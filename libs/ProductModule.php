<?php
declare(strict_types=1);
require_once __DIR__.'/InventoryStore.php';
require_once __DIR__.'/GatewayDiscovery.php';
require_once __DIR__.'/ProductPresentation.php';
require_once __DIR__.'/MasterTargetBinding.php';

trait GatewayProductModule
{
    // B8.2 checks applied targets with reads only. No live B6_BEGIN/WAL intent.
    private const PRODUCT_TARGET_ONLY = true;
    private function targetBindingContext(): array
    {
        $context=$this->readSafetyContext();$context['exclusiveChain']=$this->activeGatewayTransport();return $context;
    }
    private function targetBackup(): array
    {
        $backup=json_decode($this->ReadAttributeString('SavedBaseIDMetadata'),true)?:[];
        $backup['baseID']=$this->ReadAttributeString('SavedBaseID');return $backup;
    }
    private function productStore(): \EnOceanGatewayManager\Product\InventoryStore
    {
        return new \EnOceanGatewayManager\Product\InventoryStore(rtrim(IPS_GetKernelDir(),'/').'/egm-product-inventory');
    }
    private function productID(): string
    {
        // Native Create runs before persisted attributes are available on reload.
        // Never generate/write identity there. Durable registry mapping is authoritative.
        $db=$this->productStore()->read();$matches=[];foreach($db['gateways']as$id=>$g)if(($g['managerInstanceID']??null)===$this->InstanceID)$matches[]=$id;
        if(count($matches)>1)throw new RuntimeException('Mehrdeutige logische Gatewayzuordnung. Keine automatische Übernahme.');
        $id=$matches[0]??$this->ReadAttributeString('LogicalGatewayID');
        if($id===''||(isset($db['gateways'][$id]['managerInstanceID'])&&$db['gateways'][$id]['managerInstanceID']!==$this->InstanceID))$id=bin2hex(random_bytes(16));
        if($this->ReadAttributeString('LogicalGatewayID')!==$id)$this->WriteAttributeString('LogicalGatewayID',$id);return $id;
    }
    private function productMessage(string $text): void { if($this->ReadAttributeString('ProductMessage')!==$text){$this->WriteAttributeString('ProductMessage',$text);$this->ReloadForm();} }
    private function productView(): array
    {
        $parent=(int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0);
        return $parent>0&&function_exists('EGMA_GetWriteTransactionView')?(json_decode(EGMA_GetWriteTransactionView($parent),true)?:[]):[];
    }
    private function productReads(): array
    {
        $obs=json_decode($this->ReadAttributeString('ReadObservations'),true)?:[];$context=$this->readSafetyContext();
        foreach(['CO_RD_VERSION','CO_RD_IDBASE']as$op){$r=$obs[$op]??[];$at=strtotime($r['readAt']??'')?:0;
            if(($r['capability']??'')!=='SUPPORTED_READ'||$at>time()||time()-$at>60||($r['session']??'')!==($context['session']??null)||($r['binding']??'')!==($context['binding']??null)
                ||($r['parentInstanceID']??'')!==(string)($context['arbiterID']??0)||!($context['realConnectionActive']??false)||!($context['transportCorrelationSafe']??false))return [];
        }return $obs;
    }
    public function InitializeProductInventory(): bool
    {
        try{
            $id=$this->productID();$name=$this->ReadPropertyString('GatewayName');$metadata=json_decode($this->ReadAttributeString('SavedBaseIDMetadata'),true)?:[];$saved=$this->ReadAttributeString('SavedBaseID');
            $parent=(int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0);$rows=[];
            if($parent>0){require_once __DIR__.'/WriteJournal.php';$dir=rtrim(IPS_GetKernelDir(),'/').'/egm-write-journal-'.$parent;
                if(is_file($dir.'/transactions.ndjson')){$rows=array_values(array_filter((new \EnOceanGatewayManager\Safety\DurableWriteJournal($dir))->records(),fn($r)=>($r['owner']??null)===$this->InstanceID));}}
            $owner=$this->InstanceID;$this->productStore()->update(static function(array &$db)use($id,$name,$metadata,$saved,$rows,$owner):void{
                \EnOceanGatewayManager\Product\GatewayInventory::gateway($db,$id,$name);
                $db['gateways'][$id]['managerInstanceID']=$owner;
                if($saved!==''&&preg_match('/\A[0-9A-F]{8}\z/D',$saved)){
                    $proven=($metadata['source']??'')==='CO_RD_IDBASE'&&isset($metadata['observedEURID'],$metadata['readAt']);
                    \EnOceanGatewayManager\Product\GatewayInventory::event($db,$id,$saved,$proven?'HARDWARE_DETECTED':'IMPORTED_EXISTING_CONFIGURATION',$metadata['readAt']??gmdate('c'),'legacy-saved:'.($metadata['savedAt']??'unknown'),['eurid'=>$metadata['observedEURID']??null,'evidenceType'=>$proven?'LEGACY_READ_BACKUP':'LEGACY_LOCAL_CONFIGURATION']);
                    if($proven&&$db['gateways'][$id]['acceptedEURID']===null)$db['gateways'][$id]['acceptedEURID']=$metadata['observedEURID'];
                }
                \EnOceanGatewayManager\Product\GatewayInventory::importJournal($db,$id,$rows);
            });return true;
        }catch(Throwable $e){$this->productMessage('Inventarfehler: '.$e->getMessage());return false;}
    }
    private function productObserve(): bool
    {
        $obs=$this->productReads();if($obs===[])return false;$id=$this->productID();$v=$obs['CO_RD_VERSION']['values'];$b=$obs['CO_RD_IDBASE']['values'];
        $source='live:'.$obs['CO_RD_IDBASE']['session'].':'.$obs['CO_RD_IDBASE']['readAt'];
        $this->productStore()->update(static function(array &$db)use($id,$v,$b,$obs,$source):void{
                \EnOceanGatewayManager\Product\GatewayInventory::event($db,$id,$b['baseIdRawHex'],'HARDWARE_OBSERVED',$obs['CO_RD_IDBASE']['readAt'],$source,
                ['eurid'=>$v['eurid'],'counterAfter'=>$b['remainingWriteCycles']??null,'evidenceType'=>'FRESH_ARBITER_READ']);
            $db['gateways'][$id]['lastObserved']=['baseID'=>$b['baseIdRawHex'],'EURID'=>$v['eurid'],'at'=>$obs['CO_RD_IDBASE']['readAt'],'counter'=>$b['remainingWriteCycles']??null];
        });return true;
    }
    public function GetProductSnapshot(): string
    {
        $error=false;try{$db=$this->productStore()->read();}catch(Throwable $e){$error=true;$db=\EnOceanGatewayManager\Product\GatewayInventory::empty();}
        $id=$error?($this->ReadAttributeString('LogicalGatewayID')?:'unavailable-'.$this->InstanceID):$this->productID();$g=$db['gateways'][$id]??['logicalID'=>$id,'name'=>$this->ReadPropertyString('GatewayName'),'master'=>null,'acceptedEURID'=>null];
        $obs=$this->productReads();$v=$obs['CO_RD_VERSION']['values']??[];$b=$obs['CO_RD_IDBASE']['values']??[];$context=$this->readSafetyContext();
        $h=['baseID'=>$b['baseIdRawHex']??null,'EURID'=>$v['eurid']??null,'counter'=>($b['remainingWriteCyclesMode']??'')==='unlimited'?'UNLIMITED':($b['remainingWriteCycles']??null),'firmware'=>$v['applicationVersion']??null,'description'=>$v['applicationDescription']??null,'readAt'=>$obs['CO_RD_IDBASE']['readAt']??null];
        $parent=(int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0);$serial=$parent>0?(int)(IPS_GetInstance($parent)['ConnectionID']??0):0;
        $path=$serial>0?(string)IPS_GetProperty($serial,'Port'):'';$view=$this->productView();$flow=json_decode($this->GetBuffer('ProductFlow'),true)?:[];
        $message=$error?'Inventardaten beschädigt. Änderungen gesperrt; vorhandene Datei bleibt erhalten.':$this->ReadAttributeString('ProductMessage');
        if($obs===[]&&$message==='EnOcean Gateway erkannt und frisch geprüft.')$message='Gatewaydaten nicht mehr frisch gebunden. Bitte Gateway erneut prüfen.';
        if($obs===[]&&str_starts_with($message,'Master-ID als Ziel nativ angewandt und frisch geprüft.'))$message='Zielkonfiguration bleibt gespeichert. Hardwareprüfung nicht mehr frisch; vor einem Transfer neu prüfen.';
        if(($view['state']??'')==='UNKNOWN_OUTCOME')$message=\EnOceanGatewayManager\Product\ProductPresentation::outcome('UNKNOWN_OUTCOME');
        $replacement=$h['EURID']!==null&&$g['acceptedEURID']!==null&&$h['EURID']!==$g['acceptedEURID'];
        $snapshot=['gateway'=>$g,'hardware'=>$h,'pending'=>$this->ReadAttributeString('PendingToken')!=='','history'=>array_values(\EnOceanGatewayManager\Product\GatewayInventory::history($db,$id)),
            'inventoryError'=>$error,'transactionState'=>$view['state']??'','fresh'=>$obs!==[],'replacement'=>$replacement,'leaseActive'=>$context['writeLeaseActive']??true,'flow'=>$flow,'registryGateways'=>$db['gateways'],
            'discovery'=>json_decode($this->ReadAttributeString('DiscoveryCandidates'),true)?:[],
            'connectionText'=>$this->activeGatewayTransport()?(($obs!==[]?'Verbunden · ':'Verbindung aktiv; Gateway bitte prüfen · ').($this->ReadPropertyString('ConnectionType')==='usb'?'USB':'Seriell').' · '.$path):'Keine exklusive aktive Gatewayverbindung',
            'configuratorID'=>function_exists('IPS_GetInstanceListByModuleID')?(IPS_GetInstanceListByModuleID('{D7C9E8A3-67D2-4CBE-A85E-4941B50BF891}')[0]??0):0,
            'readEnabled'=>$this->ReadPropertyBoolean('EnableReadActions'),'message'=>$message];
        $snapshot['targetConfiguration']=['BaseIDSource'=>$this->ReadPropertyString('BaseIDSource'),'ManualBaseID'=>$this->ReadPropertyString('ManualBaseID')];
        $snapshot['configuredTarget']=null;
        try{$snapshot['configuredTarget']=\EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId(match($snapshot['targetConfiguration']['BaseIDSource']){'manual'=>$snapshot['targetConfiguration']['ManualBaseID'],'saved'=>$this->ReadAttributeString('SavedBaseID'),default=>''});}catch(Throwable){}
        $snapshot['masterTargetSelection']=null;$snapshot['appliedTarget']=null;$snapshot['targetValidated']=false;
        $snapshot['targetMessage']='Gateway frisch prüfen, bevor die Master-ID als Ziel ausgewählt wird.';
        try{
            $snapshot['masterTargetSelection']=\EnOceanGatewayManager\Product\MasterTargetBinding::selection($snapshot,$this->targetBindingContext(),$this->targetBackup());
            $applied=\EnOceanGatewayManager\Product\MasterTargetBinding::applied($snapshot,$snapshot['targetConfiguration'],$this->targetBindingContext(),$this->targetBackup());
            $snapshot['appliedTarget']=$applied['target'];$snapshot['targetValidated']=true;$snapshot['targetMessage']='Master-ID als Ziel angewandt und frisch geprüft. Hardware-Write bleibt gesperrt.';
        }catch(Throwable $e){
            $snapshot['targetMessage']=$e->getMessage();
            // Missing user configuration is normal, not a malformed Base-ID error.
            // Presentation only: validation and all transport/write guards stay unchanged.
            if(($g['master']??null)===null)$snapshot['targetMessage']='Noch keine Master-ID festgelegt. Eine Master-ID kann bewusst lokal gespeichert werden; das Gateway bleibt unverändert.';
            elseif($snapshot['masterTargetSelection']!==null&&match($snapshot['targetConfiguration']['BaseIDSource']){'manual'=>$snapshot['targetConfiguration']['ManualBaseID']==='','saved'=>$this->ReadAttributeString('SavedBaseID')==='',default=>false})$snapshot['targetMessage']='Noch kein Transferziel angewandt. Master-ID bewusst als Ziel auswählen und Änderungen übernehmen; kein Hardware-Write.';
        }
        $snapshot['targetOnlyBuild']=self::PRODUCT_TARGET_ONLY;
        return json_encode($snapshot,JSON_THROW_ON_ERROR);
    }
    public function GetConfigurationForm(): string
    {
        return json_encode(\EnOceanGatewayManager\Product\ProductPresentation::form(json_decode($this->GetProductSnapshot(),true),json_decode($this->GetTechnicalConfigurationForm(),true)),JSON_THROW_ON_ERROR);
    }
    public function SetMasterBaseID(string $baseID,string $source,bool $confirmed): bool
    {
        try{
            if(!$confirmed||$this->writeLeaseActive())return false;
            if(!in_array($source,['manual','hardware','history'],true))throw new RuntimeException('Unbekannte Quelle.');
            if($source==='hardware'){$obs=$this->productReads();if($obs===[])throw new RuntimeException('Gateway zuerst frisch prüfen.');$baseID=$obs['CO_RD_IDBASE']['values']['baseIdRawHex'];}
            $baseID=\EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId($baseID);
            if(!$this->InitializeProductInventory())return false;$id=$this->productID();
            $this->productStore()->update(static function(array &$db)use($id,$baseID,$source):void{
                if($source==='manual')\EnOceanGatewayManager\Product\GatewayInventory::event($db,$id,$baseID,'MANUAL_ENTRY',gmdate('c'),'manual:'.$db['revision'],['note'=>'Nur lokale Eingabe, kein Hardwarebeweis']);
                \EnOceanGatewayManager\Product\GatewayInventory::master($db,$id,$baseID,$source,gmdate('c'));
            });$this->productMessage('Master Base-ID lokal gespeichert. Das Gateway wurde nicht verändert.');return true;
        }catch(Throwable $e){$this->productMessage($e->getMessage());return false;}
    }
    public function DeferMaster(): void { $this->productMessage('Master Base-ID kann später festgelegt werden. Keine Hardwareänderung.'); }
    public function ExportProductInventory(): string { return $this->productStore()->export(); }
    public function AcceptReplacement(): bool
    {
        if($this->writeLeaseActive()||$this->productReads()===[])return false;
        if(!$this->InitializeProductInventory()||!$this->productObserve())return false;
        // Old backup has already been preserved in history, Master is never overwritten.
        if(!$this->SaveCurrentBaseID())return false;$obs=$this->productReads();$eurid=$obs['CO_RD_VERSION']['values']['eurid'];$id=$this->productID();
        $this->productStore()->update(static function(array &$db)use($id,$eurid):void{$db['gateways'][$id]['acceptedEURID']=$eurid;});
        $this->productMessage('Neue Hardwareidentität bewusst zugeordnet. Master Base-ID bleibt unverändert.');return true;
    }
    public function DiscoverProductGateways(): bool
    {
        $context=$this->readSafetyContext();$serial=$context['serialID']??0;$path=$serial>0?(string)IPS_GetProperty($serial,'Port'):'';
        $devices=\EnOceanGatewayManager\Product\GatewayDiscovery::localDevices($path);
        $this->WriteAttributeString('DiscoveryCandidates',json_encode($devices,JSON_THROW_ON_ERROR));
        if(!($context['exclusiveUARTOwner']??false)){$this->productMessage('Anschlüsse gefunden, aber keine sichere exklusive Verbindung. Kein Port wurde geöffnet.');return false;}
        return $this->RefreshProductGateway();
    }
    public function ConfigureProductConnection(string $type,string $device): bool
    {
        if($type==='network'){$this->productMessage('Netzwerktransport ist noch nicht verfügbar.');return false;}
        $parent=(int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0);$serial=$parent>0?(int)(IPS_GetInstance($parent)['ConnectionID']??0):0;
        if(!in_array($type,['serial','usb'],true)||$serial<=0||$device===''||realpath($device)===false
            ||realpath($device)!==realpath((string)IPS_GetProperty($serial,'Port'))||!$this->activeGatewayTransport()){
            $this->productMessage('Schnittstelle im EnOcean Gateway Manager Konfigurator bewusst auswählen und Gateway anlegen. Bestehende Anschlüsse werden nicht umkonfiguriert.');return false;
        }
        return $this->RefreshProductGateway();
    }
    public function RefreshProductGateway(): bool { return $this->startProductReads('refresh',null); }
    public function StartMasterTransfer(): bool
    {
        $s=json_decode($this->GetProductSnapshot(),true);if($s['inventoryError']||$s['leaseActive']||$s['replacement']||($s['gateway']['master']??null)===null)return false;
        $h=$s['hardware'];$counter=$h['counter'];$review=['baseID'=>$h['baseID'],'target'=>$s['gateway']['master'],'counter'=>$counter,'expectedCounter'=>is_int($counter)?max(0,$counter-1):($counter==='UNLIMITED'?'UNLIMITED':null)];
        $this->SetBuffer('ProductFlow',json_encode(['phase'=>'DRAFT','review'=>$review]));$this->productMessage('Geplante Änderung ansehen. „Änderung prüfen“ liest das Gateway frisch; noch keine Hardwareänderung.');return true;
    }
    private function startProductReads(string $intent,?array $prior): bool
    {
        if($this->writeLeaseActive()||$this->ReadAttributeString('PendingToken')!==''||!$this->InitializeProductInventory())return false;
        $context=$this->readSafetyContext();
        if(!($context['realConnectionActive']??false)||!($context['exclusiveUARTOwner']??false)||!($context['correlationSafeAndIdle']??false)||!($context['noUnknownOutcome']??false)){
            $this->productMessage('Keine sichere exklusive Gatewayverbindung. Es wird kein Anschluss konkurrierend angesprochen.');return false;
        }
        $target=null;if(in_array($intent,['prepare','target'],true)){$s=json_decode($this->GetProductSnapshot(),true);$target=\EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId($s['gateway']['master']??'');}
        $this->SetBuffer('ProductFlow',json_encode(['phase'=>'READ_VERSION','intent'=>$intent,'prior'=>$prior,'target'=>$target,'started'=>time()],JSON_THROW_ON_ERROR));
        $this->productMessage($prior===null?'Gateway wird sicher geprüft.':'Die Sicherheitsprüfung ist inzwischen abgelaufen. Das Gateway wird vor der Änderung erneut geprüft.');
        if(!$this->ReadGatewayInformation()){$this->SetBuffer('ProductFlow','');$this->productMessage('Gatewayprüfung konnte nicht gestartet werden. Verbindung und Besitz prüfen.');return false;}return true;
    }
    public function CheckMasterTransfer(): bool
    {
        try{
            $s=json_decode($this->GetProductSnapshot(),true);if($s['inventoryError']||$s['replacement'])throw new RuntimeException('Inventar oder neue Hardwareidentität zuerst prüfen / bewusst zuordnen.');
            $master=$s['gateway']['master']??null;if($master===null)throw new RuntimeException('Zuerst eine Master Base-ID festlegen.');
            $master=\EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId($master);if($this->writeLeaseActive())return false;
            $configured=\EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId($this->ReadPropertyString('BaseIDSource')==='saved'?$this->ReadAttributeString('SavedBaseID'):$this->ReadPropertyString('ManualBaseID'));
            if($configured!==$master)throw new RuntimeException('Transfer noch nicht verfügbar: Master und angewandtes Sicherheitsziel stimmen nicht überein. Die B7.3-Zielbindung bleibt unverändert; keine Hardwareänderung.');
            return $this->startProductReads(self::PRODUCT_TARGET_ONLY?'target':'prepare',null);
        }catch(Throwable $e){$this->productMessage($e->getMessage());return false;}
    }
    public function ConfirmMasterTransfer(): bool
    {
        if(self::PRODUCT_TARGET_ONLY){$this->productMessage('B8.2 prüft nur das angewandte Ziel. Kein Start einer Write-Transaktion; Hardware-Write gesperrt.');return false;}
        try{
            $flow=json_decode($this->GetBuffer('ProductFlow'),true)?:[];$view=$this->productView();
            if(($flow['phase']??'')!=='REVIEW'||!isset($flow['review'])||($view['owner']??0)!==$this->InstanceID||($flow['transactionID']??null)!==($view['transactionID']??''))return false;
            if(($view['sendAttempts']??0)!==0)throw new RuntimeException('Ein Sendeversuch hat bereits stattgefunden. Keine Wiederholung.');
            $s=json_decode($this->GetProductSnapshot(),true);if($s['inventoryError']||$s['gateway']['master']!==$flow['review']['target'])throw new RuntimeException('Master Base-ID geändert. Bitte erneut prüfen.');
            if(($view['state']??'')!=='READY_FOR_CONFIRMATION'||time()>($view['expiresAt']??0)){
                if(isset($view['transactionID'])&&($view['sendAttempts']??0)===0&&($view['state']??'')!=='CANCELLED')$this->CancelWriteTransaction($view['transactionID']);
                return $this->startProductReads('prepare',$flow['review']);
            }
            if(!\EnOceanGatewayManager\Product\ProductPresentation::sameSituation($flow['review'],\EnOceanGatewayManager\Product\ProductPresentation::review($view)))throw new RuntimeException('Gatewaywerte haben sich geändert. Erneut prüfen und neu bestätigen.');
            $challenge=$this->writeControl('B6_CHALLENGE',['TransactionID'=>$view['transactionID']]);
            if(!($challenge['accepted']??false)||!$this->ConfirmWriteTransaction($view['transactionID'],$challenge['token'],$view['target']))throw new RuntimeException('Bestätigung konnte nicht sicher gebunden werden. Keine Änderung.');
            $flow['phase']='WAIT_FINAL';$this->SetBuffer('ProductFlow',json_encode($flow,JSON_THROW_ON_ERROR));$this->productMessage('Gateway wird unmittelbar vor der Änderung erneut geprüft.');return true;
        }catch(Throwable $e){$this->productMessage($e->getMessage());return false;}
    }
    public function CancelProductWorkflow(): void
    {
        $view=$this->productView();if(isset($view['transactionID'])&&($view['owner']??0)===$this->InstanceID&&($view['sendAttempts']??0)===0&&($view['stateClassification']['leaseActive']??false))$this->CancelWriteTransaction($view['transactionID']);
        $this->SetBuffer('ProductFlow','');$this->productMessage('Vorbereitung beendet. Keine weitere Hardwareänderung ausgelöst.');
    }
    public function ProcessProductWorkflow(): void
    {
        $lock='EGM_PRODUCT_'.$this->InstanceID;if(!IPS_SemaphoreEnter($lock,100))return;
        try{
            $flow=json_decode($this->GetBuffer('ProductFlow'),true)?:[];
            if(($flow['phase']??'')==='TARGET_VALIDATED'){
                if($this->productReads()===[]){$flow['phase']='TARGET_STALE';$this->SetBuffer('ProductFlow',json_encode($flow));$this->productMessage('Zielkonfiguration bleibt gespeichert. Hardwareprüfung abgelaufen oder Verbindung geändert; vor einem Transfer neu prüfen.');}return;
            }
            if($flow===[]||in_array($flow['phase'],['BLOCKED','DRAFT','TARGET_STALE'],true))return;
            if(in_array($flow['phase'],['READ_VERSION','READ_BASE'],true)){
                if($this->ReadAttributeString('PendingToken')!=='')return;
                if(time()-$flow['started']>10)throw new RuntimeException('Gatewayprüfung abgebrochen. Keine Änderung.');
                $obs=json_decode($this->ReadAttributeString('ReadObservations'),true)?:[];$op=$flow['phase']==='READ_VERSION'?'CO_RD_VERSION':'CO_RD_IDBASE';
                if(($obs[$op]['capability']??'')!=='SUPPORTED_READ'||(strtotime($obs[$op]['readAt']??'')?:0)<$flow['started'])throw new RuntimeException('Gateway antwortet nicht erfolgreich. Keine Änderung.');
                if($flow['phase']==='READ_VERSION'){$flow['phase']='READ_BASE';$this->SetBuffer('ProductFlow',json_encode($flow));if(!$this->ReadHardwareBaseID())throw new RuntimeException('Base-ID-Abfrage abgewiesen.');return;}
                if(!$this->productObserve())throw new RuntimeException('Gatewaydaten nicht frisch / nicht gebunden.');
                if($flow['intent']==='refresh'){$this->SetBuffer('ProductFlow','');$this->productMessage('EnOcean Gateway erkannt und frisch geprüft.');return;}
                if(self::PRODUCT_TARGET_ONLY){
                    if($flow['intent']!=='target')throw new RuntimeException('B8.2 startet keine Write-Transaktion.');
                    $s=json_decode($this->GetProductSnapshot(),true);
                    if(($s['gateway']['master']??null)!==($flow['target']??null))throw new RuntimeException('Master während der Prüfung geändert. Erneut bewusst prüfen.');
                    $check=\EnOceanGatewayManager\Product\MasterTargetBinding::applied($s,$s['targetConfiguration'],$this->targetBindingContext(),$this->targetBackup());
                    $preview=\EnOceanGatewayManager\Safety\BaseIDPreflight::preview($check['target'],$obs['CO_RD_IDBASE']['values'],5);
                    $flow['phase']='TARGET_VALIDATED';$flow['review']=['baseID'=>$check['currentBaseID'],'target'=>$check['target'],'EURID'=>$check['EURID'],'counter'=>$preview['remaining'],'expectedCounter'=>$preview['expectedRemaining']];
                    $this->SetBuffer('ProductFlow',json_encode($flow,JSON_THROW_ON_ERROR));$this->productMessage('Master-ID als Ziel nativ angewandt und frisch geprüft. Hardware-Write BLOCKED; keine neue Write-Transaktion und kein Write-Intent.');return;
                }
                $s=json_decode($this->GetProductSnapshot(),true);if($s['replacement'])throw new RuntimeException('Neues Gateway erkannt. Master bleibt erhalten; Hardwareidentität bewusst zuordnen.');
                if(($s['gateway']['master']??null)!==($flow['target']??null))throw new RuntimeException('Master inzwischen geändert. Bitte erneut prüfen.');
                if($this->ReadAttributeString('SavedBaseID')===''&&!$this->SaveCurrentBaseID())throw new RuntimeException('Lokale Sicherung konnte nicht angelegt werden.');
                if(!$this->ConfirmSavedBackupIdentity())throw new RuntimeException('Sicherungsidentität konnte nicht bestätigt werden.');
                $backup=json_decode($this->ReadAttributeString('SavedBaseIDMetadata'),true)?:[];$backup['baseID']=$this->ReadAttributeString('SavedBaseID');
                if(!($this->writeControl('B6_BEGIN',['Target'=>$flow['target'],'Backup'=>$backup])['accepted']??false))throw new RuntimeException('Sicherheitsprüfung konnte nicht vollständig abgeschlossen werden. Keine Änderung.');
                $view=$this->productView();if(($view['owner']??0)!==$this->InstanceID||empty($view['transactionID']))throw new RuntimeException('Vorbereitung konnte nicht eindeutig zugeordnet werden.');
                $flow['transactionID']=$view['transactionID'];$flow['phase']='ENGINE_PREFLIGHT';$this->SetBuffer('ProductFlow',json_encode($flow));return;
            }
            $view=$this->productView();
            if(($view['owner']??0)!==$this->InstanceID||($flow['transactionID']??null)!==($view['transactionID']??''))throw new RuntimeException('Änderungsablauf nicht mehr eindeutig zugeordnet. Keine Wiederholung.');
            if($flow['phase']==='ENGINE_PREFLIGHT'&&($view['state']??'')==='READY_FOR_CONFIRMATION'){
                $review=\EnOceanGatewayManager\Product\ProductPresentation::review($view);
                if(!$this->AuthorizeWriteTransaction($view['transactionID']))throw new RuntimeException('Lokale Freigabe nicht angenommen.');
                $changed=$flow['prior']!==null&&!\EnOceanGatewayManager\Product\ProductPresentation::sameSituation($flow['prior'],$review);
                $flow['phase']='REVIEW';$flow['review']=$review;$flow['changed']=$changed;$this->SetBuffer('ProductFlow',json_encode($flow));
                $this->productMessage($changed?'Gatewaywerte haben sich geändert. Neue Werte prüfen und bewusst erneut bestätigen.':'Gateway geprüft. Die angezeigte Änderung jetzt bewusst bestätigen.');return;
            }
            if($flow['phase']==='WAIT_FINAL'&&($view['state']??'')==='PRE_WRITE_JOURNALED'&&($view['hardwareWriteBarrier']??true)){
                $this->CancelWriteTransaction($view['transactionID']);$flow['phase']='BLOCKED';$this->SetBuffer('ProductFlow',json_encode($flow));
                $this->productMessage('Änderung sicher vorbereitet. Hardware-Schreibfunktion für diesen Release-Candidate-Test noch gesperrt. Es wurde nichts gesendet.');return;
            }
            // Future UAT only: unreachable while the immutable B8 barrier is true.
            // One controlled reconnect, never another write or response-driven retry.
            if($flow['phase']==='WAIT_FINAL'&&($view['state']??'')==='FORCE_RECONNECT'&&($view['sendAttempts']??0)===1&&!($view['hardwareWriteBarrier']??true)){
                $parent=(int)IPS_GetInstance($this->InstanceID)['ConnectionID'];$serial=(int)IPS_GetInstance($parent)['ConnectionID'];
                foreach(IPS_GetInstanceList()as$other){if(in_array($other,[$this->InstanceID,$parent],true))continue;$link=(int)(IPS_GetInstance($other)['ConnectionID']??0);if($link===$serial||$link===$parent)throw new RuntimeException('Verbindung geteilt. Gateway manuell erneut verbinden und Zustand prüfen.');}
                $flow['phase']='OBSERVE_DISCONNECT';$flow['reconnectStarted']=time();$flow['serialID']=$serial;$this->SetBuffer('ProductFlow',json_encode($flow));
                IPS_SetProperty($serial,'Open',false);IPS_ApplyChanges($serial);$this->productMessage('Antwort erhalten. Gateway wird erneut verbunden und anschließend geprüft; noch kein bestätigter Erfolg.');return;
            }
            if($flow['phase']==='OBSERVE_DISCONNECT'){
                if(time()-$flow['reconnectStarted']>10)throw new RuntimeException('Trennung nicht sicher beobachtet. Gateway manuell erneut verbinden und Zustand prüfen. Keine Wiederholung.');
                if(!($view['sawDisconnect']??false)||($this->readSafetyContext()['realConnectionActive']??true))return;
                if(\EnOceanGatewayManager\Product\GatewayDiscovery::owners((string)IPS_GetProperty($flow['serialID'],'Port'))!==[])throw new RuntimeException('Anschluss inzwischen belegt. Nicht übernommen; Zustand manuell prüfen.');
                $flow['phase']='POST_VERIFICATION';$this->SetBuffer('ProductFlow',json_encode($flow));IPS_SetProperty($flow['serialID'],'Open',true);IPS_ApplyChanges($flow['serialID']);return;
            }
            if(($view['state']??'')==='UNKNOWN_OUTCOME')throw new RuntimeException(\EnOceanGatewayManager\Product\ProductPresentation::outcome('UNKNOWN_OUTCOME'));
            if($flow['phase']==='REVIEW'&&($view['state']??'')==='CANCELLED'){$this->productMessage('Die Sicherheitsprüfung ist abgelaufen. Beim Bestätigen wird das Gateway erneut geprüft.');return;}
            if(in_array($view['state']??'', ['VERIFIED','NO_OP'],true)){$parent=(int)IPS_GetInstance($this->InstanceID)['ConnectionID'];$this->synchronizeTransactionReadProofs($view,$parent);$this->InitializeProductInventory();$this->SetBuffer('ProductFlow','');$this->productMessage($view['state']==='VERIFIED'?'Base-ID erfolgreich geändert und nach erneutem Verbinden geprüft.':'Master Base-ID und Gateway stimmen überein. Keine Änderung nötig.');}
            elseif(in_array($view['state']??'', ['CANCELLED','RECOVERY_NOT_APPLIED'],true)&&$flow['phase']!=='REVIEW')throw new RuntimeException('Sicherheitsprüfung beendet. Bitte Gatewaywerte neu prüfen; keine automatische Wiederholung.');
        }catch(Throwable $e){$view=$this->productView();if(($view['owner']??0)===$this->InstanceID&&($view['sendAttempts']??0)===0&&($view['stateClassification']['leaseActive']??false))$this->CancelWriteTransaction($view['transactionID']);$this->SetBuffer('ProductFlow','');$this->productMessage($e->getMessage());}
        finally{IPS_SemaphoreLeave($lock);}
    }
}
