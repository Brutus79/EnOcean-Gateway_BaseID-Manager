<?php
declare(strict_types=1);
require_once __DIR__.'/C2InstanceStatus.php';

/** Status-only lifecycle observer. Never reapplies settings or sends hardware frames. */
trait GatewayManagerLifecycle
{
    private function activeGatewayTransport(): bool
    {
        try {
            $parent=(int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0);
            if($parent<=0||(IPS_GetInstance($parent)['ModuleInfo']['ModuleID']??'')!=='{C5D65AB1-045B-40ED-B854-3D74D41C81EC}'||!$this->HasActiveParent())return false;
            $serial=(int)(IPS_GetInstance($parent)['ConnectionID']??0);if($serial<=0)return false;
            $port=(string)IPS_GetProperty($serial,'Port');$canonical=realpath($port);
            foreach(IPS_GetInstanceList()as$id){if(in_array($id,[$this->InstanceID,$parent,$serial],true))continue;$peer=IPS_GetInstance($id);
                if(in_array((int)($peer['ConnectionID']??0),[$parent,$serial],true))return false;
                if(($peer['ModuleInfo']['ModuleID']??'')==='{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}'&&(bool)IPS_GetProperty($id,'Open')){
                    $other=(string)IPS_GetProperty($id,'Port');if($other===$port||($canonical!==false&&realpath($other)===$canonical))return false;
                }
            }return true;
        } catch (Throwable) { return false; }
    }
    public function RefreshConnectionStatus(): void
    {
        $parent=(int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0);
        $serial=$parent>0?(int)(IPS_GetInstance($parent)['ConnectionID']??0):0;
        $watch=array_values(array_filter([$parent,$serial]));$old=json_decode($this->GetBuffer('StatusSubscriptions'),true)?:[];
        foreach(array_diff($old,$watch)as$id)$this->UnregisterMessage($id,IM_CHANGESTATUS);
        foreach(array_diff($watch,$old)as$id)$this->RegisterMessage($id,IM_CHANGESTATUS);
        $this->SetBuffer('StatusSubscriptions',json_encode($watch));
        $active=$this->activeGatewayTransport();$status=$active?'CONNECTED_TO_ARBITER':'NO_ACTIVE_ARBITER';
        if($this->ReadAttributeString('TransportStatus')!==$status){
            $previous=$this->ReadAttributeString('TransportStatus');
            $this->setDisplayValue('TransportStatus',$status);$this->refreshManagerForm();
            if($active&&in_array($previous,['NOT_CONNECTED','NO_ACTIVE_ARBITER'],true)
                &&$this->ReadPropertyBoolean('EnableReadActions'))$this->SetBuffer('ProductInitialRead','requested');
        }
        $code=$active?102:201;
        if($parent===0&&$this->ReadPropertyInteger('NativeGatewayInstanceID')===0){
            $s=json_decode($this->ReadAttributeString('C2Session'),true)?:[];
            $h=json_decode($this->ReadAttributeString('C2Handoff'),true)?:[];
            if(($s['phase']??'IDLE')==='IDLE'&&($s['faults']??[])===[]&&in_array($h['phase']??'IDLE',['IDLE','RESTORED'],true))$code=104;
        }
        if($this->ReadPropertyInteger('NativeGatewayInstanceID')>0){
            $s=json_decode($this->ReadAttributeString('C2Session'),true)?:[];
            $h=json_decode($this->ReadAttributeString('C2Handoff'),true)?:[];
            $r=json_decode($this->ReadAttributeString('C2NativeRefresh'),true)?:[];
            $code=\EnOceanGatewayManager\Product\C2InstanceStatus::code($s,$h,$r,$parent,$active,$this->c2NativeContextValid($h));
        }
        if((int)(IPS_GetInstance($this->InstanceID)['InstanceStatus']??0)!==$code)$this->SetStatus($code);
    }
    /** Read-only native topology/configuration health, not a hardware refresh. */
    private function c2NativeContextValid(array $h): bool
    {
        try{
            $id=$this->ReadPropertyInteger('NativeGatewayInstanceID');$n=IPS_GetInstance($id);
            $io=(int)($n['ConnectionID']??0);
            if(($n['ModuleInfo']['ModuleID']??'')!=='{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}'||$io<=0)return false;
            $i=IPS_GetInstance($io);
            if(($i['ModuleInfo']['ModuleID']??'')!=='{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}'||($i['InstanceStatus']??0)!==102)return false;
            if(($h['phase']??'')==='RESTORED'){
                $snapshot=$h['snapshot']??[];
                return ($snapshot['nativeID']??0)===$id&&($snapshot['ioID']??0)===$io
                    &&json_decode(IPS_GetConfiguration($id),true)===($snapshot['nativeConfiguration']??null)
                    &&json_decode(IPS_GetConfiguration($io),true)===($snapshot['ioConfiguration']??null);
            }
            return ($n['InstanceStatus']??0)===102;
        }catch(Throwable){return false;}
    }
    public function MessageSink(int $TimeStamp,int $SenderID,int $Message,array $Data): void
    {
        parent::MessageSink($TimeStamp,$SenderID,$Message,$Data);
        if(in_array($Message,[FM_CONNECT,FM_DISCONNECT,IM_CHANGESTATUS,IPS_KERNELSTARTED],true))$this->RefreshConnectionStatus();
    }
}
