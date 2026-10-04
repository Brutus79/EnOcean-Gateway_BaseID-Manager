<?php
declare(strict_types=1);
require_once __DIR__.'/C2Session.php';
require_once __DIR__.'/C2SymconEnvironment.php';
require_once __DIR__.'/NativeRefreshVerifier.php';
require_once __DIR__.'/C2InventoryModule.php';
use EnOceanGatewayManager\Protocol\ESP3Codec;

/** C2 runtime adapter. No CO_WR_IDBASE path; all reads pass through the arbiter. */
trait GatewayC2Module
{
    use GatewayC2InventoryModule;
    private function c2Environment(): \EnOceanGatewayManager\Maintenance\C2SymconEnvironment
    {return new \EnOceanGatewayManager\Maintenance\C2SymconEnvironment();}
    private function c2Resolver(): \EnOceanGatewayManager\Maintenance\NativeGatewayResolver
    {
        $e=$this->c2Environment();
        return new \EnOceanGatewayManager\Maintenance\NativeGatewayResolver($e->instance(...),$e->configuration(...),$e->instances(...));
    }
    private function c2Journal(): \EnOceanGatewayManager\Safety\DurableWriteJournal
    {return new \EnOceanGatewayManager\Safety\DurableWriteJournal(rtrim(IPS_GetKernelDir(),'/').'/egm-c2-handoff-'.$this->InstanceID);}
    private function c2Handoff(): \EnOceanGatewayManager\Maintenance\C2Handoff
    {return new \EnOceanGatewayManager\Maintenance\C2Handoff($this->c2Environment(),$this->c2Journal());}
    private function c2SaveHandoff(\EnOceanGatewayManager\Maintenance\C2Handoff $h): void
    {$this->c2WriteChanged('C2Handoff',json_encode($h->state(),JSON_THROW_ON_ERROR));}
    private function c2Session(): \EnOceanGatewayManager\Maintenance\C2Session
    {return new \EnOceanGatewayManager\Maintenance\C2Session(json_decode($this->ReadAttributeString('C2Session'),true,512,JSON_THROW_ON_ERROR));}
    private function c2SaveSession(\EnOceanGatewayManager\Maintenance\C2Session $s): void
    {$this->c2WriteChanged('C2Session',json_encode($s->state(),JSON_THROW_ON_ERROR));}
    /** Identical SDK attribute writes still notify the open configuration form. */
    private function c2WriteChanged(string $name,string $value): void
    {if($this->ReadAttributeString($name)!==$value)$this->WriteAttributeString($name,$value);}
    private function c2Lock(callable $f): mixed
    {
        $lock='EGM_C2_COORDINATOR';if(!IPS_SemaphoreEnter($lock,1000))throw new RuntimeException('C2 coordinator busy.');
        try{return$f();}finally{IPS_SemaphoreLeave($lock);}
    }
    private function c2Context(\EnOceanGatewayManager\Maintenance\C2Handoff $h): array
    {
        $binding=$h->verifyActive();$hs=$h->state();
        if($this->ReadPropertyInteger('NativeGatewayInstanceID')!==$hs['snapshot']['nativeID'])throw new RuntimeException('Selected native reference changed.');
        if(!$this->activeGatewayTransport())throw new RuntimeException('Exclusive manager transport chain changed.');
        $c=json_decode(EGMA_GetReadSafetyContext($hs['ownArbiter']),true,512,JSON_THROW_ON_ERROR);
        if(!($c['realConnectionActive']??false)||!($c['transportCorrelationSafe']??false)
            ||!($c['noUnknownOutcome']??false)||!($c['exclusiveUARTOwner']??false)
            ||($c['writeLeaseActive']??true))throw new RuntimeException('Arbiter safety context/lease is not valid.');
        return ['session'=>$c['session']??'','transportBinding'=>$c['binding']??'', 'handoffBinding'=>$binding,
            'exclusive'=>$c['exclusiveUARTOwner'], 'descriptorCount'=>$c['uartDescriptorCount']??0,
            'faultEpoch'=>$c['communicationFaultEpoch']??-1,
            'writeLeaseActive'=>$c['writeLeaseActive'],'noUnknownOutcome'=>$c['noUnknownOutcome']];
    }
    private function c2Fail(string $reason): void
    {
        $s=$this->c2Session();$s->fault($reason,microtime(true));$this->c2SaveSession($s);
        $this->productMessage('Wartung gesperrt: '.$reason.'. Verbindung sicher zurückgeben; danach neu prüfen.',true);
    }
    public function StartNativeMaintenance(): bool
    {
        try{return$this->c2Lock(function():bool{
            $h=$this->c2Handoff();$h->begin($this->InstanceID,$this->ReadPropertyInteger('NativeGatewayInstanceID'),microtime(true));
            $this->c2SaveHandoff($h);$this->WriteAttributeString('C2Session','[]');
            $this->WriteAttributeString('C2Review','[]');
            $this->WriteAttributeString('C2ResultInbox','[]');$this->WriteAttributeString('C2NativeRefresh','[]');
            $this->SetBuffer('C2RuntimeStarted','1');$this->SetBuffer('ProductInitialRead','');
            $this->SetBuffer('ProductFlow','');$this->SetTimerInterval('C2Timer',100);
            $this->productMessage('Wartung startet: native Verbindung schließen und UART exklusiv übernehmen.',true);return true;
        });}catch(Throwable $e){
            try{$h=$this->c2Handoff();$this->c2SaveHandoff($h);}catch(Throwable){}
            $this->c2Fail($e->getMessage());return false;
        }
    }
    /** Receive only queues. Never call back into a locked arbiter from ReceiveData. */
    private function c2Receive(string $json): bool
    {
        $h=json_decode($this->ReadAttributeString('C2Handoff'),true)?:[];
        if(($h['phase']??'')!=='ACTIVE')return false;
        $p=json_decode($json,true);
        if(!is_array($p)||($p['DataID']??'')!==self::MAINTENANCE_RESULT_DATA_ID
            ||($p['OwnerInstanceID']??0)!==$this->InstanceID)return true;
        $rows=json_decode($this->ReadAttributeString('C2ResultInbox'),true)?:[];
        if(count($rows)>=32){$this->c2Fail('Result inbox overflow');return true;}
        $rows[]=$p;$this->WriteAttributeString('C2ResultInbox',json_encode($rows,JSON_THROW_ON_ERROR));return true;
    }
    public function ProcessC2Maintenance(): void
    {
        if($this->ReadAttributeString('C2Handoff')==='[]')return;
        $request=null;
        try{
            $this->c2Lock(function()use(&$request):void{
                $h=$this->c2Handoff();$phase=$h->state()['phase']??'IDLE';$now=microtime(true);
                // Journal is authoritative, including a Destroy/library reload
                // that restored transport before attributes could be updated.
                $this->c2SaveHandoff($h);
                if($this->GetBuffer('C2RuntimeStarted')!=='1'&&$phase==='RESTORED'
                    &&!in_array($this->c2Session()->state()['phase']??'IDLE',['IDLE','RETURNED','RETURN_WARNING'],true)){
                    $this->c2Fail('Runtime reload invalidated old evidence');
                    $this->WriteAttributeString('C2NativeRefresh','[]');$this->SetBuffer('C2RuntimeStarted','1');
                }
                if($this->GetBuffer('C2RuntimeStarted')!=='1'&&$phase!=='IDLE'&&$phase!=='RESTORED'){
                    // Reload/restart can only restore, NEVER continue a previous proof.
                    $this->c2Fail('Runtime restart invalidated maintenance evidence');
                    $this->WriteAttributeString('C2NativeRefresh','[]');
                    $h->restore($now);$this->c2SaveHandoff($h);$this->SetBuffer('C2RuntimeStarted','1');return;
                }
                if($phase==='CLOSING_NATIVE'){$h->advance($now);$this->c2SaveHandoff($h);return;}
                if($phase==='RETURN_CLOSING'){
                    if($h->finishRestore($now)==='RESTORED')$this->productMessage('Konfiguration zurückgegeben. IP-Symcon übernimmt Gateway / Base-ID wird synchronisiert.',true);
                    $this->c2SaveHandoff($h);return;
                }
                if($phase==='RESTORED'){$this->c2ObserveNativeRefresh($h,$now);return;}
                if($phase!=='ACTIVE')return;
                if($this->c2Session()->state()===[]){
                    // Opening an SDK I/O does not synchronously activate its
                    // splitter. No proof/read exists yet. Wait only for observed
                    // activation, never through a communication/context fault.
                    $h->verifyActive();
                    $activation=json_decode(EGMA_GetReadSafetyContext($h->state()['ownArbiter']),true,512,JSON_THROW_ON_ERROR);
                    if(($activation['communicationFaultEpoch']??-1)!==0||($activation['writeLeaseActive']??true)
                        ||!($activation['noUnknownOutcome']??false)||!($activation['transportCorrelationSafe']??false)
                        ||!($activation['exclusiveUARTOwner']??false)||($activation['uartDescriptorCount']??0)!==1)
                        throw new RuntimeException('Transport activation safety context is not valid.');
                    if(!$this->activeGatewayTransport()||!($activation['realConnectionActive']??false)){
                        if($now-$h->state()['closingAt']>3)throw new RuntimeException('Exclusive transport activation not observed.');
                        $this->productMessage('Exklusive Verbindung wird aktiviert; noch keine Hardwareprüfung / kein Maintenance bereit.');return;
                    }
                }
                $context=$this->c2Context($h);$s=$this->c2Session();
                if($s->state()===[]){
                    if($context['faultEpoch']!==0)throw new RuntimeException('Communication warning before initial synchronization');
                    $s->start($context,$now);$this->c2SaveSession($s);
                }
                if(!$s->checkContext($context,$now)){$this->c2SaveSession($s);$this->productMessage('Kommunikations-/Kontextfehler gelatcht. Wartung zurückgeben.');return;}
                // Every queued outcome is part of this session, not just the latest one.
                $rows=json_decode($this->ReadAttributeString('C2ResultInbox'),true)?:[];
                $this->c2WriteChanged('C2ResultInbox','[]');
                foreach($rows as$p){
                    if(($p['Session']??'')!==$context['session']||($p['Binding']??'')!==$context['transportBinding']){$s->fault('response_context_mismatch',$now);break;}
                    if(($p['Outcome']??'')!=='RESPONSE'){$s->fault('read_'.$p['Outcome'],$now);break;}
                    $s->response((string)$p['Token'],(string)$p['Operation'],ESP3Codec::fromHex((string)$p['FrameHex']),$context,$now);
                }
                $st=$s->state();$pending=$st['pending']??null;
                if($pending!==null&&$now-$pending['at']>5)$s->fault('read_timeout',$now);
                $request=$s->request($context,$now);$this->c2SaveSession($s);
                $st=$s->state();
                if(($st['phase']??'')==='MAINTENANCE_READY'){
                    if(!$s->verifiedSnapshot()){$this->c2Fail('Initial synchronization invalid');return;}
                    $this->c2PublishSnapshot($st,$h);
                    $this->productMessage('Maintenance bereit. Aktuelle Hardware frisch und konsistent erkannt. Reale Hardware-Writes bleiben gesperrt.');
                }elseif(($st['phase']??'')==='WRITE_BLOCKED'){
                    if(!$s->prewriteGate($st['target'],$context,$now)){$this->c2SaveSession($s);$this->productMessage('Prewrite-Nachweis ungültig oder abgelaufen. Kein Write; Wartung sicher zurückgeben.');return;}
                    $idle=json_decode(EGMA_GetReadSafetyContext($h->state()['ownArbiter']),true,512,JSON_THROW_ON_ERROR);
                    if(!($idle['correlationSafeAndIdle']??false)||($idle['writeLeaseActive']??true)
                        ||!($idle['realConnectionActive']??false)||!($idle['exclusiveUARTOwner']??false)
                        ||!($idle['noUnknownOutcome']??false)||($idle['uartDescriptorCount']??0)!==1
                        ||($idle['session']??'')!==$context['session']||($idle['binding']??'')!==$context['transportBinding']
                        ||($idle['communicationFaultEpoch']??-1)!==$context['faultEpoch']
                        ||$h->verifyActive()!==$context['handoffBinding'])throw new RuntimeException('Prewrite arbiter context is not fresh, exclusive and idle.');
                    $this->productMessage('Prewrite-Prüfung bestanden. Hardwarebarriere aktiv: kein Write, kein Write-Intent, kein Schreibzyklus verbraucht.');
                }elseif(($st['phase']??'')==='FAULT_LATCHED')$this->productMessage('Kommunikationsfehler gelatcht. Wartung zurückgeben und neu starten.');
            });
            // Outside coordinator lock: parent can synchronously call ReceiveData.
            if($request!==null){
                $result=json_decode($this->SendDataToParent(json_encode([
                    'DataID'=>self::MAINTENANCE_REQUEST_DATA_ID,'OwnerInstanceID'=>$this->InstanceID,
                    'Token'=>$request['token'],'Operation'=>$request['operation'],
                    'FrameHex'=>ESP3Codec::toHex(ESP3Codec::buildReadRequest($request['operation'])),
                    'TimeoutMs'=>min(5000,max(100,$this->ReadPropertyInteger('ReadTimeoutMs'))),
                    'ExpectedSession'=>$this->c2Session()->state()['context']['session'],
                ],JSON_THROW_ON_ERROR)),true);
                if(!($result['accepted']??false))$this->c2Fail('Arbiter rejected read');
            }
        }catch(Throwable $e){$this->c2Fail($e->getMessage());$this->SetTimerInterval('C2Timer',0);}
    }
    private function c2PublishSnapshot(array $s,\EnOceanGatewayManager\Maintenance\C2Handoff $h): void
    {
        $old=json_decode($this->ReadAttributeString('C2LastKnown'),true)?:[];
        $current=$s['snapshot'];
        if($this->GetBuffer('C2PublishedSession')!==$s['id']){
            $this->WriteAttributeString('C2PreviousKnown',json_encode($old,JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('C2LastKnown',json_encode($current,JSON_THROW_ON_ERROR));
            $this->SetBuffer('C2PublishedSession',$s['id']);
        }
        $obs=[];
        foreach($s['initial']as$row)$obs[$row['operation']]=[
            'readAt'=>gmdate('c',(int)$row['readAt']),'parentInstanceID'=>(string)$h->state()['ownArbiter'],
            'session'=>$s['context']['session'],'binding'=>$s['context']['transportBinding'],
            'capability'=>'SUPPORTED_READ','values'=>$row['value']];
        $this->c2WriteChanged('ReadObservations',json_encode($obs,JSON_THROW_ON_ERROR));
        foreach($obs as$op=>$r)$this->updateInformationDisplay($op,$r['values']);
        $b=$current['idbase'];$this->setDisplayValue('HardwareBaseID',$b['baseIdRawHex']);
        $this->setDisplayValue('RemainingWriteCycles',$b['remainingWriteCyclesMode']==='unlimited'?'Unbegrenzt':(string)($b['remainingWriteCycles']??'Nicht verfügbar'));
        $this->c2WriteChanged('BaseIDReadAt',$obs['CO_RD_IDBASE']['readAt']);
        $this->c2WriteChanged('BaseIDReadParent',(string)$h->state()['ownArbiter']);
        if($this->GetBuffer('C2InventorySession')!==$s['id']){
            if(!$this->InitializeProductInventory()||!$this->productObserve())throw new RuntimeException('Lokales Inventar nicht sicher synchronisiert.');
            $this->SetBuffer('C2InventorySession',$s['id']);
        }
    }
    public function ReviewNativeTarget(string $target): bool
    {
        try{$target=ESP3Codec::normalizeWritableBaseId($target);}
        catch(Throwable $e){$this->productMessage($e->getMessage(),true);return false;}
        try{return$this->c2Lock(function()use($target):bool{
            $s=$this->c2Session();$summary=$s->review($target,$this->c2Context($this->c2Handoff()),microtime(true));
            if($this->c2InventoryView()['replacement'])throw new RuntimeException('Hardwarewechsel zuerst bewusst zuordnen; Master bleibt erhalten.');
            $this->c2SaveSession($s);$this->WriteAttributeString('C2Review',json_encode($summary,JSON_THROW_ON_ERROR));
            $this->productMessage('Ziel prüfen und beide Bestätigungsstufen bewusst bestätigen. Hardware bleibt gesperrt.',true);return true;
        });}catch(Throwable $e){$this->c2Fail($e->getMessage());return false;}
    }
    public function ConfirmNativeTargetA(string $token): bool
    {
        try{return$this->c2Lock(function()use($token):bool{
            $s=$this->c2Session();if(!$s->checkContext($this->c2Context($this->c2Handoff()),microtime(true)))throw new RuntimeException('Context changed.');
            $s->confirmA($token,microtime(true));$this->c2SaveSession($s);$this->ReloadForm();return true;
        });}catch(Throwable $e){$this->c2Fail($e->getMessage());return false;}
    }
    public function ConfirmNativeTargetB(string $token,string $target): bool
    {
        try{return$this->c2Lock(function()use($token,$target):bool{
            $s=$this->c2Session();$s->confirmB($token,$target,$this->c2Context($this->c2Handoff()),microtime(true));
            $this->c2SaveSession($s);$this->productMessage('Frische Prewrite-Verifikation läuft. Kein Hardware-Write freigegeben.',true);return true;
        });}catch(Throwable $e){$this->c2Fail($e->getMessage());return false;}
    }
    public function ReturnNativeMaintenance(): bool
    {
        try{return$this->c2Lock(function():bool{
            $h=$this->c2Handoff();$hs=$h->state();if($hs===[]||$hs['phase']==='RESTORED')return true;
            $s=$this->c2Session();$s->returning();$this->c2SaveSession($s);
            // Cursor is acquired BEFORE native reconnect. No pre-return debug can prove refresh.
            $messages=json_decode(IPS_GetSnapshotChanges(0),true,512,JSON_THROW_ON_ERROR);
            $cursor=$messages===[]?null:max(array_column($messages,'TimeStamp'));
            $expected=$s->state()['snapshot']['idbase']??null;
            if($cursor!==null&&$expected!==null){
                $counter=$expected['remainingWriteCyclesMode']==='unlimited'?255:$expected['remainingWriteCycles'];
                $v=new \EnOceanGatewayManager\Maintenance\NativeRefreshVerifier($hs['snapshot']['nativeID'],$hs['snapshot']['ioID'],
                    $cursor,$expected['baseIdRawHex'],$counter,microtime(true));
                $this->WriteAttributeString('C2NativeRefresh',json_encode($v->state(),JSON_THROW_ON_ERROR));
                IPS_EnableDebug($hs['snapshot']['nativeID'],180);
            }else{$this->WriteAttributeString('C2NativeRefresh','[]');}
            $h->restore(microtime(true));$this->c2SaveHandoff($h);$this->SetBuffer('C2RuntimeStarted','1');
            $this->SetTimerInterval('C2Timer',100);$this->productMessage('Wartung beendet; Verbindung wird sicher zurückgegeben.',true);return true;
        });}catch(Throwable $e){$this->c2Fail('Rückgabe benötigt Prüfung: '.$e->getMessage());return false;}
    }
    private function c2ObserveNativeRefresh(\EnOceanGatewayManager\Maintenance\C2Handoff $h,float $now): void
    {
        $r=json_decode($this->ReadAttributeString('C2NativeRefresh'),true)?:[];
        $hs=$h->state();$n=$hs['snapshot'];
        $e=$this->c2Environment();
        if($e->configuration($n['nativeID'])!==$n['nativeConfiguration']||$e->configuration($n['ioID'])!==$n['ioConfiguration']
            ||($e->instance($n['nativeID'])['ConnectionID']??-1)!==$n['ioID']||($e->instance($n['ioID'])['InstanceStatus']??0)!==102){
            throw new RuntimeException('Returned native context changed; normal operation not proven.');
        }
        $fds=$e->descriptors($n['ioConfiguration']['Port']);
        if($fds===null||count($fds)!==1||$fds[0]['pid']!==$e->selfPID())throw new RuntimeException('Native UART ownership not proven after return.');
        if($r===[]){$this->productMessage('Konfiguration wiederhergestellt; nativer Base-ID-Refresh NICHT nachgewiesen. Bitte native Gatewayverbindung prüfen.',true);$this->SetTimerInterval('C2Timer',0);return;}
        $v=new \EnOceanGatewayManager\Maintenance\NativeRefreshVerifier($r['native'],$r['io'],$r['cursor'],$r['base'],$r['counter'],$r['startedAt'],$r);
        $messages=json_decode(IPS_GetSnapshotChanges($r['cursor']),true,512,JSON_THROW_ON_ERROR);
        $status=$v->consume($messages,$now);$this->WriteAttributeString('C2NativeRefresh',json_encode($v->state(),JSON_THROW_ON_ERROR));
        if($status==='PENDING'){$this->SetTimerInterval('C2Timer',1000);return;}
        $s=$this->c2Session();$s->returned($status==='OBSERVED_NATIVE_REFRESH');$this->c2SaveSession($s);
        // Let temporary debug forwarding expire; do not disable another user's debug session.
        $this->SetTimerInterval('C2Timer',0);
        $this->productMessage($status==='OBSERVED_NATIVE_REFRESH'?'Normalbetrieb wiederhergestellt: frischer nativer Base-ID-Read und RESULT beobachtet.':
            'Konfiguration wiederhergestellt; nativer Base-ID-Refresh NICHT nachgewiesen: '.$v->state()['reason'],true);
    }
    public function GetNativeMaintenanceSnapshot(): string
    {
        $rows=$this->c2Resolver()->discover(IPS_GetInstanceListByModuleID(\EnOceanGatewayManager\Maintenance\NativeGatewayResolver::NATIVE),IPS_GetName(...));
        try{$inventory=$this->c2InventoryView();}catch(Throwable$e){$inventory=['error'=>$e->getMessage(),'gateway'=>['master'=>null],'history'=>[],'replacement'=>false];}
        $fresh=false;$hstate=json_decode($this->ReadAttributeString('C2Handoff'),true)?:[];
        if(($hstate['phase']??'')==='ACTIVE'){
            try{$s=$this->c2Session();$fresh=$s->verifiedSnapshot()&&$s->checkContext($this->c2Context($this->c2Handoff()),microtime(true));}
            catch(Throwable){} // display only, never infer a safe fallback
        }
        return json_encode(['gateways'=>$rows,'selectedReference'=>$this->ReadPropertyInteger('NativeGatewayInstanceID'),
            'fresh'=>$fresh,'inventory'=>$inventory,
            'handoff'=>json_decode($this->ReadAttributeString('C2Handoff'),true), 'session'=>$this->c2Session()->state(),
            'lastKnown'=>json_decode($this->ReadAttributeString('C2LastKnown'),true),'previousKnown'=>json_decode($this->ReadAttributeString('C2PreviousKnown'),true),
            'nativeRefresh'=>json_decode($this->ReadAttributeString('C2NativeRefresh'),true),
            'message'=>$this->ReadAttributeString('ProductMessage'),'hardwareWriteBlocked'=>true],JSON_THROW_ON_ERROR);
    }
    private function c2Form(): string
    {
        $v=json_decode($this->GetNativeMaintenanceSnapshot(),true);$s=$v['session'];$phase=$s['phase']??($v['handoff']['phase']??'IDLE');$opts=[['caption'=>'Vorhandenes EnOcean-Gateway auswählen','value'=>0]];
        foreach($v['gateways']as$g)$opts[]=['caption'=>$g['name'].' · '.$g['reason'],'value'=>$g['id']];
        $actions=[['type'=>'Label','caption'=>$this->ReadAttributeString('ProductMessage')],
            ['type'=>'Label','caption'=>'Zustand: '.($phase==='MAINTENANCE_READY'&&!$v['fresh']?'STALE_OR_UNSAFE':$phase)],
            ['type'=>'Button','caption'=>'Gateway prüfen / Wartung starten','enabled'=>in_array($phase,['IDLE','RETURNED','RETURN_WARNING'],true)
                ||($phase==='FAULT_LATCHED'&&in_array($v['handoff']['phase']??'IDLE',['IDLE','RESTORED'],true)),
                'onClick'=>'EGMM_StartNativeMaintenance($id);'],
            ['type'=>'Button','caption'=>'Verbindung sicher an IP-Symcon zurückgeben','enabled'=>!in_array($phase,['IDLE','RETURNED','RETURN_WARNING'],true),
                'onClick'=>'EGMM_ReturnNativeMaintenance($id);']];
        $local=in_array($phase,['IDLE','MAINTENANCE_READY','RETURNED','RETURN_WARNING'],true);
        $fresh=$phase==='MAINTENANCE_READY'&&$v['fresh'];$inventory=$v['inventory'];
        $actions[]=['type'=>'Label','caption'=>'Master (nur lokal): '.($inventory['gateway']['master']??'Noch nicht festgelegt')];
        if(isset($inventory['error']))$actions[]=['type'=>'Label','caption'=>'Inventarfehler; Ziele gesperrt: '.$inventory['error']];
        $actions[]=['type'=>'ValidationTextBox','name'=>'C2MasterEntry','caption'=>'Master Base-ID lokal (8 Hexzeichen)'];
        $actions[]=['type'=>'Button','caption'=>'Eingabe bewusst als lokalen Master speichern','enabled'=>$local,'onClick'=>'EGMM_SetNativeMasterBaseID($id, $C2MasterEntry, "manual", true);'];
        $actions[]=['type'=>'Button','caption'=>'Frisch gelesene Base-ID als Master speichern','enabled'=>$fresh,'onClick'=>'EGMM_SetNativeMasterBaseID($id, "", "hardware", true);'];
        $choices=[['caption'=>'Historischen Wert auswählen (kein Hardwarebeweis)','value'=>'']];
        foreach($inventory['history']as$row)$choices[]=['caption'=>$row['baseID'].' · '.($row['written']?'verifizierter Write':($row['observed']?'gelesen':'nur lokal')).' · '.$row['lastSeen'],'value'=>$row['baseID']];
        $actions[]=['type'=>'Select','name'=>'C2HistoryChoice','caption'=>'Historie dieses logischen Gateways','options'=>$choices];
        $actions[]=['type'=>'Button','caption'=>'History-Wert bewusst als Master speichern','enabled'=>$local,'onClick'=>'EGMM_SetNativeMasterBaseID($id, $C2HistoryChoice, "history", true);'];
        $actions[]=['type'=>'Button','caption'=>'History-Wert als Ziel prüfen','enabled'=>$fresh,'onClick'=>'EGMM_ReviewNativeStoredTarget($id, "history", $C2HistoryChoice);'];
        if(($inventory['gateway']['master']??null)!==null)$actions[]=['type'=>'Button','caption'=>'Master als Ziel prüfen','enabled'=>$fresh,'onClick'=>'EGMM_ReviewNativeStoredTarget($id, "master", '.json_encode($inventory['gateway']['master']).');'];
        if($inventory['replacement'])$actions[]=['type'=>'Button','caption'=>'Erkannten Hardwarewechsel bewusst zuordnen und neue Sicherung anlegen','enabled'=>$fresh,'onClick'=>'EGMM_AcceptNativeReplacement($id);'];
        if(($s['snapshot']??null)!==null){$current=$s['snapshot'];
            $actions[]=['type'=>'Label','caption'=>$v['fresh']?'Frisch verifiziert innerhalb dieser Wartungssitzung:':'Nur zuletzt gelesene Daten; NICHT aktuell verifiziert. Neue Wartungsprüfung erforderlich.'];
            foreach($current['version']as$k=>$value)if(is_scalar($value)&&!in_array($k,['returnCode','returnName','optionalDataHex'],true))$actions[]=['type'=>'Label','caption'=>$k.': '.$value];
            $b=$current['idbase'];$actions[]=['type'=>'Label','caption'=>($v['fresh']?'Aktuell erkannt: ':'Historisch gelesen: ').$b['baseIdRawHex'].' · Remaining Writes: '.($b['remainingWriteCyclesMode']==='unlimited'?'unbegrenzt':($b['remainingWriteCycles']??'nicht verfügbar'))];
            $old=$v['previousKnown'];if($old!==[]&&$old!==$current)$actions[]=['type'=>'Label','caption'=>'Hardwareänderung erkannt. Zuletzt bekannt: '.($old['idbase']['baseIdRawHex']??'unbekannt').' / '.($old['version']['eurid']??'unbekannt').'. Alte Daten sind nur Wiederherstellungsziele.'];
            $actions[]=['type'=>'Label','caption'=>'Generation / Funkregion: unbekannt, nicht aus Modellnamen abgeleitet.'];
            $actions[]=['type'=>'Button','caption'=>'Aktuelle Base-ID lokal sichern','enabled'=>$fresh&&!$inventory['replacement'],'onClick'=>'EGMM_SaveCurrentBaseID($id);'];
        }
        $actions[]=['type'=>'Button','caption'=>'Gesicherte Base-ID löschen (nur lokale Sicherung)','onClick'=>'EGMM_DeleteSavedBaseID($id);'];
        $saved=$this->ReadAttributeString('SavedBaseID');
        if($saved!=='')$actions[]=['type'=>'Button','caption'=>'Gesicherte Base-ID '.$saved.' als Ziel prüfen','enabled'=>$fresh,'onClick'=>'EGMM_ReviewNativeStoredTarget($id, "saved", '.json_encode($saved).');'];
        $actions[]=['type'=>'ValidationTextBox','name'=>'ManualBaseID','caption'=>'Ziel-Base-ID (manuell, 8 Hexzeichen)'];
        $actions[]=['type'=>'Button','caption'=>'Manuelles Ziel prüfen','enabled'=>$fresh,'onClick'=>'EGMM_ReviewNativeTarget($id, $ManualBaseID);'];
        $r=json_decode($this->ReadAttributeString('C2Review'),true)?:[];
        if($r!==[]){$actions[]=['type'=>'Label','caption'=>'Aktuell '.$r['current'].' → Ziel '.$r['target'].' · Schreibvorgänge '.$r['remaining'].' → '.$r['expectedRemaining'].' (direkt gelesen; Hardware-Write gesperrt)'];
            $actions[]=['type'=>'Button','caption'=>'Stufe A: Änderung bewusst bestätigen','enabled'=>$phase==='REVIEW_A','onClick'=>'EGMM_ConfirmNativeTargetA($id, '.json_encode($r['token']).');'];
            $actions[]=['type'=>'Button','caption'=>'Stufe B: frische Prewrite-Prüfung starten','enabled'=>$phase==='REVIEW_B','onClick'=>'EGMM_ConfirmNativeTargetB($id, '.json_encode($r['token']).', '.json_encode($r['target']).');'];}
        $actions[]=['type'=>'Label','caption'=>'In diesem Build keine realen Hardware-Writes. Base-ID-Limits sind nicht zurücksetzbar; andere Funktionen werden nicht pauschal daraus abgeleitet.'];
        return json_encode(['elements'=>[['type'=>'Select','name'=>'NativeGatewayInstanceID','caption'=>'Vorhandenes natives EnOcean-Gateway','options'=>$opts]],'actions'=>$actions,
            'status'=>[['code'=>102,'icon'=>'active','caption'=>'Gatewayauswahl / Wartung'],['code'=>201,'icon'=>'inactive','caption'=>'Keine aktive Wartungsverbindung']]],JSON_THROW_ON_ERROR);
    }
}
