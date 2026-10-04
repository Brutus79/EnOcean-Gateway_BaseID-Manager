<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;

/** Presentation only. Never supplies hardware evidence or authorizes a write. */
final class C2Presentation
{
    public static function form(array $v,string $saved,array $review,string $source='manual'): array
    {
        $s=$v['session']??[];$h=$v['handoff']??[];
        $phase=$s['phase']??($h['phase']??'IDLE');
        $ready=$phase==='MAINTENANCE_READY'&&($v['fresh']??false);
        $local=in_array($phase,['IDLE','MAINTENANCE_READY','RETURNED','RETURN_WARNING'],true);
        $pending=$phase==='NATIVE_REFRESH_PENDING';
        $inventory=$v['inventory']??[];$replacement=$inventory['replacement']??false;
        $inventoryOK=!isset($inventory['error']);$master=$inventory['gateway']['master']??null;
        $base=$s['snapshot']['idbase']??[];$version=$s['snapshot']['version']??[];
        $start=in_array($phase,['IDLE','RETURNED','RETURN_WARNING'],true)
            ||($phase==='FAULT_LATCHED'&&in_array($h['phase']??'IDLE',['IDLE','RESTORED'],true));
        $return=!$start&&!$pending;
        $status=match($phase){
            'IDLE','RESTORED'=>'Wählen Sie Ihr vorhandenes Gateway und starten Sie die Wartung.',
            'CAPTURED','CLOSING_NATIVE','DETACHED','ACTIVE','SYNCHRONIZING'=>'Gateway wird geprüft. Bitte warten. IP-Symcon nutzt das Gateway während der Wartung nicht.',
            'MAINTENANCE_READY'=>$ready?'Gateway geprüft. Wählen Sie die gewünschte Base-ID oder geben Sie sie ein.':'Die Wartungsverbindung muss geprüft werden. Geben Sie die Verbindung an IP-Symcon zurück.',
            'REVIEW_A'=>'Prüfen Sie die angezeigte Änderung und bestätigen Sie den ersten Schritt.',
            'REVIEW_B'=>'Erster Schritt bestätigt. Starten Sie jetzt die abschließende, frische Sicherheitsprüfung.',
            'PREWRITE_VERIFYING'=>'Abschließende Sicherheitsprüfung läuft. Bitte warten. Es wird nichts geschrieben.',
            'WRITE_BLOCKED'=>'Vorbereitung erfolgreich. Das Gateway wurde nicht verändert. Beenden Sie jetzt die Wartung.',
            'NATIVE_REFRESH_PENDING'=>($h['phase']??'')==='RESTORED'
                ?'Verbindung wurde an IP-Symcon zurückgegeben. Abschließende Prüfung läuft. Dies kann etwa 1–2 Minuten dauern. Bitte warten; kein weiterer Eingriff ist nötig.'
                :'Verbindung wird an IP-Symcon zurückgegeben. Bitte warten; kein weiterer Eingriff ist nötig.',
            'RETURNED'=>'IP-Symcon verwendet das Gateway wieder. Die abschließende Prüfung war erfolgreich.',
            'RETURN_WARNING'=>'Die Verbindung wurde zurückgegeben, aber die abschließende Prüfung ist nicht belegt. Prüfen Sie die native Gatewayverbindung und die technischen Details.',
            'FAULT_LATCHED'=>'Wartung aus Sicherheitsgründen gestoppt. Weitere Änderungen sind gesperrt. Geben Sie die Verbindung an IP-Symcon zurück und starten Sie danach eine neue Prüfung.',
            default=>'Gatewayzustand nicht eindeutig. Prüfen Sie die technischen Details; keine Änderung durchführen.',
        };
        if($replacement&&$ready)$status='Ein anderes Gateway wurde erkannt. Ordnen Sie es unter „Weitere Optionen“ zu, bevor Sie eine Änderung vorbereiten.';
        if(!$inventoryOK)$status='Lokale Sicherungsdaten konnten nicht gelesen werden. Zielauswahl ist gesperrt; prüfen Sie die technischen Details.';
        $label=static fn(string $name,string $caption,bool $visible=true):array=>['type'=>'Label','name'=>$name,'caption'=>$caption,'visible'=>$visible];
        $button=static fn(string $name,string $caption,string $click,bool $enabled,bool $visible=true):array=>[
            'type'=>'Button','name'=>$name,'caption'=>$caption,'onClick'=>$click,'enabled'=>$enabled,'visible'=>$visible];
        $history=[['caption'=>'Base-ID aus der Historie auswählen','value'=>'']];
        foreach($inventory['history']??[]as$row)$history[]=['caption'=>$row['baseID'].' · '.($row['observed']?'vom Gateway gelesen':'lokal hinterlegt'),'value'=>$row['baseID']];
        $sources=[['caption'=>'Manuell eingeben','value'=>'manual']];
        if($master!==null)$sources[]=['caption'=>'Master Base-ID: '.$master,'value'=>'master'];
        if($saved!=='')$sources[]=['caption'=>'Gesicherte Base-ID: '.$saved,'value'=>'saved'];
        if(count($history)>1)$sources[]=['caption'=>'Aus der Historie auswählen','value'=>'history'];
        if(!in_array($source,array_column($sources,'value'),true))$source='manual';
        $current=$base['baseIdRawHex']??'Noch nicht gelesen';
        $counter=($base['remainingWriteCyclesMode']??'')==='unlimited'?'Unbegrenzt':(string)($base['remainingWriteCycles']??'Nicht verfügbar');
        $reviewVisible=$review!==[]&&in_array($phase,['REVIEW_A','REVIEW_B','PREWRITE_VERIFYING','WRITE_BLOCKED'],true);
        $summary=$review===[]?'':sprintf('Aktuelle Base-ID: %s → gewünschte Base-ID: %s. Mögliche Änderungen: %s → %s nach einem erfolgreichen Write. In diesem Test wird kein Änderungszyklus verbraucht.',
            $review['current'],$review['target'],$review['remaining']===255?'unbegrenzt':$review['remaining'],$review['expectedRemaining']===255?'unbegrenzt':$review['expectedRemaining']);
        $notice='';$message=$v['message']??'';
        if(str_starts_with($message,'Aktuelle Base-ID lokal gesichert'))$notice='Base-ID gesichert. Das Gateway wurde nicht verändert.';
        elseif(str_starts_with($message,'Master Base-ID bewusst lokal gespeichert'))$notice='Master Base-ID gespeichert. Das Gateway wurde nicht verändert.';
        elseif(str_starts_with($message,'Hardwarewechsel bewusst zugeordnet'))$notice='Neues Gateway zugeordnet. Frühere Sicherung und Master bleiben erhalten.';
        elseif(str_starts_with($message,'Lokale Sicherung gelöscht'))$notice='Lokale Sicherung gelöscht. Das Gateway und sein Änderungszähler bleiben unverändert.';
        elseif(str_starts_with($message,'Sicherung abgelehnt:'))$notice='Sicherung nicht gespeichert. Prüfen Sie die technischen Details.';
        $options=[
            $label('C2LocalValues','Master Base-ID: '.($master??'Nicht festgelegt').' · Gesicherte Base-ID: '.($saved!==''?$saved:'Nicht vorhanden')),
            $label('C2LocalHint','Diese Optionen speichern nur lokale Werte. Sie verändern weder Gateway noch Änderungszähler.'),
            $button('C2Save','Aktuelle Base-ID sichern','EGMM_SaveCurrentBaseID($id);',$ready&&$inventoryOK&&!$replacement),
            $button('C2MasterCurrent','Aktuelle Base-ID als Master speichern','EGMM_SetNativeMasterBaseID($id, "", "hardware", true);',$ready&&$inventoryOK),
            ['type'=>'ValidationTextBox','name'=>'C2MasterEntry','caption'=>'Master Base-ID eingeben (8 Hexzeichen)','enabled'=>$local&&$inventoryOK,'visible'=>true],
            $button('C2MasterSave','Master Base-ID speichern','EGMM_SetNativeMasterBaseID($id, $C2MasterEntry, "manual", true);',$local&&$inventoryOK),
            ['type'=>'Select','name'=>'C2MasterHistoryChoice','caption'=>'Master aus der Historie','options'=>$history,'enabled'=>$local&&$inventoryOK,'visible'=>true],
            $button('C2MasterHistory','Ausgewählte historische Base-ID als Master speichern','EGMM_SetNativeMasterBaseID($id, $C2MasterHistoryChoice, "history", true);',$local&&$inventoryOK),
            $button('C2Delete','Lokale Sicherung löschen','EGMM_DeleteSavedBaseID($id);',$local&&$saved!==''),
            $button('C2Replacement','Neues Gateway zuordnen und aktuelle Base-ID sichern','EGMM_AcceptNativeReplacement($id);',$ready&&$inventoryOK,$replacement),
        ];
        $details=[
            $label('C2InternalState','Diagnosezustand: '.$phase.' · Übergabe: '.($h['phase']??'IDLE')),
            $label('C2RawMessage',$message),
            $label('C2EURID','Eindeutige Radio-ID (EURID): '.($version['eurid']??'Nicht ermittelt')),
            $label('C2Firmware','Firmware-Version: '.($version['applicationVersion']??'Nicht ermittelt')),
            $label('C2API','API-Version: '.($version['apiVersion']??'Nicht ermittelt')),
            $label('C2Device','Device-Version (Hex): '.($version['deviceVersionHex']??'Nicht ermittelt')),
            $label('C2Description','Firmware-Beschreibung: '.($version['applicationDescription']??'Nicht ermittelt')),
            $label('C2Region','Gateway-Generation / Funkregion: nicht zuverlässig ermittelt.'),
            $label('C2FaultDetails','Fehlerdetails: '.json_encode($s['faults']??[],JSON_UNESCAPED_UNICODE)),
            $label('C2InventoryError','Sicherungsdaten: '.($inventory['error']??'Verfügbar')),
        ];
        $gateways=[['caption'=>'Vorhandenes EnOcean-Gateway auswählen','value'=>0]];
        foreach($v['gateways']??[]as$gateway)$gateways[]=['caption'=>$gateway['name'],'value'=>$gateway['id']];
        return ['elements'=>[['type'=>'Select','name'=>'NativeGatewayInstanceID','caption'=>'EnOcean-Gateway','options'=>$gateways,'enabled'=>$start]],
            'actions'=>[
                $label('C2Status',$status),$label('C2Notice',$notice,$notice!==''),
                $label('C2Base',($v['fresh']??false?'Aktuelle Base-ID: ':'Zuletzt gelesene Base-ID: ').$current),
                $label('C2Counter','Verbleibende mögliche Base-ID-Änderungen: '.$counter),
                $button('C2Start','Gateway prüfen / Wartung starten','EGMM_StartNativeMaintenance($id);',$start,$start),
                ['type'=>'Select','name'=>'C2TargetSource','caption'=>'Gewünschte Base-ID verwenden aus','options'=>$sources,'value'=>$source,
                    'enabled'=>$ready&&$inventoryOK&&!$replacement,'visible'=>$ready,
                    'onChange'=>'EGMM_SelectNativeTargetSource($id, $C2TargetSource);'],
                ['type'=>'ValidationTextBox','name'=>'ManualBaseID','caption'=>'Gewünschte Base-ID (8 Hexzeichen)','enabled'=>$ready&&$inventoryOK&&!$replacement,'visible'=>$ready&&$source==='manual'],
                ['type'=>'Select','name'=>'C2HistoryChoice','caption'=>'Gewünschte historische Base-ID','options'=>$history,'enabled'=>$ready&&$inventoryOK&&!$replacement,'visible'=>$ready&&$source==='history'],
                $button('C2Review','Änderung prüfen','EGMM_ReviewNativeSelectedTarget($id, $C2TargetSource, $ManualBaseID, $C2HistoryChoice);',$ready&&$inventoryOK&&!$replacement,$ready),
                $label('C2ReviewSummary',$summary,$reviewVisible),
                $button('C2ConfirmA','1. Angezeigte Änderung bestätigen','EGMM_ConfirmNativeTargetA($id, '.json_encode($review['token']??'').');',$phase==='REVIEW_A',$phase==='REVIEW_A'),
                $button('C2ConfirmB','2. Frisch prüfen und Vorbereitung abschließen','EGMM_ConfirmNativeTargetB($id, '.json_encode($review['token']??'').', '.json_encode($review['target']??'').');',$phase==='REVIEW_B',$phase==='REVIEW_B'),
                $label('C2Barrier','Dieser Teststand schreibt keine Base-ID. Die Vorbereitung endet vor dem Schreiben; es wird kein Änderungszyklus verbraucht.'),
                $button('C2Return','Wartung beenden / Verbindung an IP-Symcon zurückgeben','EGMM_ReturnNativeMaintenance($id);',$return,!$start),
                ['type'=>'ExpansionPanel','name'=>'C2Options','caption'=>'Weitere Optionen: Sicherung, Master und Historie','expanded'=>false,'items'=>$options],
                ['type'=>'ExpansionPanel','name'=>'C2Details','caption'=>'Technische Details','expanded'=>false,'items'=>$details],
            ],'status'=>[['code'=>102,'icon'=>'active','caption'=>'Gateway im Wartungsmodus'],['code'=>201,'icon'=>'inactive','caption'=>'Keine aktive Wartung / native Verbindung']]];
    }

    /** Only mutable parameters; never overwrite text inputs or panel expansion. */
    public static function fields(array $form): array
    {
        $out=[];$walk=static function(array $items)use(&$walk,&$out):void {
            foreach($items as$item){
                if(isset($item['name'])){
                    foreach(['caption','visible','enabled','options','onClick','value']as$key)
                        if(array_key_exists($key,$item))$out[$item['name']][$key]=$item[$key];
                }
                if(isset($item['items']))$walk($item['items']);
            }
        };$walk($form['elements']);$walk($form['actions']);return$out;
    }
}
