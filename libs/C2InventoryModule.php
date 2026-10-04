<?php
declare(strict_types=1);
use EnOceanGatewayManager\Protocol\ESP3Codec;

/** Local inventory actions for native C2. Never a transport/write permission. */
trait GatewayC2InventoryModule
{
    private function c2InventoryView(): array
    {
        $db=$this->productStore()->read();$id=$this->productID();
        $g=$db['gateways'][$id]??['master'=>null,'acceptedEURID'=>null];
        $current=$this->c2Session()->state()['snapshot']??null;
        return ['gateway'=>$g,'history'=>array_values(\EnOceanGatewayManager\Product\GatewayInventory::history($db,$id)),
            'replacement'=>$current!==null&&$g['acceptedEURID']!==null&&$g['acceptedEURID']!==$current['version']['eurid']];
    }
    private function c2FreshLocalAction(): array
    {
        $s=$this->c2Session();$now=microtime(true);
        if(!$s->checkContext($this->c2Context($this->c2Handoff()),$now)||!$s->verifiedSnapshot()
            ||$s->state()['phase']!=='MAINTENANCE_READY')throw new RuntimeException('Frische, fehlerfreie Wartungsprüfung erforderlich.');
        return$s->state()['snapshot'];
    }
    private function c2SaveBackup(bool $replacementAcknowledged=false): bool
    {
        $fresh=$this->c2FreshLocalAction();
        if(!$this->InitializeProductInventory())throw new RuntimeException('Inventar nicht verfügbar.');
        if(!$replacementAcknowledged&&$this->c2InventoryView()['replacement'])throw new RuntimeException('Hardwarewechsel zuerst bewusst zuordnen.');
        $s=$this->c2Session()->state();$h=$this->c2Handoff()->state();
        $last=$s['initial'][array_key_last($s['initial'])];
        $this->WriteAttributeString('SavedBaseID',$fresh['idbase']['baseIdRawHex']);
        $this->WriteAttributeString('SavedBaseIDMetadata',json_encode([
            'source'=>'CO_RD_IDBASE','savedAt'=>gmdate('c'),'readAt'=>gmdate('c',(int)$last['readAt']),
            'observedEURID'=>$fresh['version']['eurid'],'parentInstanceID'=>(string)$h['ownArbiter'],
            'binding'=>$s['context']['transportBinding'],'session'=>$s['context']['session'],
            'proof'=>'C2_FIVE_CONSISTENT_ROUNDS'],JSON_THROW_ON_ERROR));
        if(!$this->InitializeProductInventory())throw new RuntimeException('Sicherung gespeichert, Inventar benötigt Prüfung.');
        $this->productMessage('Aktuelle Base-ID lokal gesichert; kein Hardwarekommando.',true);return true;
    }
    public function SetNativeMasterBaseID(string $base,string $source,bool $confirmed): bool
    {
        try{return$this->c2Lock(function()use($base,$source,$confirmed):bool{
            if(!$confirmed)throw new RuntimeException('Bewusste lokale Bestätigung erforderlich.');
            $phase=$this->c2Session()->state()['phase']??'IDLE';
            if(!in_array($phase,['IDLE','MAINTENANCE_READY','RETURNED','RETURN_WARNING'],true))throw new RuntimeException('Laufende Prüfung zuerst sicher beenden.');
            if(!in_array($source,['manual','hardware','history'],true))throw new RuntimeException('Unbekannte Masterquelle.');
            if($source==='hardware')$base=$this->c2FreshLocalAction()['idbase']['baseIdRawHex'];
            $base=ESP3Codec::normalizeWritableBaseId($base);
            if(!$this->InitializeProductInventory())throw new RuntimeException('Inventar nicht verfügbar.');
            $id=$this->productID();
            $this->productStore()->update(static function(array&$db)use($id,$base,$source):void{
                if($source==='manual')\EnOceanGatewayManager\Product\GatewayInventory::event($db,$id,$base,'MANUAL_ENTRY',gmdate('c'),'manual:'.$db['revision']);
                \EnOceanGatewayManager\Product\GatewayInventory::master($db,$id,$base,$source,gmdate('c'));
            });
            $this->WriteAttributeString('C2Review','[]');$this->productMessage('Master Base-ID bewusst lokal gespeichert; Hardware unverändert.',true);return true;
        });}catch(Throwable$e){$this->productMessage($e->getMessage(),true);return false;}
    }
    public function AcceptNativeReplacement(): bool
    {
        try{return$this->c2Lock(function():bool{
            $fresh=$this->c2FreshLocalAction();
            if(!$this->InitializeProductInventory()||!$this->productObserve())throw new RuntimeException('Inventar nicht verfügbar.');
            // Initialize preserves the former saved ID in history BEFORE replacing it.
            if(!$this->c2SaveBackup(true))throw new RuntimeException('Neue lokale Sicherung nicht frisch nachgewiesen.');
            $id=$this->productID();$eurid=$fresh['version']['eurid'];
            $this->productStore()->update(static function(array&$db)use($id,$eurid):void{$db['gateways'][$id]['acceptedEURID']=$eurid;});
            $this->productMessage('Hardwarewechsel bewusst zugeordnet; alte Sicherung in Historie, Master unverändert.',true);return true;
        });}catch(Throwable$e){$this->productMessage($e->getMessage(),true);return false;}
    }
    public function ReviewNativeStoredTarget(string $source,string $base): bool
    {
        try{
            $v=$this->c2InventoryView();
            $expected=match($source){'master'=>$v['gateway']['master'],'saved'=>$this->ReadAttributeString('SavedBaseID'),
                'history'=>in_array($base,array_column($v['history'],'baseID'),true)?$base:null,default=>null};
            if($expected===null||$base!==$expected)throw new RuntimeException('Lokales Ziel gehört nicht zur gewählten Quelle.');
            return$this->ReviewNativeTarget($base);
        }catch(Throwable$e){$this->productMessage($e->getMessage(),true);return false;}
    }
}
