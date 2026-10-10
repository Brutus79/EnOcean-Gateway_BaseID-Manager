<?php
declare(strict_types=1);
require_once __DIR__.'/C2Session.php';
require_once __DIR__.'/C2SymconEnvironment.php';
require_once __DIR__.'/C2InventoryModule.php';
require_once __DIR__.'/C2Presentation.php';
require_once __DIR__.'/C2BlockedGate.php';
require_once __DIR__.'/C2WriteBridge.php';
use EnOceanGatewayManager\Protocol\ESP3Codec;

/** C2 runtime adapter. All reads/writes use the arbiter's existing B6 engine. */
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
    {if($this->ReadAttributeString($name)!==$value){$this->WriteAttributeString($name,$value);$this->c2RequestFormUpdate();}}
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
            $h=$this->c2Handoff();$hs=$h->state();$s=$this->c2Session()->state();
            // A repeated/stale UI start is not a transport fault. Reject it
            // BEFORE begin() or clearing attributes; preserve the live cycle.
            if($hs!==[]&&($hs['phase']??'')!=='RESTORED')return false;
            if($s!==[]&&!in_array($s['phase']??'',['IDLE','RETURNED','RETURN_WARNING'],true))return false;
            $refresh=json_decode($this->ReadAttributeString('C2NativeRefresh'),true)?:[];
            if(($refresh['status']??'')==='PENDING')return false;
            if($this->ReadPropertyInteger('NativeGatewayInstanceID')<=0){
                $this->productMessage('Bitte wählen Sie zuerst ein vorhandenes EnOcean-Gateway aus.',true);return false;
            }
            $h->begin($this->InstanceID,$this->ReadPropertyInteger('NativeGatewayInstanceID'),microtime(true));
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
        $request=null;$write=null;
        try{
            $this->c2Lock(function()use(&$request,&$write):void{
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
                    $restored=$h->finishRestore($now)==='RESTORED';
                    $this->c2SaveHandoff($h);
                    if($restored)$this->c2CompleteReturn($h);
                    return;
                }
                if($phase==='RESTORED'){
                    if(($this->c2Session()->state()['phase']??'')==='RETURNED'
                        &&$this->GetBuffer('C2NativeRestoredDisplay')===$h->state()['id']){
                        $this->SetTimerInterval('C2Timer',0);return;
                    }
                    $this->c2CompleteReturn($h);return;
                }
                if($phase!=='ACTIVE')return;
                if($this->c2ObserveWrite($h,$now))return;
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
                    if($this->GetBuffer('C2ReadyMessageSession')!==$st['id']){
                        $this->SetBuffer('C2ReadyMessageSession',$st['id']);
                        $this->productMessage('Maintenance bereit. Aktuelle Hardware frisch und konsistent erkannt. Reale Hardware-Writes bleiben gesperrt.');
                    }
                }elseif(($st['phase']??'')==='WRITE_BLOCKED'){
                    if(!$s->prewriteGate($st['target'],$context,$now)){$this->c2SaveSession($s);$this->productMessage('Prewrite-Nachweis ungültig oder abgelaufen. Kein Write; Wartung sicher zurückgeben.');return;}
                    $idle=json_decode(EGMA_GetReadSafetyContext($h->state()['ownArbiter']),true,512,JSON_THROW_ON_ERROR);
                    $blocked=\EnOceanGatewayManager\Maintenance\C2BlockedGate::observe($idle,$context,$h->verifyActive(),$st['pending']??null);
                    if($blocked==='INCOMING_BUSY'){
                        $this->productMessage('Prewrite-Nachweis bleibt gültig. Empfang läuft: momentan nicht sendbar. Hardwarebarriere aktiv; kein Write.');return;
                    }
                    $key=hash('sha256',$st['id'].':'.$st['confirmation']);
                    if($this->GetBuffer('C2WriteStarted')!==$key){
                        $this->SetBuffer('C2WriteStarted',$key);
                        $write=['DataID'=>self::MAINTENANCE_REQUEST_DATA_ID,'OwnerInstanceID'=>$this->InstanceID,
                            'Operation'=>'B6_C2_BEGIN','Target'=>$st['target']];
                    }
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
            if($write!==null){
                $result=json_decode($this->SendDataToParent(json_encode($write,JSON_THROW_ON_ERROR)),true,512,JSON_THROW_ON_ERROR);
                if(!($result['accepted']??false))$this->c2Fail('C2 write preparation rejected: '.($result['reason']??'unknown'));
            }
        }catch(Throwable $e){$this->c2Fail($e->getMessage());$this->SetTimerInterval('C2Timer',0);}
    }
    /** Read-only metadata callback under the arbiter lock. No coordinator/arbiter
     * lock acquisition here: avoids a manager -> arbiter -> manager deadlock. */
    public function GetC2WriteProof(): string
    {
        $h=$this->c2Handoff();
        if(!$this->activeGatewayTransport())throw new RuntimeException('C2 manager transport changed.');
        return json_encode(['session'=>$this->c2Session()->state(),'handoff'=>$h->state(),
            'selectedReference'=>$this->ReadPropertyInteger('NativeGatewayInstanceID'),
            'runtimeStarted'=>$this->GetBuffer('C2RuntimeStarted')==='1','handoffBinding'=>$h->verifyActive()],JSON_THROW_ON_ERROR);
    }
    private function c2ObserveWrite(\EnOceanGatewayManager\Maintenance\C2Handoff $h,float $now): bool
    {
        $v=json_decode(EGMA_GetWriteTransactionView($h->state()['ownArbiter']),true,512,JSON_THROW_ON_ERROR);
        if(!isset($v['c2Authority']))return false;
        $s=$this->c2Session();$st=$s->state();$a=$v['c2Authority'];$phase=$v['state'];
        if(($a['sessionID']??'')!==($st['id']??''))return false; // Previous, explicitly cancelled selection.
        if(($st['phase']??'')==='MAINTENANCE_READY')return false; // Completed/postverified transaction.
        if(($v['sendAttempts']??0)===0&&($v['stateClassification']['terminal']??false)
            &&hash('sha256',(string)($st['confirmation']??''))!==$a['confirmationHash'])return false;
        if(($st['faults']??[])!==[]||$a['nativeID']!==$this->ReadPropertyInteger('NativeGatewayInstanceID')
            ||$a['handoffID']!==$h->state()['id']||$a['target']!==($st['target']??null))throw new RuntimeException('C2 write context changed.');
        $arbiter=$h->state()['ownArbiter'];
        if(($v['sendAttempts']??0)===0){
            $context=json_decode(EGMA_GetReadSafetyContext($arbiter),true,512,JSON_THROW_ON_ERROR);
            if(\EnOceanGatewayManager\Maintenance\C2WriteBridge::validate(json_decode($this->GetC2WriteProof(),true),$context,$now)!==$a)
                throw new RuntimeException('C2 write authorization changed.');
            if(in_array($phase,['CANCELLED','NO_OP','UNKNOWN_OUTCOME'],true))throw new RuntimeException($v['reason']??'B6 preparation stopped.');
            $this->productMessage($v['finalGateBlocked']??false
                ?'Prewrite und finale Live-Gates bestanden. Am einzigen Sendepunkt durch Hardwarebarriere gesperrt; kein Write, kein Schreibzyklus verbraucht.'
                :'B6 prüft Identität, Base-ID und Counter unmittelbar vor dem Sendepunkt. Hardwarebarriere bleibt aktiv.');
            return true;
        }
        if($phase==='UNKNOWN_OUTCOME'){
            if(!($v['recovery']??false)&&!($v['permanentFailure']??false)){
                $r=json_decode($this->SendDataToParent(json_encode(['DataID'=>self::MAINTENANCE_REQUEST_DATA_ID,
                    'OwnerInstanceID'=>$this->InstanceID,'Operation'=>'B6_RECOVER','TransactionID'=>$v['transactionID']],JSON_THROW_ON_ERROR)),true);
                if(!($r['accepted']??false))throw new RuntimeException('Read-only write recovery rejected.');
                $this->productMessage('Schreibausgang unklar. Ausschließlich Wiederverbindung und frische Read-only-Verifikation; kein Retry.');
            }else{$this->c2Fail('Write outcome UNKNOWN; no retry.');}
            return true;
        }
        if($phase==='FORCE_RECONNECT'){
            if($this->GetBuffer('C2VerificationReopened')===$v['transactionID']){
                EGMA_ProcessTimeouts($arbiter);
                if($now-(float)$this->GetBuffer('C2VerificationReopenAt')>3)throw new RuntimeException('Post-write new-session activation not observed.');
                return true;
            }
            if(!($h->state()['verificationClose']??false)){
                $before=json_decode(EGMA_GetReadSafetyContext($arbiter),true);
                if(($before['communicationFaultEpoch']??-1)!==$st['context']['faultEpoch'])throw new RuntimeException('Unexpected communication fault before owned verification close.');
                $h->closeForVerification();$this->c2SaveHandoff($h);return true;
            }
            // Make B6 observe the real closed parent before any reopening.
            EGMA_ProcessTimeouts($arbiter);
            $off=json_decode(EGMA_GetReadSafetyContext($arbiter),true);
            $after=json_decode(EGMA_GetWriteTransactionView($arbiter),true);
            if($h->reopenForVerification(!($off['realConnectionActive']??true)&&($after['sawDisconnect']??false))){
                $this->SetBuffer('C2VerificationReopened',$v['transactionID']);$this->SetBuffer('C2VerificationReopenAt',(string)$now);
            }elseif($now-($h->state()['verificationCloseAt']??$now)>3)throw new RuntimeException('Owned post-write UART disconnect not proven.');
            $this->c2SaveHandoff($h);return true;
        }
        $h->verifyActive();
        if(in_array($phase,['VERIFIED','RECOVERY_NOT_APPLIED'],true)){
            $c=$this->c2Context($h);
            $live=json_decode(EGMA_GetReadSafetyContext($arbiter),true);
            // Keep the warning/history latched. Exactly one deliberately observed
            // owned disconnect belongs to the NEW post-write session; any extra
            // warning still blocks. Never clear/reset a fault epoch.
            if($this->GetBuffer('C2VerificationReopened')!==$v['transactionID']
                ||$c['faultEpoch']!==$st['context']['faultEpoch']+1
                ||($live['lastCommunicationFault']['reason']??'')!=='transport_disconnected')
                throw new RuntimeException('Unexpected communication warning remains latched after write.');
            $post=new \EnOceanGatewayManager\Maintenance\C2Session();
            $pairs=$v['postPairs']??[];if(count($pairs)!==5)throw new RuntimeException('Five postverification pairs missing.');
            $post->start($c,$pairs[0]['CO_RD_VERSION']['at']);
            foreach($pairs as$pair)foreach(['CO_RD_VERSION','CO_RD_IDBASE']as$op){
                $row=$pair[$op];$r=$post->request($c,$row['at']);
                if($r===null||!$post->response($r['token'],$op,ESP3Codec::fromHex($row['frameHex']),$c,$row['at']))throw new RuntimeException('Postverification C2 proof invalid.');
            }
            $this->c2SaveSession($post);$this->c2PublishSnapshot($post->state(),$h);
            $this->productMessage($phase==='VERIFIED'?'Base-ID und reduzierter Counter nach neuer Session vollständig verifiziert.':'Frisch verifiziert: Write nicht erfolgt; alte Base-ID und Counter unverändert. Kein Retry.',true);return true;
        }
        if(!in_array($phase,['WAITING_FOR_RESPONSE','POST_VERIFY'],true))throw new RuntimeException('Invalid C2 write state: '.$phase);
        $this->productMessage('Schreibvorgang / Read-only-Postverification läuft. Kein erneuter Schreibversuch.');return true;
    }
    private function c2CancelPreparedWrite(bool $returnUnknown=false): void
    {
        $h=$this->c2Handoff()->state();if(($h['phase']??'')!=='ACTIVE'||($h['ownArbiter']??0)<=0)return;
        $v=json_decode(EGMA_GetWriteTransactionView($h['ownArbiter']),true,512,JSON_THROW_ON_ERROR);
        if(!isset($v['c2Authority']))return;
        if(!($v['stateClassification']['active']??false)){
            if($returnUnknown&&($this->c2Session()->state()['faults']??[])!==[])return;
            if(($v['sendAttempts']??0)>0&&$v['c2Authority']['sessionID']===($this->c2Session()->state()['id']??''))
                $this->c2ObserveWrite($this->c2Handoff(),microtime(true)); // Publish postproof before return expectations.
            return;
        }
        if(($v['sendAttempts']??0)>0){
            if($returnUnknown&&$v['state']==='UNKNOWN_OUTCOME')return; // Explicit return, never success/retry.
            if(!$returnUnknown||($this->c2Session()->state()['faults']??[])===[])
                throw new RuntimeException('Write outcome must be reconciled before leaving maintenance.');
            // Explicit safe return after a latched runtime fault cancels to UNKNOWN,
            // not success. The native refresh observer still checks measured state.
        }
        $r=json_decode($this->SendDataToParent(json_encode(['DataID'=>self::MAINTENANCE_REQUEST_DATA_ID,
            'OwnerInstanceID'=>$this->InstanceID,'Operation'=>'B6_CANCEL','TransactionID'=>$v['transactionID']],JSON_THROW_ON_ERROR)),true);
        if(!($r['accepted']??false))throw new RuntimeException('Prepared transaction cancellation failed.');
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
            $s->confirmA($token,microtime(true));$this->c2SaveSession($s);$this->c2RequestFormUpdate();return true;
        });}catch(Throwable $e){$this->c2Fail($e->getMessage());return false;}
    }
    public function BackToNativeTargetSelection(): bool
    {
        try{return$this->c2Lock(function():bool{
            $this->c2CancelPreparedWrite();
            $s=$this->c2Session();$s->discardSelection($this->c2Context($this->c2Handoff()),microtime(true));
            $this->c2SaveSession($s);$this->c2WriteChanged('C2Review','[]');
            $this->productMessage('Zieländerung verworfen. Wählen Sie die gewünschte Base-ID; Wartung bleibt aktiv.',true);return true;
        });}catch(Throwable$e){$this->c2Fail($e->getMessage());return false;}
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
            $h=$this->c2Handoff();$hs=$h->state();if($hs===[])return true;
            if($hs['phase']==='RETURN_CLOSING')return true;
            if($hs['phase']==='RESTORED'){
                $this->c2CompleteReturn($h);
                return true;
            }
            $this->c2CancelPreparedWrite(true);
            $s=$this->c2Session();$s->returning();$this->c2SaveSession($s);
            $this->c2WriteChanged('C2NativeRefresh','[]');
            $h->restore(microtime(true));$this->c2SaveHandoff($h);$this->SetBuffer('C2RuntimeStarted','1');
            $this->SetTimerInterval('C2Timer',100);$this->productMessage('Wartung beendet; Verbindung wird sicher zurückgegeben.',true);return true;
        });}catch(Throwable $e){$this->c2Fail('Rückgabe benötigt Prüfung: '.$e->getMessage());return false;}
    }
    /** Complete only the technical return; no native telegram/cache-uptake observer. */
    private function c2CompleteReturn(\EnOceanGatewayManager\Maintenance\C2Handoff $h): void
    {
        $hs=$h->state();$n=$hs['snapshot'];
        $e=$this->c2Environment();
        if($e->configuration($n['nativeID'])!==$n['nativeConfiguration']||$e->configuration($n['ioID'])!==$n['ioConfiguration']
            ||($e->instance($n['nativeID'])['ConnectionID']??-1)!==$n['ioID']||($e->instance($n['ioID'])['InstanceStatus']??0)!==102){
            throw new RuntimeException('Returned native context changed; normal operation not proven.');
        }
        $fds=$e->descriptors($n['ioConfiguration']['Port']);
        if($fds===null||count($fds)!==1||$fds[0]['pid']!==$e->selfPID())throw new RuntimeException('Native UART ownership not proven after return.');
        // Presentation only: mark physical return AFTER the existing checks.
        // Bound to this handoff; never used as refresh proof or authorization.
        if($this->GetBuffer('C2NativeRestoredDisplay')!==$h->state()['id']){
            $this->SetBuffer('C2NativeRestoredDisplay',$h->state()['id']);$this->c2RequestFormUpdate();
        }
        $this->c2WriteChanged('C2NativeRefresh','[]');
        $s=$this->c2Session();$s->returning();$s->returned(true);$this->c2SaveSession($s);
        $this->SetTimerInterval('C2Timer',0);
        $this->productMessage('Wartung beendet. Native Verbindung und UART-Ownership wiederhergestellt; kein nachgelagerter Base-ID-Refresh-Nachweis.',true);
    }
    public function GetNativeMaintenanceSnapshot(): string
    {
        $rows=$this->c2Resolver()->references(IPS_GetInstanceListByModuleID(\EnOceanGatewayManager\Maintenance\NativeGatewayResolver::NATIVE),IPS_GetName(...));
        try{$inventory=$this->c2InventoryView();}catch(Throwable$e){$inventory=['error'=>$e->getMessage(),'gateway'=>['master'=>null],'history'=>[],'replacement'=>false];}
        $fresh=false;$hstate=json_decode($this->ReadAttributeString('C2Handoff'),true)?:[];
        $write=[];
        if(($hstate['phase']??'')==='ACTIVE'){
            try{$write=json_decode(EGMA_GetWriteTransactionView($hstate['ownArbiter']),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){}
            try{$s=$this->c2Session();$fresh=$s->verifiedSnapshot()&&$s->checkContext($this->c2Context($this->c2Handoff()),microtime(true));}
            catch(Throwable){} // display only, never infer a safe fallback
        }
        return json_encode(['gateways'=>$rows,'selectedReference'=>$this->ReadPropertyInteger('NativeGatewayInstanceID'),
            'fresh'=>$fresh,'inventory'=>$inventory,
            'nativeRestored'=>($hstate['phase']??'')==='RESTORED'&&$this->GetBuffer('C2NativeRestoredDisplay')===($hstate['id']??null),
            'nativeContextValid'=>$this->c2NativeContextValid($hstate),
            'handoff'=>json_decode($this->ReadAttributeString('C2Handoff'),true), 'session'=>$this->c2Session()->state(),
            'lastKnown'=>json_decode($this->ReadAttributeString('C2LastKnown'),true),'previousKnown'=>json_decode($this->ReadAttributeString('C2PreviousKnown'),true),
            'nativeRefresh'=>json_decode($this->ReadAttributeString('C2NativeRefresh'),true),
            'message'=>$this->ReadAttributeString('ProductMessage'),'writeTransaction'=>$write,
            'hardwareWriteBlocked'=>$write['hardwareWriteBarrier']??true],JSON_THROW_ON_ERROR);
    }
    private function c2FormModel(): array
    {
        $view=json_decode($this->GetNativeMaintenanceSnapshot(),true,512,JSON_THROW_ON_ERROR);
        $proof=json_decode((string)$this->GetBuffer('C2SelectionValidation'),true)?:[];
        $source=$this->GetBuffer('C2TargetSource')?:'manual';
        $input=match($source){
            'manual'=>$this->GetBuffer('C2SelectedManual')?:'',
            'history'=>$this->GetBuffer('C2SelectedHistory')?:'',
            'master'=>$view['inventory']['gateway']['master']??'',
        };
        $view['selectionValidation']=($proof['valid']??false)&&($proof['raw']??null)!==$input?[]:$proof;
        return \EnOceanGatewayManager\Product\C2Presentation::form(
            $view,
            $this->ReadAttributeString('SavedBaseID'),
            json_decode($this->ReadAttributeString('C2Review'),true)?:[],
            $this->GetBuffer('C2TargetSource')?:'manual',
            $this->GetBuffer('C2MasterSource')?:'manual');
    }
    private function c2RequestFormUpdate(): void { $this->SetBuffer('C2FormDirty','1'); }
    public function ProcessNativeFormUpdates(): void
    {
        if($this->ReadPropertyInteger('NativeGatewayInstanceID')<=0||$this->GetBuffer('C2FormDirty')!=='1')return;
        $this->SetBuffer('C2FormDirty','');
        $old=json_decode((string)$this->GetBuffer('C2FormFields'),true);
        if(!is_array($old))return; // No configuration form opened yet.
        try{
            $fields=\EnOceanGatewayManager\Product\C2Presentation::fields($this->c2FormModel());
            foreach($fields as$name=>$values)foreach($values as$key=>$value){
                if(array_key_exists($key,$old[$name]??[])&&$old[$name][$key]===$value)continue;
                if($this->UpdateFormField($name,$key,is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):$value)){
                    $old[$name][$key]=$value;
                }
            }
            $this->SetBuffer('C2FormFields',json_encode($old,JSON_THROW_ON_ERROR));
        }catch(Throwable$e){$this->SendDebug('C2 form update',$e->getMessage(),0);}
    }
    public function SelectNativeTargetSource(string $source): void
    {
        if(!in_array($source,['manual','master','history'],true))return;
        $this->SetBuffer('C2TargetSource',$source);
        $this->InvalidateNativeBaseIDSelection($source,$this->GetBuffer('C2SelectedManual')?:'',$this->GetBuffer('C2SelectedHistory')?:'');
    }
    /** Form-local validation only. Never queries or authorizes the hardware. */
    public function InvalidateNativeBaseIDSelection(string $source,string $manual,string $history): void
    {
        $this->SetBuffer('C2SelectedManual',$manual);$this->SetBuffer('C2SelectedHistory',$history);
        $this->SetBuffer('C2SelectionValidation','[]');$this->c2RequestFormUpdate();
        $this->ProcessNativeFormUpdates();
    }
    private function c2SelectedBaseID(string $source,string $manual,string $history): string
    {
        if($source==='manual')return $manual;
        $v=$this->c2InventoryView();
        if($source==='master')return $v['gateway']['master']??'';
        if($source==='history'&&in_array($history,array_column($v['history'],'baseID'),true))return $history;
        throw new RuntimeException('Die Base-ID gehört nicht zur ausgewählten Quelle.');
    }
    public function ValidateNativeSelectedBaseID(string $source,string $manual,string $history): bool
    {
        $this->SetBuffer('C2SelectionValidation','[]');
        $this->SetBuffer('C2SelectedManual',$manual);$this->SetBuffer('C2SelectedHistory',$history);
        try{
            if(!in_array($source,['manual','history','master'],true))throw new RuntimeException('Unbekannte Base-ID-Quelle.');
            $this->SetBuffer('C2TargetSource',$source);
            $raw=$this->c2SelectedBaseID($source,$manual,$history);
            $base=ESP3Codec::normalizeWritableBaseId($raw);
            $proof=['valid'=>true,'source'=>$source,'raw'=>$raw,'base'=>$base,
                'gateway'=>$this->ReadPropertyInteger('NativeGatewayInstanceID'),'message'=>'Base-ID '.$base.' ist gültig.'];
        }catch(Throwable $e){
            $reason=match(true){
                str_contains($e->getMessage(),'INVALID_BASE_ID_ALIGNMENT')=>'Die Base-ID muss auf einen 128-Adressen-Block ausgerichtet sein (Endung 00 oder 80).',
                $e->getMessage()==='Base ID must contain exactly eight hexadecimal digits.'=>'Die Base-ID muss genau acht Hexzeichen enthalten.',
                $e->getMessage()==='Base ID is outside the ESP3 writable range.'=>'Die Base-ID liegt außerhalb des zulässigen ESP3-Wertebereichs.',
                default=>$e->getMessage(),
            };
            $proof=['valid'=>false,'message'=>'Base-ID ungültig: '.$reason];
        }
        $this->SetBuffer('C2SelectionValidation',json_encode($proof,JSON_THROW_ON_ERROR));
        $this->c2RequestFormUpdate();$this->ProcessNativeFormUpdates();return $proof['valid'];
    }
    private function c2RequireSelectedBaseIDValidation(string $source,string $manual,string $history): void
    {
        $proof=json_decode((string)$this->GetBuffer('C2SelectionValidation'),true)?:[];
        $raw=$this->c2SelectedBaseID($source,$manual,$history);
        if(!($proof['valid']??false)||($proof['source']??null)!==$source||($proof['raw']??null)!==$raw
            ||($proof['gateway']??null)!==$this->ReadPropertyInteger('NativeGatewayInstanceID')
            ||($proof['base']??null)!==ESP3Codec::normalizeWritableBaseId($raw)){
            $this->InvalidateNativeBaseIDSelection($source,$manual,$history);
            throw new RuntimeException('Bitte zuerst exakt diese Auswahl über BASE-ID PRÜFEN erfolgreich prüfen.');
        }
    }
    public function ReviewNativeSelectedTarget(string $source,string $manual,string $history): bool
    {
        try{
            $this->c2RequireSelectedBaseIDValidation($source,$manual,$history);
            if($source==='manual')return$this->ReviewNativeTarget($manual);
            $base=match($source){
                'master'=>$this->c2InventoryView()['gateway']['master']??'',
                'history'=>$history,
                default=>throw new RuntimeException('Unbekannte Zielquelle.'),
            };
            return$this->ReviewNativeStoredTarget($source,$base);
        }catch(Throwable$e){$this->productMessage($e->getMessage(),true);return false;}
    }
    public function SelectNativeMasterSource(string $source): void
    {
        if(!in_array($source,['manual','history'],true))return;
        $this->SetBuffer('C2MasterSource',$source);$this->c2RequestFormUpdate();
    }
    public function SaveSelectedNativeMaster(string $source,string $manual,string $history): bool
    {
        try{
            if(!in_array($source,['manual','history'],true))return false;
            $this->c2RequireSelectedBaseIDValidation($source,$manual,$history);
            return$this->SetNativeMasterBaseID($source==='manual'?$manual:$history,$source,true);
        }catch(Throwable $e){$this->productMessage($e->getMessage(),true);return false;}
    }
    private function c2Form(): string
    {
        // Reopening the form restores its persisted input, not a previous editor value.
        $this->SetBuffer('C2SelectionValidation','[]');
        $form=$this->c2FormModel();
        $this->SetBuffer('C2FormFields',json_encode(\EnOceanGatewayManager\Product\C2Presentation::fields($form),JSON_THROW_ON_ERROR));
        $this->SetBuffer('C2FormDirty','');
        return json_encode($form,JSON_THROW_ON_ERROR);
    }
}
