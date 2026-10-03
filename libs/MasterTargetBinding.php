<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;
require_once __DIR__.'/ESP3Codec.php';
use EnOceanGatewayManager\Protocol\ESP3Codec;

/** Product validation only. Applied target remains the existing native properties. */
final class MasterTargetBinding
{
    public static function selection(array $s,array $context,array $backup): array
    {
        $master=ESP3Codec::normalizeWritableBaseId((string)($s['gateway']['master']??''));
        if(($s['inventoryError']??true)||($s['replacement']??true)||($s['leaseActive']??true)||!($s['fresh']??false)||($s['pending']??true))throw new \RuntimeException('Gateway frisch auslesen; Inventar, Hardwarezuordnung und laufende Vorgänge prüfen.');
        foreach(['realConnectionActive','correlationSafeAndIdle','noUnknownOutcome','exclusiveUARTOwner','exclusiveChain']as$key)if(!($context[$key]??false))throw new \RuntimeException('Keine sichere eindeutige Gatewayverbindung. Ziel bleibt unverändert.');
        $h=$s['hardware'];$eurid=$h['EURID']??null;
        if(!is_string($eurid)||preg_match('/\A[0-9A-F]{8}\z/D',$eurid)!==1)throw new \RuntimeException('Gatewayidentität nicht frisch ermittelt.');
        ESP3Codec::normalizeWritableBaseId((string)($h['baseID']??''));
        if(($s['gateway']['acceptedEURID']??null)!==null&&$s['gateway']['acceptedEURID']!==$eurid)throw new \RuntimeException('Neue Hardwareidentität zuerst bewusst zuordnen.');
        // A backup is hardware evidence, NOT the desired Master/target. Its Base-ID
        // may deliberately differ. Only conflicting provenance blocks selection.
        if(($backup['baseID']??'')!==''){
            ESP3Codec::normalizeWritableBaseId($backup['baseID']);
            if(($backup['observedEURID']??null)!==$eurid||($backup['parentInstanceID']??'')!==(string)($context['arbiterID']??0)
                ||(isset($backup['binding'])&&$backup['binding']!==($context['binding']??null)))throw new \RuntimeException('Sicherungsidentität oder Anschluss widersprüchlich. Keine automatische Übernahme.');
        }
        return [['name'=>'BaseIDSource','value'=>'manual'],['name'=>'ManualBaseID','value'=>$master]];
    }
    public static function applied(array $s,array $config,array $context,array $backup): array
    {
        $selection=self::selection($s,$context,$backup);$master=$selection[1]['value'];
        $target=ESP3Codec::normalizeWritableBaseId(match($config['BaseIDSource']??''){
            'manual'=>(string)($config['ManualBaseID']??''),'saved'=>(string)($backup['baseID']??''),default=>throw new \RuntimeException('Unbekannte Zielquelle.')});
        if($target!==$master)throw new \RuntimeException('Ziel stimmt nicht mit der gespeicherten Master-ID überein. Master-ID bewusst auswählen und Änderungen übernehmen.');
        return ['currentBaseID'=>$s['hardware']['baseID'],'master'=>$master,'target'=>$target,'EURID'=>$s['hardware']['EURID'],
            'remaining'=>$s['hardware']['counter']??null,'hardwareWriteBarrier'=>true,'hardwareWriteEnabled'=>false,'transactionStarted'=>false];
    }
}
