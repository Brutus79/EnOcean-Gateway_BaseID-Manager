<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;
require_once __DIR__.'/C2Session.php';
require_once __DIR__.'/BaseIDPreflight.php';
use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\BaseIDPreflight;

/** Stateless authorization adapter, not a second transaction/write engine. */
final class C2WriteBridge
{
    public static function validate(array $proof,array $transport,float $now): array
    {
        $s=$proof['session']??[];$h=$proof['handoff']??[];$c=$s['context']??[];
        if(($proof['runtimeStarted']??false)!==true||($h['phase']??'')!=='ACTIVE'
            ||($proof['selectedReference']??0)!==($h['snapshot']['nativeID']??null)
            ||($h['ownArbiter']??0)!==($transport['arbiterID']??null)
            ||($proof['handoffBinding']??'')!==($c['handoffBinding']??null))throw new \RuntimeException('C2 gateway/maintenance identity changed.');
        foreach(['realConnectionActive','transportCorrelationSafe','noUnknownOutcome','exclusiveUARTOwner']as$gate)
            if(($transport[$gate]??false)!==true)throw new \RuntimeException('C2 live gate denied: '.$gate);
        if(($transport['uartDescriptorCount']??0)!==1||($transport['communicationFaultEpoch']??-1)!==($c['faultEpoch']??null)
            ||($transport['session']??'')!==($c['session']??null)||($transport['binding']??'')!==($c['transportBinding']??null))
            throw new \RuntimeException('C2 session/ownership/communication context changed.');
        $session=new C2Session($s);
        $target=ESP3Codec::normalizeWritableBaseId((string)($s['target']??''));
        if(!$session->prewriteGate($target,$c,$now)||($s['pending']??null)!==null||($s['confirmation']??'')==='')
            throw new \RuntimeException('C2 A/B and five-round prewrite proof required.');
        if(!$session->verifiedSnapshot())throw new \RuntimeException('C2 hardware identity missing.');
        $snapshot=$s['snapshot'];
        $preview=BaseIDPreflight::preview($target,$snapshot['idbase'],5);
        if($preview['noChange'])throw new \RuntimeException('Identical target: no write.');
        return ['sessionID'=>$s['id'],'handoffID'=>$h['id'],'nativeID'=>$proof['selectedReference'],
            'confirmationHash'=>hash('sha256',$s['confirmation']),'target'=>$target,'snapshot'=>$snapshot,
            'handoffBinding'=>$proof['handoffBinding']];
    }
    public static function measured(array $authority,array $reads): void
    {
        if(($reads['CO_RD_VERSION']['values']??null)!==$authority['snapshot']['version']
            ||($reads['CO_RD_IDBASE']['values']??null)!==$authority['snapshot']['idbase'])
            throw new \RuntimeException('Live EURID/Base-ID/counter differs from the C2 confirmation.');
    }
    public static function backup(array $authority,array $transport,int $now): array
    {
        $base=$authority['snapshot']['idbase']['baseIdRawHex'];$eurid=$authority['snapshot']['version']['eurid'];
        // Transaction-local identity witness; never changes SavedBaseID/inventory.
        return ['baseID'=>$base,'observedEURID'=>$eurid,'parentInstanceID'=>(string)$transport['arbiterID'],
            'binding'=>$transport['binding'],'identityConfirmation'=>['confirmedAt'=>gmdate('c',$now),
                'session'=>$transport['session'],'backupBaseID'=>$base,'eurid'=>$eurid]];
    }
}
