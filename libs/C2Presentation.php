<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;

/** Presentation only. Never supplies hardware evidence or authorizes a write. */
final class C2Presentation
{
    public static function form(array $v,string $saved,array $review,string $source='manual',string $masterSource='manual'): array
    {
        $s=$v['session']??[];$h=$v['handoff']??[];
        $phase=$s['phase']??($h['phase']??'IDLE');
        $ready=$phase==='MAINTENANCE_READY'&&($v['fresh']??false);
        $local=in_array($phase,['IDLE','MAINTENANCE_READY','RETURNED','RETURN_WARNING'],true);
        $pending=$phase==='NATIVE_REFRESH_PENDING';
        $selected=(int)($v['selectedReference']??0);
        $inventory=$v['inventory']??[];$replacement=$inventory['replacement']??false;
        $inventoryOK=!isset($inventory['error']);$master=$inventory['gateway']['master']??null;
        $display=($v['fresh']??false)?($s['snapshot']??[]):($v['lastKnown']??[]);
        $base=$display['idbase']??[];$version=$display['version']??[];
        $refresh=$v['nativeRefresh']??[];
        $verifiedReturned=$phase==='RETURNED'&&($s['faults']??[])===[]&&($h['phase']??'')==='RESTORED'
            &&($refresh['status']??'')==='OBSERVED_NATIVE_REFRESH'&&($v['nativeContextValid']??false)
            &&($refresh['base']??null)===($base['baseIdRawHex']??null)
            &&($refresh['counter']??null)===($base['remainingWriteCycles']??null);
        $start=in_array($phase,['IDLE','RETURNED','RETURN_WARNING'],true)
            ||($phase==='FAULT_LATCHED'&&in_array($h['phase']??'IDLE',['IDLE','RESTORED'],true));
        $return=!$start&&!$pending&&($h['phase']??'')!=='RETURN_CLOSING';
        $canStart=$start&&$selected>0;
        $status=match($phase){
            'IDLE','RESTORED'=>'Gateway wird von IP-Symcon verwendet.',
            'CAPTURED','CLOSING_NATIVE','DETACHED','ACTIVE','SYNCHRONIZING'=>'Gateway wird geprüft. Bitte warten. IP-Symcon nutzt das Gateway während der Wartung nicht.',
            'MAINTENANCE_READY'=>$ready?'Gateway bereit zur Base-ID-Verwaltung.':'Die Wartungsverbindung muss geprüft werden. Geben Sie die Verbindung an IP-Symcon zurück.',
            'REVIEW_A'=>'Geplante Änderung. Prüfen Sie die Werte, bevor Sie fortfahren.',
            'REVIEW_B'=>'Base-ID wirklich ändern?',
            'PREWRITE_VERIFYING'=>'Abschließende Sicherheitsprüfung läuft. Bitte warten. Es wird nichts geschrieben.',
            'WRITE_BLOCKED'=>'Prüfung erfolgreich. Testmodus: Die Änderung wurde nicht ausgeführt. Sie können zur Auswahl zurückgehen oder die Wartung beenden.',
            'NATIVE_REFRESH_PENDING'=>($v['nativeRestored']??false)
                ?'Gateway wieder an IP-Symcon übergeben. Die abschließende Hintergrundprüfung läuft noch.'
                :'Verbindung wird an IP-Symcon zurückgegeben. Bitte warten; kein weiterer Eingriff ist nötig.',
            'RETURNED'=>'Gateway wird von IP-Symcon verwendet. Rückgabe vollständig geprüft.',
            'RETURN_WARNING'=>'Die Verbindung wurde zurückgegeben, aber die abschließende Prüfung ist nicht belegt. Prüfen Sie die native Gatewayverbindung und die technischen Details.',
            'FAULT_LATCHED'=>$start
                ?'Wartung gestoppt. Die Verbindung liegt bereits wieder bei IP-Symcon. Starten Sie eine neue Prüfung; Einzelheiten finden Sie unter Technische Details.'
                :'Wartung aus Sicherheitsgründen gestoppt. Weitere Änderungen sind gesperrt. Geben Sie die Verbindung an IP-Symcon zurück und starten Sie danach eine neue Prüfung.',
            default=>'Gatewayzustand nicht eindeutig. Prüfen Sie die technischen Details; keine Änderung durchführen.',
        };
        if($replacement&&$ready)$status='Ein anderes Gateway wurde erkannt. Ordnen Sie es unter „Gespeicherte Base-IDs“ zu, bevor Sie eine Änderung vorbereiten.';
        if(!$inventoryOK)$status='Lokale Sicherungsdaten konnten nicht gelesen werden. Zielauswahl ist gesperrt; prüfen Sie die technischen Details.';
        if($selected===0&&$phase==='IDLE')$status='Bitte wählen Sie ein vorhandenes EnOcean-Gateway aus und übernehmen Sie die Auswahl.';
        $label=static fn(string $name,string $caption,bool $visible=true):array=>['type'=>'Label','name'=>$name,'caption'=>$caption,'visible'=>$visible];
        $button=static fn(string $name,string $caption,string $click,bool $enabled,bool $visible=true):array=>[
            'type'=>'Button','name'=>$name,'caption'=>$caption,'onClick'=>$click,'enabled'=>$enabled,'visible'=>$visible];
        $history=[['caption'=>'Base-ID aus der Historie auswählen','value'=>'']];
        foreach($inventory['history']??[]as$row){
            $at=$row['lastSeen']??$row['firstSeen']??null;$time=is_string($at)?strtotime($at):false;
            $history[]=['caption'=>$row['baseID'].' · '.($time===false?'Datum nicht verfügbar':date('d.m.Y H:i T',$time)),'value'=>$row['baseID']];
        }
        $sources=[['caption'=>'Manuell eingeben','value'=>'manual']];
        if($master!==null)$sources[]=['caption'=>'Master Base-ID: '.$master,'value'=>'master'];
        if(count($history)>1)$sources[]=['caption'=>'Aus der Historie auswählen','value'=>'history'];
        if(!in_array($source,array_column($sources,'value'),true))$source='manual';
        $current=$base['baseIdRawHex']??'Noch nicht gelesen';
        $counter=($base['remainingWriteCyclesMode']??'')==='unlimited'?'Unbegrenzt':(string)($base['remainingWriteCycles']??'Nicht verfügbar');
        $reviewVisible=$review!==[]&&in_array($phase,['REVIEW_A','REVIEW_B','PREWRITE_VERIFYING','WRITE_BLOCKED'],true);
        $remaining=$review['remaining']??null;$expected=$review['expectedRemaining']??null;
        $cycle=static fn($n):string=>$n===255?'Unbegrenzt':(string)$n;
        if(!in_array($masterSource,['manual','history'],true))$masterSource='manual';
        $notice='';$message=$v['message']??'';
        if(str_starts_with($message,'Aktuelle Base-ID lokal gesichert'))$notice='Base-ID gesichert. Das Gateway wurde nicht verändert.';
        elseif(str_starts_with($message,'Master Base-ID bewusst lokal gespeichert'))$notice='Master Base-ID gespeichert. Das Gateway wurde nicht verändert.';
        elseif(str_starts_with($message,'Hardwarewechsel bewusst zugeordnet'))$notice='Neues Gateway zugeordnet. Frühere Sicherung und Master bleiben erhalten.';
        elseif(str_starts_with($message,'Lokale Sicherung gelöscht'))$notice='Lokale Sicherung gelöscht. Das Gateway und sein Änderungszähler bleiben unverändert.';
        elseif(str_starts_with($message,'Sicherung abgelehnt:'))$notice='Sicherung nicht gespeichert. Prüfen Sie die technischen Details.';
        $options=[
            $label('C2LocalValues','Master Base-ID: '.($master??'Nicht festgelegt').' · Gesicherte Base-ID: '.($saved!==''?$saved:'Nicht vorhanden')),
            $label('C2KnownHistory','Historie – lokal bekannte Base-IDs: '.(count($history)>1?implode('; ',array_column(array_slice($history,1),'caption')):'Noch keine Einträge')),
            $label('C2LocalHint','Diese Optionen speichern nur lokale Werte. Sie verändern weder Gateway noch Änderungszähler.'),
            $button('C2Save','Aktuelle Base-ID sichern','EGMM_SaveCurrentBaseID($id);',$ready&&$inventoryOK&&!$replacement),
            ['type'=>'Select','name'=>'C2MasterSource','caption'=>'Master Base-ID auswählen','options'=>[
                ['caption'=>'Manuell eingeben','value'=>'manual'],['caption'=>'Aus der Historie auswählen','value'=>'history']],
                'value'=>$masterSource,'enabled'=>$local&&$inventoryOK,'onChange'=>'EGMM_SelectNativeMasterSource($id, $C2MasterSource);'],
            ['type'=>'ValidationTextBox','name'=>'C2MasterEntry','caption'=>'Master Base-ID (8 Hexzeichen)','enabled'=>$local&&$inventoryOK,'visible'=>$masterSource==='manual'],
            ['type'=>'Select','name'=>'C2MasterHistoryChoice','caption'=>'Lokal bekannte Base-ID','options'=>$history,'enabled'=>$local&&$inventoryOK,'visible'=>$masterSource==='history'],
            $button('C2MasterSave','Angezeigte Base-ID als Master sichern','EGMM_SaveSelectedNativeMaster($id, $C2MasterSource, $C2MasterEntry, $C2MasterHistoryChoice);',$local&&$inventoryOK),
            $button('C2Replacement','Neues Gateway zuordnen und aktuelle Base-ID sichern','EGMM_AcceptNativeReplacement($id);',$ready&&$inventoryOK,$replacement),
        ];
        $details=[
            $label('C2InternalState','Diagnosezustand: '.$phase.' · Übergabe: '.($h['phase']??'IDLE')),
            $label('C2SessionDetails','Session: '.($s['id']??'Keine').' · Gateway-Instanz: '.($v['selectedReference']??'Nicht gewählt').' · Transport: '.json_encode($h['snapshot']['ioConfiguration']??[],JSON_UNESCAPED_UNICODE)),
            $button('C2Delete','Lokale Sicherung löschen','EGMM_DeleteSavedBaseID($id);',$local&&$saved!==''),
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
        $gateways=$selected===0?[['caption'=>'Bitte EnOcean-Gateway auswählen','value'=>0]]:[];
        foreach($v['gateways']??[]as$gateway){
            $gateways[]=['caption'=>$gateway['name'],'value'=>$gateway['id']];
        }
        return ['elements'=>[['type'=>'Select','name'=>'NativeGatewayInstanceID','caption'=>'EnOcean-Gateway auswählen','options'=>$gateways,'enabled'=>$start]],
            'actions'=>[
                $label('C2Status',$status),$label('C2Notice',$notice,$notice!==''),
                $label('C2Base',(($v['fresh']??false)||$verifiedReturned?'Aktuelle Base-ID des Gateways: ':'Zuletzt gelesene Base-ID: ').$current),
                $label('C2Counter','Verbleibende Änderungen: '.$counter),
                $button('C2Start','Gateway prüfen und Base-ID verwalten','EGMM_StartNativeMaintenance($id);',$canStart,$start),
                $label('C2MasterExplanation','Master Base-ID: Die bewusst gespeicherte Referenz für dieses System. Beim Gatewaytausch können Sie sie verwenden, um die bisherige Base-ID auf das neue Gateway zu übernehmen.'),
                ['type'=>'Select','name'=>'C2TargetSource','caption'=>'Gewünschte Base-ID auswählen','options'=>$sources,'value'=>$source,
                    'enabled'=>$ready&&$inventoryOK&&!$replacement,'visible'=>$ready,
                    'onChange'=>'EGMM_SelectNativeTargetSource($id, $C2TargetSource);'],
                ['type'=>'ValidationTextBox','name'=>'ManualBaseID','caption'=>'Gewünschte Base-ID (8 Hexzeichen)','enabled'=>$ready&&$inventoryOK&&!$replacement,'visible'=>$ready&&$source==='manual'],
                ['type'=>'Select','name'=>'C2HistoryChoice','caption'=>'Gewünschte historische Base-ID','options'=>$history,'enabled'=>$ready&&$inventoryOK&&!$replacement,'visible'=>$ready&&$source==='history'],
                $button('C2Review','Änderung prüfen','EGMM_ReviewNativeSelectedTarget($id, $C2TargetSource, $ManualBaseID, $C2HistoryChoice);',$ready&&$inventoryOK&&!$replacement,$ready),
                $label('C2ReviewHeading',$phase==='REVIEW_B'?'Base-ID wirklich ändern?':'Geplante Änderung',$reviewVisible),
                $label('C2ReviewCurrent','Aktuelle Base-ID: '.($review['current']??''),$reviewVisible),
                $label('C2ReviewTarget','Neue Base-ID: '.($review['target']??''),$reviewVisible),
                $label('C2ReviewCounter','Verbleibende Änderungen: '.$cycle($remaining).' → '.$cycle($expected),$reviewVisible),
                $label('C2FinalNotice',$remaining===255?'Die Base-ID des Gateways wird geändert. Dieses Gateway meldet unbegrenzte Änderungen.':'Die Base-ID des Gateways wird geändert. Dabei wird ein verfügbarer Änderungszyklus verwendet. Verbleibende Änderungen danach: '.$cycle($expected),$phase==='REVIEW_B'),
                $button('C2ConfirmA','Gewünschte Base-ID schreiben','EGMM_ConfirmNativeTargetA($id, '.json_encode($review['token']??'').');',$phase==='REVIEW_A',$phase==='REVIEW_A'),
                $button('C2ConfirmB','Jetzt schreiben','EGMM_ConfirmNativeTargetB($id, '.json_encode($review['token']??'').', '.json_encode($review['target']??'').');',$phase==='REVIEW_B',$phase==='REVIEW_B'),
                $button('C2Back','Zurück zur Auswahl','EGMM_BackToNativeTargetSelection($id);',in_array($phase,['REVIEW_A','REVIEW_B','WRITE_BLOCKED'],true),$reviewVisible),
                $label('C2Barrier','Testmodus: Es wird keine Base-ID geschrieben und kein Änderungszyklus verbraucht.'),
                $button('C2Return','Wartung beenden','EGMM_ReturnNativeMaintenance($id);',$return,!$start),
                $label('C2ReturnHint','Das Gateway wird wieder an IP-Symcon übergeben. Abschließende Prüfung im Hintergrund: normalerweise ca. 1–2 Minuten.',!$start),
                $label('C2ReturnPending','Sie können diese Ansicht verlassen. Eine neue Wartung ist erst nach Abschluss der Hintergrundprüfung möglich.',$pending&&($v['nativeRestored']??false)),
                ['type'=>'ExpansionPanel','name'=>'C2Options','caption'=>'Gespeicherte Base-IDs','expanded'=>false,'items'=>$options],
                ['type'=>'ExpansionPanel','name'=>'C2Details','caption'=>'Technische Details','expanded'=>false,'items'=>$details],
            ],'status'=>[['code'=>102,'icon'=>'active','caption'=>'Manager betriebsbereit'],['code'=>104,'icon'=>'inactive','caption'=>$selected===0?'EnOcean-Gateway auswählen':'Übergabe oder Prüfung läuft'],['code'=>201,'icon'=>'error','caption'=>'Gatewayzustand prüfen / Wartung gesperrt']]];
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
