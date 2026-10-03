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
            'PRE_WRITE_JOURNALED'=>'Änderung vorbereitet. Hardware-Schreibfunktion für diesen Release-Candidate-Test noch gesperrt.',
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
        // Session must always be freshly bound by the arbiter, not reused as a comparison exemption.
        foreach(['EURID','baseID','target','counter','expectedCounter','binding']as$k){if(!array_key_exists($k,$a)||($a[$k]??null)!==($b[$k]??null))return false;}return true;
    }
    public static function form(array $s,array $technical): array
    {
        $label=static fn(string $text):array=>['type'=>'Label','caption'=>$text];
        $h=$s['hardware'];$master=$s['gateway']['master']??null;$known=($h['baseID']??null)!==null;
        $match=$known&&$master!==null&&$master===$h['baseID'];$flow=$s['flow'];$ready=($flow['phase']??'')==='REVIEW';
        $review=(($s['targetOnlyBuild']??false)&&!$s['fresh'])?[]:($flow['review']??[]);
        $history=[];$choices=[];
        foreach($s['history']as$r){$history[]=$label($r['baseID'].' · '.($r['master']?'Master Base-ID':($r['formerMaster']?'ehemalige Master Base-ID':'Historie')).' · '.($r['observed']?'auf Hardware beobachtet':'nicht als Hardwarewert belegt').($r['written']?' · erfolgreich geschrieben':'').($r['requested']?' · angefordert':'').' · '.$r['firstSeen'].' – '.$r['lastSeen']);$choices[]=['caption'=>$r['baseID'],'value'=>$r['baseID']];}
        $candidates=[];foreach($s['discovery']as$d)$candidates[]=$label($d['path'].' · '.$d['status']);
        // Existing transports are not reconfigured by imperative helper scripts.
        $connection=[$label('Neue Schnittstelle im EnOcean Gateway Manager Konfigurator auswählen. Die interne Gatewaykette wird dort mit 57600/8N1 angelegt.'),
            ['type'=>'OpenObjectButton','caption'=>'Gatewayeinrichtung öffnen','objectID'=>$s['configuratorID']??0,'visible'=>($s['configuratorID']??0)>0],
            ['type'=>'Button','caption'=>'Verbindung herstellen / Gateway auslesen','enabled'=>!$s['leaseActive'],'onClick'=>'EGMM_RefreshProductGateway($id);'],...$candidates];
        $setMaster=[$label('Master ändern ist ausschließlich lokal. Alte Master Base-IDs bleiben in der Historie.'),
            ['type'=>'Button','caption'=>'Aktuelle Base-ID als Master speichern','enabled'=>$known&&$s['fresh']&&!$s['leaseActive'],'confirm'=>'Aktuelle Hardware Base-ID als Master Base-ID speichern? Das verändert nur die lokale Referenz, nicht das Gateway.','onClick'=>'EGMM_SetMasterBaseID($id, "", "hardware", true);'],
            ['type'=>'Select','name'=>'HistoryMasterChoice','caption'=>'Master Base-ID aus Historie','options'=>$choices?:[['caption'=>'Noch keine Historie','value'=>'']]],
            ['type'=>'Button','caption'=>'Historische Base-ID als Master übernehmen','enabled'=>!$s['leaseActive'],'confirm'=>'Gewählte Base-ID als Master Base-ID speichern? Kein Hardware-Write.','onClick'=>'EGMM_SetMasterBaseID($id, $HistoryMasterChoice, "history", true);'],
            ['type'=>'ValidationTextBox','name'=>'MasterEntry','caption'=>'Andere Master Base-ID manuell wählen'],
            ['type'=>'Button','caption'=>'Manuelle Master Base-ID speichern','enabled'=>!$s['leaseActive'],'confirm'=>'Diese Base-ID als Master Base-ID festlegen? Es wird ausschließlich die lokale Referenz geändert.','onClick'=>'EGMM_SetMasterBaseID($id, $MasterEntry, "manual", true);'],
            ['type'=>'Button','caption'=>'Später festlegen','onClick'=>'EGMM_DeferMaster($id);']];
        $advanced=$technical['actions']??[];
        $transportElements=array_values(array_filter($technical['elements']??[],static fn(array $item):bool=>!in_array($item['name']??'',['EnableReadActions','BaseIDSource','ManualBaseID'],true)));
        $config=$s['targetConfiguration']??['BaseIDSource'=>'saved','ManualBaseID'=>''];
        $current=[['name'=>'BaseIDSource','value'=>$config['BaseIDSource']],['name'=>'ManualBaseID','value'=>$config['ManualBaseID']]];
        $targetOptions=[['caption'=>($s['targetValidated']??false)?'Master-ID bereits als Ziel angewandt':'Bisheriges Ziel unverändert lassen','value'=>$current]];
        if(($s['masterTargetSelection']??null)!==null&&$s['masterTargetSelection']!==$current)$targetOptions[]=['caption'=>'Gespeicherte Master-ID '.$master.' als Ziel verwenden','value'=>$s['masterTargetSelection']];
        $indicator=($s['inventoryError']||($s['transactionState']??'')==='UNKNOWN_OUTCOME')?'🔴':($s['replacement']?'🟠':($s['fresh']?'🟢':'⚪'));
        $actions=[$label('GATEWAY-VERBINDUNG'),$label($indicator.' '.$s['connectionText']),
            ['type'=>'ExpansionPanel','caption'=>'Gateway manuell einrichten','expanded'=>!$known,'items'=>$connection],
            $label('GATEWAY-INFORMATIONEN'),$label('Gerät / Gateway: '.(($h['description']??null)!==null?'ESP3 · '.$h['description'].' · Modell nicht automatisch bestimmbar':'Noch nicht erkannt')),
            $label('EURID: '.($h['EURID']??'Nicht ermittelt')),$label('Aktuelle Base-ID: '.($h['baseID']??'Nicht ermittelt')),$label('Master Base-ID: '.($master??'Keine Master Base-ID festgelegt')),
            $label('Verbleibende Änderungen: '.($h['counter']=== 'UNLIMITED'?'Unbegrenzt':($h['counter']??'Nicht verfügbar')).' (laut Gateway)'),
            $label('Firmware: '.($h['firmware']??'Nicht ermittelt')),$label($s['message']),
            $label('Angewandtes Ziel: '.($s['configuredTarget']??'Nicht festgelegt').(($s['targetValidated']??false)?' · Master-ID frisch geprüft':' · noch nicht als Master-Ziel frisch geprüft')),$label($s['targetMessage']??''),
            $label($match?'✓ Master Base-ID und Gateway stimmen überein':($master===null?'Soll die erkannte Base-ID als Master Base-ID gespeichert werden?':($known?'Master Base-ID und Gateway unterscheiden sich.':'Master gespeichert; Gateway bitte frisch prüfen, bevor die Werte verglichen werden.'))),
            ['type'=>'Button','caption'=>'Gateway prüfen / aktualisieren','enabled'=>!$s['leaseActive'],'onClick'=>'EGMM_RefreshProductGateway($id);'],
            ['type'=>'Button','caption'=>'Gateway automatisch suchen','enabled'=>!$s['leaseActive'],'onClick'=>'EGMM_DiscoverProductGateways($id);'],
            ['type'=>'ExpansionPanel','caption'=>$master===null?'Master Base-ID festlegen':'Master Base-ID ändern','expanded'=>$master===null,'items'=>$setMaster],
            ['type'=>'Button','caption'=>'Master Base-ID auf Gateway übertragen','visible'=>$master!==null&&!$match,'enabled'=>!$s['leaseActive']&&!$s['inventoryError']&&!$s['replacement'],'onClick'=>'EGMM_StartMasterTransfer($id);'],
            ['type'=>'ExpansionPanel','caption'=>'Base-ID ändern','expanded'=>!empty($flow),'visible'=>!empty($flow),'items'=>[
                $label('Aktuell: '.($review['baseID']??$h['baseID']??'Wird geprüft')),$label('Neu: '.($review['target']??$master??'Nicht festgelegt').' · Master Base-ID'),
                $label('Verbleibende Änderungen: '.($review['counter']??'Wird gelesen').' → '.($review['expectedCounter']??'Wird geprüft')),
                $label('Die Base-ID kann nur begrenzt oft geändert werden. Bei unklarem Ausgang erfolgt keine Wiederholung.'),
                ['type'=>'Button','caption'=>($s['targetOnlyBuild']??false)?'Angewandtes Ziel prüfen (kein Write)':'Änderung prüfen','enabled'=>!$s['leaseActive'],'onClick'=>'EGMM_CheckMasterTransfer($id);'],
                ['type'=>'Button','caption'=>'Base-ID jetzt ändern','enabled'=>$ready&&!($s['targetOnlyBuild']??false),'confirm'=>'Genau die angezeigte Änderung bestätigen? Diese Aktion kann später einen begrenzten Schreibzyklus verbrauchen. Im B8-Release-Candidate bleibt Hardware-Schreiben gesperrt.','onClick'=>'EGMM_ConfirmMasterTransfer($id);'],
                ['type'=>'Button','caption'=>'Abbrechen','onClick'=>'EGMM_CancelProductWorkflow($id);'],
                $label('Hardware-Schreibfunktion für diesen Release-Candidate-Test noch gesperrt.')]],
            ['type'=>'ExpansionPanel','caption'=>'Neues Gateway / Austausch','visible'=>$s['replacement'],'expanded'=>true,'items'=>[
                $label('Neues physisches Gateway erkannt. Master und bisherige Historie bleiben erhalten.'),
                ['type'=>'Button','caption'=>'Neues Gateway bewusst zuordnen / später übertragen','confirm'=>'Neue Hardwareidentität dieser logischen Gatewayinstanz zuordnen? Master bleibt erhalten; keine Hardwareänderung.','onClick'=>'EGMM_AcceptReplacement($id);'],
                $label('Master ändern: oben unter „Master Base-ID ändern“. Die Hardwarezuordnung allein schreibt nichts.')]],
            ['type'=>'ExpansionPanel','caption'=>'Base-ID-Historie','items'=>$history?:[$label('Noch keine Einträge.')]],
            ['type'=>'Button','caption'=>'Master / History / Registry als JSON sichern','download'=>'EnOcean-Gateway-Inventar.json','onClick'=>'echo "data:application/json;base64," . base64_encode(EGMM_ExportProductInventory($id));'],
            ['type'=>'ExpansionPanel','caption'=>'Erweiterte Einstellungen & Diagnose','items'=>[
                $label('Registry-Schema 1; Import/Restore noch nicht verfügbar. Export enthält alle logischen Gateways.'),
                $label('Systemweite Registry: '.implode(', ',array_map(static fn($g)=>$g['name'].' · Master '.($g['master']??'nicht festgelegt'),$s['registryGateways']??[]))),
                $label('EURID: '.($h['EURID']??'Nicht ermittelt')),$label('Letzte erfolgreiche Abfrage: '.($h['readAt']??'Noch keine')),
                $label($s['fresh']?'Hardwaredaten frisch geprüft.':'Die Sicherheitsprüfung ist abgelaufen oder noch nicht erfolgt. Vor einer Änderung wird neu geprüft.'),
                ['type'=>'ExpansionPanel','caption'=>'Technisches Protokoll und Sicherheitsdiagnose','items'=>$advanced],
                ['type'=>'ExpansionPanel','caption'=>'Transport-Konfiguration','items'=>$transportElements]]]];
        return ['$schema'=>$technical['$schema']??'https://www.symcon.de/assets/files/validation/formSchema.json','elements'=>[['type'=>'ValidationTextBox','name'=>'GatewayName','caption'=>'Gatewayname'],
            ['type'=>'CheckBox','name'=>'EnableReadActions','caption'=>'Gateway auslesen erlauben','visible'=>!($s['readEnabled']??true)],
            ['type'=>'ExpansionPanel','caption'=>'Master-ID als Transferziel vorbereiten','visible'=>$master!==null,'items'=>[
                $label('Nur das lokale Ziel konfigurieren: Master-ID bewusst auswählen, dann Änderungen übernehmen. Kein Hardware-Write; Gateway danach neu prüfen.'),
                ['type'=>'Select','name'=>'MasterTargetChoice','caption'=>'Ziel für einen späteren Transfer','enabled'=>count($targetOptions)>1,'options'=>$targetOptions]]]],
            'actions'=>$actions,'status'=>$technical['status']??[]];
    }
}
