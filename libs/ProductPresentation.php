<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;

final class ProductPresentation
{
    public static function outcome(string $state): string
    {
        return match($state){
            'UNKNOWN_OUTCOME'=>'Das Ergebnis der letzten Änderung kann nicht sicher bestimmt werden. Es wird keine weitere Änderung zugelassen.',
            'READY_FOR_CONFIRMATION'=>'Gateway geprüft. Bitte die angezeigte Änderung bewusst bestätigen.',
            'PRE_WRITE_JOURNALED'=>'Änderung vorbereitet. Hardware-Schreibfunktion für diesen Test noch gesperrt.',
            'VERIFIED'=>'Letzte Änderung nach erneutem Verbinden erfolgreich geprüft.',
            'FORCE_RECONNECT'=>'Gateway erneut verbinden und Zustand prüfen. Die Änderung ist noch nicht bestätigt.',
            'RECOVERED_WITH_DIFFERENT_APPLIED_VALUE','READ_ONLY_RESOLVED'=>'Letzte Wiederherstellung abgeschlossen. Hardwarezustand sicher bekannt.',
            'CANCELLED'=>'Vorbereitung beendet. Es wurde keine weitere Hardwareänderung ausgelöst.',
            default=>'Gateway prüfen / aktualisieren, bevor eine Änderung vorbereitet wird.',
        };
    }
    public static function review(array $view): array
    {
        return ['EURID'=>$view['eurid']??null,'baseID'=>$view['preview']['currentBaseID']??null,'target'=>$view['target']??null,
            'counter'=>$view['preview']['remaining']??null,'expectedCounter'=>$view['preview']['expectedRemaining']??null,
            'binding'=>$view['binding']??null,'session'=>$view['session']??null];
    }
    public static function sameSituation(array $a,array $b): bool
    {
        foreach(['EURID','baseID','target','counter','expectedCounter','binding']as$k){if(!array_key_exists($k,$a)||($a[$k]??null)!==($b[$k]??null))return false;}return true;
    }
    private static function counter(mixed $value): string
    {
        return $value==='UNLIMITED'?'Unbegrenzt':($value===null?'Nicht verfügbar':(string)$value);
    }
    private static function simpleMessage(string $message): string
    {
        if(preg_match('/B[5-8]|Transferziel|angewandte[ns]? Ziel|nativ angewandt|Write-Intent|Write-Transaktion|Arbiter|Sicherheitsziel|Zielkonfiguration|Gatewaykette/i',$message)){
            return 'Die Gatewayänderung bleibt gesperrt. Bitte die Konfiguration und die erweiterte Diagnose prüfen.';
        }
        return $message;
    }
    private static function diagnosticActions(array $items): array
    {
        $out=[];
        foreach($items as$item){
            if(!is_array($item))continue;
            if(($item['type']??'')==='TestCenter')continue;
            if(($item['type']??'')==='Button'){
                $action=$item['onClick']??'';
                if($action===''||preg_match('/EGMM_(BeginWriteTransaction|AuthorizeWriteTransaction|ConfirmWriteTransaction|BeginWritePreparation|PrepareSelectedBaseIDPreview)\(/',$action))continue;
            }
            if(isset($item['items']))$item['items']=self::diagnosticActions($item['items']);
            $out[]=$item;
        }
        return $out;
    }
    public static function form(array $s,array $technical): array
    {
        $label=static fn(string $text):array=>['type'=>'Label','caption'=>$text];
        // Cached display values never act as safety evidence.
        $h=$s['displayHardware']??$s['hardware'];$master=$s['gateway']['master']??null;
        $known=($h['baseID']??null)!==null;$fresh=$s['fresh']??false;$historical=$s['displayHistorical']??false;
        $match=$known&&$master!==null&&$master===$h['baseID'];$flow=$s['flow']??[];$phase=$flow['phase']??'';
        $busy=($s['leaseActive']??true)||($s['pending']??false);
        $blocked=($s['targetOnlyBuild']??false)||($s['inventoryError']??false)||($s['replacement']??false)
            ||($s['transactionState']??'')==='UNKNOWN_OUTCOME';
        $review=(!$fresh&&($s['targetOnlyBuild']??false))?[]:($flow['review']??[]);
        $history=[];$choices=[];
        foreach($s['history']??[]as$r){
            $history[]=$label($r['baseID'].' · '.($r['master']?'Master Base-ID':($r['formerMaster']?'ehemalige Master Base-ID':'Historie'))
                .' · '.($r['observed']?'auf Hardware beobachtet':'nicht als Hardwarewert belegt')
                .($r['written']?' · erfolgreich geschrieben':'').' · '.$r['firstSeen'].' – '.$r['lastSeen']);
            if($r['baseID']!==$master)$choices[]=['caption'=>$r['baseID'],'value'=>$r['baseID']];
        }
        $setMaster=[$label('Diese Einstellung wird nur lokal gespeichert. Das Gateway wird nicht verändert.'),
            ['type'=>'Button','caption'=>'Aktuelle Gateway Base-ID als Master übernehmen','enabled'=>$known&&$fresh&&!$busy,
                'confirm'=>'Aktuelle Base-ID nur lokal als Master speichern? Das Gateway bleibt unverändert.',
                'onClick'=>'EGMM_SetMasterBaseID($id, "", "hardware", true);'],
            ['type'=>'ValidationTextBox','name'=>'MasterEntry','caption'=>'Master Base-ID manuell eingeben'],
            ['type'=>'Button','caption'=>'Master Base-ID lokal speichern','enabled'=>!$busy,
                'confirm'=>'Diese Base-ID nur lokal als Master speichern? Das Gateway bleibt unverändert.',
                'onClick'=>'EGMM_SetMasterBaseID($id, $MasterEntry, "manual", true);']];
        if($choices!==[])$setMaster[]=['type'=>'ExpansionPanel','caption'=>'Historische Base-ID verwenden','expanded'=>false,'items'=>[
            ['type'=>'Select','name'=>'HistoryMasterChoice','caption'=>'Historische Base-ID','options'=>$choices],
            ['type'=>'Button','caption'=>'Historische Base-ID als Master übernehmen','enabled'=>!$busy,
                'confirm'=>'Historische Base-ID nur lokal als Master speichern?',
                'onClick'=>'EGMM_SetMasterBaseID($id, $HistoryMasterChoice, "history", true);']]];
        if($master===null)$setMaster[]=['type'=>'Button','caption'=>'Später festlegen','onClick'=>'EGMM_DeferMaster($id);'];
        $config=$s['targetConfiguration']??['BaseIDSource'=>'saved','ManualBaseID'=>''];
        $current=[['name'=>'BaseIDSource','value'=>$config['BaseIDSource']],['name'=>'ManualBaseID','value'=>$config['ManualBaseID']]];
        $options=[['caption'=>'Bisherige Konfiguration unverändert lassen','value'=>$current]];
        if(($s['masterTargetSelection']??null)!==null&&$s['masterTargetSelection']!==$current){
            $options[]=['caption'=>'Master Base-ID '.$master.' verwenden','value'=>$s['masterTargetSelection']];
        }
        $advanced=self::diagnosticActions($technical['actions']??[]);
        $transportElements=array_values(array_filter($technical['elements']??[],static fn(array $item):bool=>!in_array($item['name']??'',['EnableReadActions','BaseIDSource','ManualBaseID'],true)));
        $status=($s['inventoryError']??false)||($s['transactionState']??'')==='UNKNOWN_OUTCOME'?'Prüfung erforderlich':(($s['connected']??$fresh)?'✓ Verbunden':'Nicht verbunden');
        $comparison=$master===null?'Master Base-ID noch nicht festgelegt.':(!$known?'Gateway noch nicht gelesen.':
            ($match?($fresh?'✓ Gateway verwendet die gewünschte Master Base-ID.':'Zuletzt gelesen: Gateway und Master Base-ID stimmen überein.'):
                ($fresh?'⚠ Die Base-ID des Gateways unterscheidet sich von der Master Base-ID.':'Zuletzt gelesen: Gateway und Master Base-ID unterscheiden sich.')));
        $actions=[$label('EnOcean Gateway · '.$status),$label('Verbindung: '.($s['connectionText']??'Nicht verbunden')),
            $label('Gateway: '.(($h['description']??null)!==null?'ESP3 · '.$h['description'].' · Typ nicht eindeutig bestimmbar':'Noch nicht erkannt')),
            $label('Firmware: '.($h['firmware']??'Nicht ermittelt')),
            $label('Aktuelle Base-ID: '.($h['baseID']??'Nicht ermittelt')),
            $label('Master Base-ID: '.($master??'Noch nicht festgelegt')),
            $label('Verbleibende Base-ID-Änderungen: '.self::counter($h['counter']??null)),
            $label($comparison),
            $label(($historical?'Anzeige der letzten erfolgreichen Abfrage; kein frischer Sicherheitsnachweis. ':'').self::simpleMessage($s['message']??'')),
            ['type'=>'Button','caption'=>'Gateway prüfen / aktualisieren','enabled'=>!$busy,'onClick'=>'EGMM_RefreshProductGateway($id);'],
            ['type'=>'ExpansionPanel','caption'=>$master===null?'Master Base-ID festlegen':'Master Base-ID ändern','expanded'=>$master===null,'items'=>$setMaster],
            ['type'=>'Button','caption'=>'Base-ID des Gateways auf '.($master??'Master Base-ID').' ändern',
                'visible'=>$master!==null&&!($match&&$fresh),
                'enabled'=>!$busy&&!($s['inventoryError']??false)&&!($s['replacement']??false)&&($s['transactionState']??'')!=='UNKNOWN_OUTCOME',
                'onClick'=>'EGMM_PrepareMasterChange($id);'],
            ['type'=>'ExpansionPanel','caption'=>'Gatewayänderung prüfen','visible'=>$flow!==[]&&$phase!=='NO_CHANGE','expanded'=>true,'items'=>[
                $label($phase==='NEEDS_CONFIGURATION'?'Gewünschte Base-ID oben auswählen und Änderungen übernehmen. Anschließend erneut prüfen.':self::simpleMessage($s['message']??'')),
                $label('Aktuelle Base-ID: '.($review['baseID']??$h['baseID']??'Wird geprüft')),
                $label('Neue Base-ID: '.($review['target']??$master??'Nicht festgelegt')),
                $label('Verbleibende Änderungen: '.self::counter($review['counter']??null).' → '.self::counter($review['expectedCounter']??null)),
                $label('Bei begrenztem Zähler verbraucht eine erfolgreiche Änderung einen Schreibzyklus. Bei unklarem Ausgang gibt es keine automatische Wiederholung.'),
                ['type'=>'Button','caption'=>'Base-ID jetzt ändern','enabled'=>$phase==='REVIEW'&&$fresh&&!$blocked,
                    'confirm'=>'Genau die angezeigte Änderung bestätigen? Ein begrenzter Schreibzyklus kann verbraucht werden.',
                    'onClick'=>'EGMM_ConfirmMasterTransfer($id);'],
                ['type'=>'Button','caption'=>'Abbrechen','onClick'=>'EGMM_CancelProductWorkflow($id);'],
                $label(($s['targetOnlyBuild']??false)?'Hardware-Schreibfunktion bleibt in diesem Build gesperrt.':'Nur nach gültiger Prüfung und bewusster Bestätigung.')]],
            ['type'=>'ExpansionPanel','caption'=>'Neues Gateway erkannt','visible'=>$s['replacement']??false,'expanded'=>true,'items'=>[
                $label('Master und bisherige Historie bleiben erhalten. Hardwareidentität vor einer Änderung bewusst zuordnen.'),
                ['type'=>'Button','caption'=>'Neues Gateway bewusst zuordnen','confirm'=>'Neue Hardwareidentität zuordnen? Master bleibt erhalten; keine Hardwareänderung.',
                    'onClick'=>'EGMM_AcceptReplacement($id);']]],
            ['type'=>'ExpansionPanel','caption'=>'Erweiterte Diagnose','expanded'=>false,'items'=>[
                $label('EURID: '.($h['EURID']??'Nicht ermittelt')),
                $label('Letzte erfolgreiche Abfrage: '.($h['readAt']??'Noch keine')),
                $label('Modell / Funkregion: nicht zuverlässig automatisch ermittelbar.'),
                $label($s['message']??''),$label($s['targetMessage']??''),
                $label($fresh?'Hardwaredaten frisch geprüft.':'Angezeigte frühere Werte sind kein gültiger Sicherheitsnachweis.'),
                ['type'=>'ExpansionPanel','caption'=>'Base-ID-Historie','expanded'=>false,'items'=>$history?:[$label('Noch keine Einträge.')]],
                ['type'=>'Button','caption'=>'Lokales Inventar als JSON sichern','download'=>'EnOcean-Gateway-Inventar.json',
                    'onClick'=>'echo "data:application/json;base64," . base64_encode(EGMM_ExportProductInventory($id));'],
                $label('Export enthält alle logischen Gateways und lokale Identitäten. Import/Restore ist noch nicht validiert.'),
                ['type'=>'ExpansionPanel','caption'=>'Technisches Protokoll und Sicherheitsdiagnose','expanded'=>false,'items'=>$advanced],
                ['type'=>'ExpansionPanel','caption'=>'Transport-Konfiguration','expanded'=>false,'items'=>$transportElements]]]];
        return ['$schema'=>$technical['$schema']??'https://www.symcon.de/assets/files/validation/formSchema.json',
            'elements'=>[
                ['type'=>'ValidationTextBox','name'=>'GatewayName','caption'=>'Gatewayname'],
                ['type'=>'CheckBox','name'=>'EnableReadActions','caption'=>'Gateway auslesen erlauben','visible'=>!($s['readEnabled']??true)],
                // Preserve native owner binding; never automatically Apply changes.
                ['type'=>'ExpansionPanel','caption'=>'Gewünschte Base-ID für die Gatewayänderung','visible'=>$master!==null&&$phase==='NEEDS_CONFIGURATION','expanded'=>true,'items'=>[
                    $label('Master Base-ID bewusst auswählen, dann Änderungen übernehmen. Dies ändert nur die lokale Konfiguration, nicht das Gateway.'),
                    ['type'=>'Select','name'=>'MasterTargetChoice','caption'=>'Gewünschte Base-ID','enabled'=>count($options)>1,'options'=>$options]]]],
            'actions'=>$actions,'status'=>$technical['status']??[]];
    }
}
