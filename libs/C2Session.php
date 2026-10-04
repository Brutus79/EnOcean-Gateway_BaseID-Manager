<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;

use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\BaseIDPreflight;
use RuntimeException;
use Throwable;
require_once __DIR__.'/ESP3Codec.php';
require_once __DIR__.'/BaseIDPreflight.php';

/** Pure C2 safety state. No transport, no persistence and absolutely no send path.
 * RESPONSE origin cannot be proven by ESP3. Five rounds protect the documented
 * bounded substitution model; they are not a transaction-ID substitute.
 */
final class C2Session
{
    public const ROUNDS = 5;
    public const FRESHNESS_SECONDS = 60.0;
    private array $s;

    public function __construct(array $state = []) { $this->s = $state; }
    public function state(): array { return $this->s; }
    /** Initial proof stays valid while the caller freshly verifies exclusive context.
     * Its acquisition and every prewrite proof still have the 60-second bound.
     */
    public function verifiedSnapshot(): bool
    {
        return ($this->s['faults']??[])===[]&&($this->s['snapshot']??null)!==null
            &&count($this->s['initial']??[])===self::ROUNDS*2;
    }
    public function start(array $context, float $now): void
    {
        if ($this->s !== [] && !in_array($this->s['phase'], ['RETURNED', 'RETURN_WARNING'], true)) {
            throw new RuntimeException('Return the current session before starting another.');
        }
        self::validateContext($context);
        $this->s = ['phase'=>'SYNCHRONIZING', 'id'=>bin2hex(random_bytes(16)),
            'context'=>$context, 'startedAt'=>$now, 'faults'=>[], 'history'=>[],
            'initial'=>[], 'prewrite'=>[], 'pending'=>null, 'snapshot'=>null,
            'target'=>null, 'confirmation'=>null, 'confirmationAt'=>null];
    }
    public function checkContext(array $context, float $now): bool
    {
        try { self::validateContext($context); }
        catch (Throwable $e) { $this->fault('unsafe_context', $now); return false; }
        if (($this->s['context'] ?? null) !== $context) {
            $this->fault('session_context_changed', $now); return false;
        }
        return ($this->s['faults'] ?? []) === [];
    }
    private static function validateContext(array $c): void
    {
        foreach (['session', 'transportBinding', 'handoffBinding'] as $key) {
            if (!is_string($c[$key] ?? null) || $c[$key] === '') throw new RuntimeException('Missing context.');
        }
        if (($c['exclusive'] ?? false) !== true || ($c['faultEpoch'] ?? -1) < 0
            || ($c['descriptorCount'] ?? 0) !== 1 || ($c['writeLeaseActive']??true)!==false
            || ($c['noUnknownOutcome']??false)!==true) throw new RuntimeException('Ownership, lease or fault history unknown.');
    }
    public function fault(string $reason, float $now): void
    {
        if ($this->s === []) $this->s=['phase'=>'FAULT_LATCHED','faults'=>[],'pending'=>null,'confirmation'=>null,'snapshot'=>null];
        $this->s['faults'][] = ['reason'=>$reason, 'at'=>$now];
        $this->s['phase'] = 'FAULT_LATCHED';
        $this->s['pending'] = null;
        $this->s['confirmation'] = null;
    }
    /** Returns one operation at a time; caller must use the existing arbiter. */
    public function request(array $context, float $now): ?array
    {
        if (!$this->checkContext($context,$now) || $this->s['pending'] !== null) return null;
        $phase = $this->s['phase'];
        if (!in_array($phase,['SYNCHRONIZING','PREWRITE_VERIFYING'],true)) return null;
        $which = $phase === 'SYNCHRONIZING' ? 'initial' : 'prewrite';
        $index = count($this->s[$which]);
        $op = $index % 2 === 0 ? 'CO_RD_VERSION' : 'CO_RD_IDBASE';
        $req = ['operation'=>$op,'token'=>bin2hex(random_bytes(16)), 'at'=>$now];
        $this->s['pending'] = $req;
        return $req;
    }
    public function response(string $token, string $operation, string $frame, array $context, float $now): bool
    {
        if (!$this->checkContext($context,$now)) return false;
        $p = $this->s['pending'];
        if ($p === null || $p['token'] !== $token || $p['operation'] !== $operation) {
            $this->fault('unexpected_response',$now); return false;
        }
        if ($now < $p['at'] || $now-$p['at'] > self::FRESHNESS_SECONDS) {
            $this->fault('response_timeout_or_clock_change',$now); return false;
        }
        try {
            $value = ESP3Codec::parseReadResponse($operation,$frame);
            if (($value['returnCode'] ?? -1) !== 0) throw new RuntimeException('Required command unavailable.');
        } catch (Throwable $e) { $this->fault('invalid_or_unsupported_response',$now); return false; }
        $row = ['operation'=>$operation,'token'=>$token,'readAt'=>$now,'value'=>$value];
        $this->s['history'][] = $row;
        $which = $this->s['phase'] === 'SYNCHRONIZING' ? 'initial' : 'prewrite';
        $this->s[$which][] = $row;
        $this->s['pending'] = null;
        // Compare the entire parsed response, including versions and optional data.
        foreach ($this->s['history'] as $old) {
            if ($old['operation'] === $operation && $old['value'] !== $value) {
                $this->fault('gateway_state_changed',$now); return false;
            }
        }
        if (count($this->s[$which]) === self::ROUNDS*2) {
            if (!$this->fresh($this->s[$which],$now)) { $this->fault('reads_expired',$now); return false; }
            if ($which === 'initial') {
                $this->s['snapshot'] = ['version'=>$this->s['initial'][0]['value'],
                    'idbase'=>$this->s['initial'][1]['value']];
                $this->s['phase'] = 'MAINTENANCE_READY';
            } else {
                // Deliberately NEVER WRITE_READY for a physical session. The real
                // barrier is also enforced independently at the arbiter send site.
                $this->s['phase'] = 'WRITE_BLOCKED';
            }
        }
        return true;
    }
    /** Local selection validates before any further hardware communication. */
    public function review(string $target, array $context, float $now): array
    {
        $target = ESP3Codec::normalizeWritableBaseId($target);
        if (!$this->checkContext($context,$now) || $this->s['phase'] !== 'MAINTENANCE_READY') {
            throw new RuntimeException('Maintenance not ready.');
        }
        if (!$this->verifiedSnapshot()) {
            throw new RuntimeException('Verified synchronization required.');
        }
        $b = $this->s['snapshot']['idbase'];
        if ($b['baseIdRawHex'] === $target) throw new RuntimeException('Target already current.');
        if ($b['remainingWriteCyclesMode'] === 'unknown' || $b['remainingWriteCycles'] === 0) {
            throw new RuntimeException('No reliable remaining write cycles.');
        }
        // Same unchanged reserve policy as B6, not a model-specific lifetime
        // limit. Even a hypothetical C2 proof must leave at least five cycles.
        BaseIDPreflight::preview($target,$b,5);
        $this->s['target'] = $target;
        $this->s['confirmation'] = bin2hex(random_bytes(16));
        $this->s['confirmationAt'] = $now;
        $this->s['phase'] = 'REVIEW_A';
        $remaining = $b['remainingWriteCyclesMode'] === 'unlimited' ? 255 : $b['remainingWriteCycles'];
        return ['current'=>$b['baseIdRawHex'],'target'=>$target,'remaining'=>$remaining,
            'expectedRemaining'=>$remaining === 255 ? 255 : $remaining-1,
            'token'=>$this->s['confirmation'], 'hardwareWriteBlocked'=>true];
    }
    public function confirmA(string $token, float $now): void { $this->confirm('REVIEW_A','REVIEW_B',$token,$now); }
    public function confirmB(string $token, string $target, array $context, float $now): void
    {
        if (!$this->checkContext($context,$now) || $target !== ($this->s['target'] ?? null)) {
            $this->fault('confirmation_target_changed',$now); throw new RuntimeException('Target/context changed.');
        }
        $this->confirm('REVIEW_B','PREWRITE_VERIFYING',$token,$now);
        $this->s['prewrite'] = [];
    }
    private function confirm(string $from,string $to,string $token,float $now): void
    {
        if (($this->s['phase'] ?? null) !== $from || $token !== $this->s['confirmation']
            || $now < $this->s['confirmationAt'] || $now-$this->s['confirmationAt'] > self::FRESHNESS_SECONDS) {
            $this->fault('confirmation_invalid_or_expired',$now); throw new RuntimeException('New confirmation required.');
        }
        $this->s['phase']=$to;
    }
    public function prewriteGate(string $target,array $context,float $now): bool
    {
        if (!$this->checkContext($context,$now)) return false;
        if (($this->s['phase'] ?? null) !== 'WRITE_BLOCKED' || $this->s['target'] !== $target
            || !$this->fresh($this->s['prewrite'],$now) || $now < $this->s['confirmationAt']
            || $now-$this->s['confirmationAt'] > self::FRESHNESS_SECONDS) {
            $this->fault('prewrite_gate_failed',$now); return false;
        }
        return true; // Valid proof, NOT authorization to transmit.
    }
    private function fresh(array $rows,float $now): bool
    {
        if (count($rows) !== self::ROUNDS*2) return false;
        foreach($rows as$r)if($now < $r['readAt'] || $now-$r['readAt'] > self::FRESHNESS_SECONDS)return false;
        return true;
    }
    public function returning(): void
    {
        if ($this->s === []) return;
        $this->s['phase']='NATIVE_REFRESH_PENDING'; $this->s['pending']=null; $this->s['confirmation']=null;
    }
    public function returned(bool $refreshProven): void
    {
        if (($this->s['phase'] ?? '') !== 'NATIVE_REFRESH_PENDING') throw new RuntimeException('Not returning.');
        $this->s['phase']=$refreshProven?'RETURNED':'RETURN_WARNING';
    }
}
