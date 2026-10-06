<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Safety;

require_once __DIR__ . '/TransactionStateModel.php';

use EnOceanGatewayManager\Protocol\ESP3Codec;

/** Transport-independent transaction policy. The product adapter owns the UART. */
final class TransactionalWrite
{
    private array $s;
    public function __construct(array $snapshot = []) { $this->s = $snapshot ?: ['state' => 'IDLE']; }
    public function snapshot(): array { return $this->s; }
    public function classification(): array { return TransactionStateModel::classify($this->s['state'] ?? '', (bool) ($this->s['permanentFailure'] ?? false)); }
    public function active(): bool { return $this->classification()['active']; }
    public function view(): array { $v = $this->s; unset($v['token']); $v['hardwareWriteEnabled'] = false; $v['stateClassification'] = $this->classification(); return $v; }
    private function expect(string ...$states): void
    {
        if (!in_array($this->s['state'], $states, true)) { throw new \RuntimeException('Invalid transaction state: ' . $this->s['state']); }
    }
    private function transition(string $state, WriteJournal $journal): void
    {
        $next = $this->s; $next['state'] = $state;
        $record = $next; unset($record['token']); $record['timestamp'] = gmdate('c');
        $journal->append($record); // No effect until the WAL durably acknowledges.
        $this->s = $next;
    }
    public function begin(int $owner, string $target, array $context, array $backup, int $now, WriteJournal $j): void
    {
        $target = ESP3Codec::normalizeWritableBaseId($target);
        if ($this->active()) { throw new \RuntimeException('Exclusive write lease busy.'); }
        foreach (['realConnectionActive', 'correlationSafeAndIdle', 'noUnknownOutcome', 'exclusiveUARTOwner'] as $gate) {
            if (($context[$gate] ?? false) !== true) { throw new \RuntimeException('Lease denied: ' . $gate); }
        }
        if (($backup['observedEURID'] ?? '') === '' || ($backup['parentInstanceID'] ?? '') !== (string) $context['arbiterID']
            || ($backup['binding'] ?? '') !== $context['binding'] || ($backup['baseID'] ?? '') === '') {
            throw new \RuntimeException('Confirmed parent-bound backup required.');
        }
        $confirmation = $backup['identityConfirmation'] ?? [];
        $at = strtotime($confirmation['confirmedAt'] ?? '') ?: 0;
        if ($now - $at > 60 || $at > $now || ($confirmation['session'] ?? '') !== $context['session']
            || ($confirmation['backupBaseID'] ?? '') !== $backup['baseID'] || ($confirmation['eurid'] ?? '') !== $backup['observedEURID']) {
            throw new \RuntimeException('Backup identity confirmation expired.');
        }
        $this->s = ['state' => 'IDLE', 'transactionID' => bin2hex(random_bytes(24)), 'owner' => $owner,
            'parentID' => $context['arbiterID'], 'binding' => $context['binding'], 'session' => $context['session'],
            'target' => $target, 'backupEURID' => $backup['observedEURID'], 'backupStatus' => 'confirmed',
            'ownerRevision' => $context['ownerRevision'] ?? '',
            'expiresAt' => $now + 60, 'authorized' => false, 'token' => '', 'sendAttempts' => 0, 'pending' => '', 'readNext' => 'CO_RD_VERSION'];
        $this->transition('ACQUIRE_LEASE', $j); $this->transition('PREFLIGHT', $j);
    }
    public function authorize(int $owner, string $id, WriteJournal $j): void
    {
        $this->expect('READY_FOR_CONFIRMATION'); $this->owner($owner, $id);
        $this->s['authorized'] = true; $this->transition('READY_FOR_CONFIRMATION', $j);
    }
    private function owner(int $owner, string $id): void
    {
        if ($owner !== ($this->s['owner'] ?? 0) || !hash_equals($this->s['transactionID'] ?? '', $id)) { throw new \RuntimeException('Wrong transaction owner/ID.'); }
    }
    public function confirmationToken(int $owner, string $id): string
    {
        $this->expect('READY_FOR_CONFIRMATION'); $this->owner($owner, $id);
        return $this->s['token'];
    }
    private function unchanged(array $c, int $now): bool
    {
        return ($c['realConnectionActive'] ?? false) && ($c['noUnknownOutcome'] ?? false) && ($c['exclusiveUARTOwner'] ?? false)
            && ($c['session'] ?? '') === ($this->s['session'] ?? null) && ($c['binding'] ?? '') === ($this->s['binding'] ?? null)
            && ($c['arbiterID'] ?? 0) === ($this->s['parentID'] ?? null)
            && ($c['ownerRevision'] ?? '') === ($this->s['ownerRevision'] ?? '') && $now <= ($this->s['expiresAt'] ?? 0);
    }
    public function confirm(int $owner, string $id, string $token, string $target, array $c, int $now, WriteJournal $j): void
    {
        $this->expect('READY_FOR_CONFIRMATION'); $this->owner($owner, $id);
        if (!$this->s['authorized'] || !$this->unchanged($c, $now) || !($c['correlationSafeAndIdle'] ?? false)
            || ESP3Codec::normalizeWritableBaseId($target) !== $this->s['target'] || $token === '' || !hash_equals($this->s['token'], $token)) {
            $this->cancel('Confirmation invalid or expired.', $j); throw new \RuntimeException('Confirmation rejected.');
        }
        $this->s['confirmationHash'] = hash('sha256', $token); $this->s['token'] = '';
        $this->s['readNext'] = 'CO_RD_VERSION'; $this->transition('CONFIRMED', $j);
    }
    public function nextRead(array $c, int $now, WriteJournal $j): ?array
    {
        if (!$this->active() || ($this->s['readNext'] ?? '') === '' || ($this->s['pending'] ?? '') !== '') { return null; }
        if (!$this->unchanged($c, $now)) { $this->cancel('Lease context changed/expired.', $j); return null; }
        if (!($c['correlationSafeAndIdle'] ?? false)) { return null; }
        $op = $this->s['readNext']; $token = 'b6-' . bin2hex(random_bytes(16));
        $this->s['pending'] = $token; $this->s['pendingOperation'] = $op; $this->s['readNext'] = '';
        return ['operation' => $op, 'token' => $token];
    }
    public function receive(string $token, string $operation, string $outcome, string $hex, array $c, int $now, WriteJournal $j): void
    {
        if ($token !== ($this->s['pending'] ?? '') || $operation !== ($this->s['pendingOperation'] ?? '')) { return; }
        $this->s['pending'] = '';
        if (!$this->unchanged($c, $now)) { $this->unknown('Response from changed/expired context.', $j); return; }
        if ($outcome !== 'RESPONSE') { $this->unknown('Read outcome: ' . $outcome, $j); return; }
        try { $value = ESP3Codec::parseReadResponse($operation, ESP3Codec::fromHex($hex)); }
        catch (\Throwable) { $this->unknown('Invalid read response.', $j); return; }
        if (($value['returnName'] ?? '') !== 'RET_OK') { $this->unknown('Required read not supported/successful.', $j); return; }
        $this->s['reads'][$operation] = ['at' => $now, 'session' => $c['session'] ?? '', 'binding' => $c['binding'] ?? '', 'values' => $value];
        if ($operation === 'CO_RD_VERSION') { $this->s['readNext'] = 'CO_RD_IDBASE'; return; }
        $v = $this->s['reads']['CO_RD_VERSION'] ?? [];
        if ($now < ($v['at'] ?? 0) || $now - ($v['at'] ?? 0) > 60 || ($v['session'] ?? '') !== ($c['session'] ?? null) || ($v['binding'] ?? '') !== ($c['binding'] ?? null)) {
            $this->unknown('Read identity/freshness changed.', $j); return;
        }
        $eurid = $v['values']['eurid'] ?? '';
        if ($eurid !== $this->s['backupEURID']) { $this->unknown('EURID mismatch.', $j); return; }
        if ($this->s['state'] === 'ADMIN_RECOVERY_READS') {
            $expected = $this->s['recoveryExpected'];
            if ($value['baseIdRawHex'] !== $expected['baseID'] || ($value['remainingWriteCyclesRawHex'] ?? '') !== $expected['counterRaw']) {
                $this->unknown('Administrative recovery hardware state differs from durable evidence.', $j); return;
            }
            if (!isset($this->s['recoveryFirstRead'])) {
                $this->s['recoveryFirstRead'] = $this->s['reads']; $this->s['readNext'] = 'CO_RD_VERSION';
                $this->transition('ADMIN_RECOVERY_READS', $j); return;
            }
            $this->s['recoveryResult'] = ['status' => 'READ_ONLY_RESOLVED', 'originalResult' => 'FAIL / UNKNOWN_OUTCOME',
                'originalTransactionID' => $this->s['originalIntent']['transactionID'],
                'requested' => $this->s['originalIntent']['target'], 'actuallyObserved' => $value['baseIdRawHex'],
                'eurid' => $eurid, 'counterBefore' => $this->s['originalIntent']['preview']['remaining'],
                'counterAfter' => $value['remainingWriteCyclesMode'] === 'unlimited' ? 'UNLIMITED' : $value['remainingWriteCycles'], 'counterRawAfter' => $value['remainingWriteCyclesRawHex'],
                'resolvedAt' => gmdate('c', $now), 'hardwareStateKnown' => true];
            $this->s['permanentFailure'] = false;
            $this->s['reason'] = 'Read-only recovery resolved hardware state; original intent remains FAIL.';
            $this->transition($value['baseIdRawHex'] !== $this->s['originalIntent']['target']
                ? 'RECOVERED_WITH_DIFFERENT_APPLIED_VALUE' : 'READ_ONLY_RESOLVED', $j); return;
        }
        if ($this->s['state'] === 'POST_VERIFY') {
            $counter = $value['remainingWriteCyclesRawHex'] ?? null;
            $expected = $this->s['preview']['expectedRemaining'];
            $matches = $value['baseIdRawHex'] === $this->s['target'] && $counter !== null
                && ($expected === 'UNLIMITED' ? $counter === 'FF' : hexdec($counter) === $expected);
            if ($matches) { $this->transition('VERIFIED', $j); }
            elseif (($this->s['recovery'] ?? false) && $value['baseIdRawHex'] === $this->s['preview']['currentBaseID']
                && $counter === $this->s['counterRaw']) { $this->transition('RECOVERY_NOT_APPLIED', $j); }
            else { $this->unknown('Contradictory postverification; permanent fail-closed.', $j); }
            return;
        }
        if (!$this->unchanged($c, $now)) { $this->cancel('Lease identity changed.', $j); return; }
        try { $preview = BaseIDPreflight::preview($this->s['target'], $value, 5); }
        catch (\Throwable $e) { $this->cancel($e->getMessage(), $j); return; }
        if ($preview['noChange']) {
            $this->s['preview'] = $preview; $this->s['eurid'] = $eurid; $this->s['counterRaw'] = $value['remainingWriteCyclesRawHex'];
            $this->transition('NO_OP', $j); return;
        }
        if ($this->s['state'] === 'CONFIRMED') {
            if ($preview !== $this->s['preview'] || $eurid !== $this->s['eurid'] || ($value['remainingWriteCyclesRawHex'] ?? '') !== $this->s['counterRaw']) {
                $this->cancel('Measured values changed after confirmation.', $j); return;
            }
            $this->s['frameHex'] = ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest($this->s['target']));
            $this->s['journalStatus'] = 'PREPARED_NOT_SENT'; $this->transition('PRE_WRITE_JOURNALED', $j); return;
        }
        $this->expect('PREFLIGHT');
        $this->s['eurid'] = $eurid; $this->s['preview'] = $preview; $this->s['counterRaw'] = $value['remainingWriteCyclesRawHex'];
        $this->s['token'] = bin2hex(random_bytes(32)); $this->s['expiresAt'] = $now + 60;
        $this->transition('READY_FOR_CONFIRMATION', $j);
    }
    /** Called only at the adapter's single write send point, with freshly inspected context. */
    public function prepareSend(array $c, int $now, bool $packageBarrier, WriteJournal $j): ?string
    {
        $this->expect('PRE_WRITE_JOURNALED');
        if ($this->s['readOnlyRecovery'] ?? false) { throw new \RuntimeException('Recovery can never authorize a write.'); }
        $target = ESP3Codec::normalizeWritableBaseId($this->s['target']);
        if (ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest($target)) !== ($this->s['frameHex'] ?? '')) {
            throw new \RuntimeException('Invalid final write intent/frame.');
        }
        if (!$this->unchanged($c, $now) || !($c['correlationSafeAndIdle'] ?? false) || !$this->s['authorized']
            || ($this->s['confirmationHash'] ?? '') === '' || $this->s['sendAttempts'] !== 0
            || ($this->s['journalStatus'] ?? '') !== 'PREPARED_NOT_SENT') { $this->cancel('Final send gate changed.', $j); return null; }
        foreach ($this->s['reads'] as $r) { if ($now < $r['at'] || $now - $r['at'] > 60 || $r['session'] !== $c['session'] || $r['binding'] !== $c['binding']) { $this->cancel('Stale final measurement / clock changed.', $j); return null; } }
        if ($packageBarrier) { $this->s['packageBarrier'] = 'B6_HARDWARE_WRITE_BLOCKED'; return null; }
        // Persist uncertainty BEFORE the sole send effect; crash means MAY_HAVE_SENT.
        $this->s['sendAttempts'] = 1; $this->s['journalStatus'] = 'MAY_HAVE_SENT';
        $this->transition('WRITE_SENT', $j); $this->transition('WAITING_FOR_RESPONSE', $j);
        $this->s['writeDeadline'] = $now + 5;
        return $this->s['frameHex'];
    }
    public function writeResponse(string $hex, int $now, WriteJournal $j): void
    {
        $this->expect('WAITING_FOR_RESPONSE');
        try { $r = ESP3Codec::parseWriteIdBaseResponse(ESP3Codec::fromHex($hex)); }
        catch (\Throwable) { $this->unknown('Invalid write response.', $j); return; }
        $this->s['writeResponse'] = $r; // Persist the actual validated response, also RET_OK.
        if (!$r['accepted']) {
            $message = match ($r['returnName']) {
                'RET_NOT_SUPPORTED' => 'Gateway unterstützt Base-ID-Schreiben nicht.',
                'BASEID_OUT_OF_RANGE' => 'Gateway lehnt die Base-ID als außerhalb seines Wertebereichs ab.',
                'BASEID_MAX_REACHED' => 'Keine Base-ID-Schreibzyklen mehr; Grenze nicht zurücksetzbar.',
                default => 'Gateway meldet einen Schreibfehler.',
            };
            $this->unknown($r['returnName'] . ': ' . $message . ' Keine Wiederholung; nur Recovery.', $j); return;
        }
        $this->transition('WRITE_RESPONSE_RECEIVED', $j); $this->s['recovery'] = false;
        $this->transition('FORCE_RECONNECT', $j); $this->s['sawDisconnect'] = false;
    }
    public function observe(array $c, int $now, WriteJournal $j): void
    {
        if (!$this->active()) { return; }
        if (in_array($this->s['state'], ['FORCE_RECONNECT', 'ADMIN_RECOVERY_RECONNECT'], true)) {
            if (!($c['realConnectionActive'] ?? false)) { $this->s['sawDisconnect'] = true; return; }
            if (!($this->s['sawDisconnect'] ?? false) || ($c['session'] ?? '') === $this->s['session']) { return; }
            if (($c['binding'] ?? '') !== $this->s['binding'] || ($c['arbiterID'] ?? 0) !== $this->s['parentID']
                || ($c['ownerRevision'] ?? '') !== ($this->s['ownerRevision'] ?? '') || !($c['exclusiveUARTOwner'] ?? false)
                || !($c['noUnknownOutcome'] ?? false) || !($c['correlationSafeAndIdle'] ?? false)) { $this->unknown('Reconnect identity/ownership/correlation invalid.', $j); return; }
            $this->s['session'] = $c['session']; $this->s['expiresAt'] = $now + 60;
            $this->s['readNext'] = 'CO_RD_VERSION'; $this->s['reads'] = [];
            $this->transition(($this->s['readOnlyRecovery'] ?? false) ? 'ADMIN_RECOVERY_READS' : 'POST_VERIFY', $j); return;
        }
        if ($this->s['state'] === 'UNKNOWN_OUTCOME') { return; }
        if ($this->s['state'] === 'WAITING_FOR_RESPONSE') {
            if (!$this->unchanged($c, $now) || $now >= ($this->s['writeDeadline'] ?? 0)) { $this->unknown('Write timeout/disconnect/context changed.', $j); }
            return;
        }
        if (!$this->unchanged($c, $now)) { $this->cancel('Lease expired or hardware context changed.', $j); }
    }
    public function unknown(string $reason, WriteJournal $j): void
    {
        if ($this->s['readOnlyRecovery'] ?? false) { $this->s['permanentFailure'] = true; }
        if (str_contains($reason, 'Contradictory') || (str_contains($reason, 'EURID mismatch') && ($this->s['sendAttempts'] ?? 0) > 0)) { $this->s['permanentFailure'] = true; }
        $this->s['reason'] = $reason; $this->s['token'] = ''; $this->s['authorized'] = false; $this->s['readNext'] = ''; $this->s['pending'] = '';
        $this->transition('UNKNOWN_OUTCOME', $j);
    }
    public function cancel(string $reason, WriteJournal $j): void
    {
        if (!$this->classification()['known']) { $this->s['permanentFailure'] = true; $this->unknown('Unknown transaction state: ' . $reason, $j); return; }
        if (($this->s['readOnlyRecovery'] ?? false) || ($this->s['sendAttempts'] ?? 0) > 0 || in_array($this->s['state'], ['UNKNOWN_OUTCOME', 'FORCE_RECONNECT', 'POST_VERIFY'], true)) { $this->unknown($reason, $j); return; }
        $this->s['reason'] = $reason; $this->s['token'] = ''; $this->s['authorized'] = false; $this->s['readNext'] = ''; $this->s['pending'] = '';
        $this->transition('CANCELLED', $j);
    }
    public function recover(int $owner, string $id, WriteJournal $j): void
    {
        $this->expect('UNKNOWN_OUTCOME'); $this->owner($owner, $id);
        if ($this->s['permanentFailure'] ?? false) { throw new \RuntimeException('Contradictory hardware state: permanent write lock; manual review required.'); }
        if (!isset($this->s['preview'])) { throw new \RuntimeException('No write intent to reconcile; manual diagnostic review required.'); }
        $this->s['recovery'] = true; $this->s['sawDisconnect'] = false;
        $this->transition('FORCE_RECONNECT', $j);
    }
    /** Administrative reconciliation: a NEW read-only operation, never a retry. */
    public function administrativeRecover(int $owner, string $id, array $c, int $now, WriteJournal $j): void
    {
        $this->expect('UNKNOWN_OUTCOME'); $this->owner($owner, $id);
        $records = $j->records(); // Validate full durable history before any new operation.
        if ($records === []) { throw new \RuntimeException('No durable recovery intent.'); }
        $last = $records[array_key_last($records)];
        if (($last['state'] ?? '') !== 'UNKNOWN_OUTCOME' || ($last['transactionID'] ?? '') !== $id) {
            throw new \RuntimeException('Recovery journal does not match current failed operation.');
        }
        $original = $last['originalIntent'] ?? $last;
        unset($original['token']);
        $frame = ESP3Codec::parseFrame(ESP3Codec::fromHex($original['frameHex'] ?? ''));
        if ($frame['packetType'] !== 5 || $frame['optionalLength'] !== 0 || $frame['data'] !== chr(7) . ESP3Codec::fromHex($original['target'] ?? '')
            || ($original['sendAttempts'] ?? 0) !== 1 || !isset($original['preview'])) { throw new \RuntimeException('Original durable intent invalid.'); }
        $reads = $original['reads'] ?? []; $version = $reads['CO_RD_VERSION'] ?? []; $base = $reads['CO_RD_IDBASE'] ?? [];
        $value = $base['values'] ?? [];
        if (($version['values']['eurid'] ?? '') !== ($original['backupEURID'] ?? '')
            || ($version['session'] ?? '') !== ($base['session'] ?? null) || ($version['binding'] ?? '') !== ($base['binding'] ?? null)
            || ($value['returnName'] ?? '') !== 'RET_OK' || !isset($value['remainingWriteCyclesRawHex'])) {
            throw new \RuntimeException('No consistent durable postverification proof.');
        }
        $observed = ESP3Codec::normalizeWritableBaseId($value['baseIdRawHex'] ?? '');
        $counter = $value['remainingWriteCyclesRawHex']; $expected = $original['preview']['expectedRemaining'];
        if ($expected === 'UNLIMITED' ? $counter !== 'FF' : hexdec($counter) !== $expected) {
            throw new \RuntimeException('Unexpected durable counter; manual review required.');
        }
        // The failed INTENT is intentionally unknown; the TRANSPORT must be safe.
        foreach (['realConnectionActive', 'correlationSafeAndIdle', 'transportCorrelationSafe', 'exclusiveUARTOwner'] as $gate) {
            if (($c[$gate] ?? false) !== true) { throw new \RuntimeException('Recovery gate denied: ' . $gate); }
        }
        if (($c['arbiterID'] ?? 0) !== ($original['parentID'] ?? null) || ($c['binding'] ?? '') !== ($original['binding'] ?? null)
            || ($c['ownerRevision'] ?? '') !== ($this->s['ownerRevision'] ?? '')) { throw new \RuntimeException('Recovery owner/binding mismatch.'); }
        $newID = bin2hex(random_bytes(24));
        $this->s = ['state' => 'UNKNOWN_OUTCOME', 'transactionID' => $newID, 'recoveryOperationID' => $newID,
            'owner' => $owner, 'parentID' => $c['arbiterID'], 'binding' => $c['binding'], 'session' => $c['session'],
            'ownerRevision' => $c['ownerRevision'], 'backupEURID' => $original['backupEURID'], 'target' => $original['target'],
            'originalIntent' => $original, 'readOnlyRecovery' => true, 'recoveryExpected' => ['baseID' => $observed, 'counterRaw' => $counter],
            'expiresAt' => $now + 60, 'authorized' => false, 'token' => '', 'sendAttempts' => 0,
            'sawDisconnect' => false, 'readNext' => '', 'pending' => '', 'permanentFailure' => true];
        $this->transition('ADMIN_RECOVERY_RECONNECT', $j);
    }
    public static function restart(WriteJournal $j): self
    {
        $records = $j->records(); if ($records === []) { return new self(); }
        $last = $records[array_key_last($records)]; unset($last['sequence'], $last['hash'], $last['previousHash'], $last['timestamp']);
        if (!isset($last['state']) || !is_string($last['state'])) { $last['state'] = ''; }
        $engine = new self($last); $engine->s['token'] = ''; $engine->s['authorized'] = false;
        // Unknown future/corrupt states must not be cancelled into a free terminal state.
        if (!$engine->classification()['known']) { return $engine; }
        if ($engine->active()) {
            if (($last['readOnlyRecovery'] ?? false) || ($last['sendAttempts'] ?? 0) > 0 || in_array($last['state'], ['UNKNOWN_OUTCOME', 'FORCE_RECONNECT', 'POST_VERIFY'], true)) { $engine->unknown('Process restart: send may have occurred; never resume write.', $j); }
            else { $engine->cancel('Process restart: old lease and confirmation discarded.', $j); }
        }
        return $engine;
    }
}
