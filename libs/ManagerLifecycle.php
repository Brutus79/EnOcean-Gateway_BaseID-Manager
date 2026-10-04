<?php
declare(strict_types=1);

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
        $this->SetStatus($active?102:201);
    }
    public function MessageSink(int $TimeStamp,int $SenderID,int $Message,array $Data): void
    {
        parent::MessageSink($TimeStamp,$SenderID,$Message,$Data);
        if(in_array($Message,[FM_CONNECT,FM_DISCONNECT,IM_CHANGESTATUS,IPS_KERNELSTARTED],true))$this->RefreshConnectionStatus();
    }
}
