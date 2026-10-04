<?php
declare(strict_types=1);
// Runs after the existing module test double. No real Symcon/native transport.
if(!function_exists('IPS_GetInstanceListByModuleID')){
    function IPS_GetInstanceListByModuleID(string$id):array{return[];}
    function IPS_GetName(int$id):string{return'SIMULATOR';}
    function IPS_LogMessage(string$title,string$message):void{$GLOBALS['egmTest']['logs'][]=[$title,$message];}
}
$n=0;$check=static function(bool$b,string$l)use(&$n):void{$n++;if(!$b)throw new RuntimeException($l);};
$m=new EnOceanGatewayManager(101);$GLOBALS['egmTest']['instances'][101]['properties']['NativeGatewayInstanceID']=10;
$m->WriteAttributeString('C2Session','[]');$m->WriteAttributeString('C2Handoff','[]');$m->WriteAttributeString('C2Review','[]');
$sent=count($GLOBALS['egmTest']['sent']);
$check(!$m->SetNativeMasterBaseID('FF900080','manual',false),'no implicit Master confirmation');
$check(!$m->SetNativeMasterBaseID('FF900081','manual',true),'manual range/alignment before inventory');
$check($m->SetNativeMasterBaseID('FF900080','manual',true),'manual Master codec namespace and local persistence');
$db=json_decode($m->ExportProductInventory(),true);$id=$m->ReadAttributeString('LogicalGatewayID');
$check($db['gateways'][$id]['master']==='FF900080','local Master visible');
$check(!$m->SetNativeMasterBaseID('FF920000','history',true),'foreign history rejected');
$check($m->SetNativeMasterBaseID('FF900000','manual',true),'second local Master');
$check($m->SetNativeMasterBaseID('FF900080','history',true),'history belongs to logical gateway');
$check(!$m->SetNativeMasterBaseID('','hardware',true),'cached hardware without fresh C2 proof rejected');
$form=json_decode($m->GetConfigurationForm(),true);
$names=array_column($form['actions'],'name');
$check(in_array('C2HistoryChoice',$names,true)&&in_array('C2MasterEntry',$names,true),'C2 form contains history and Master choice');
$check(!json_decode($m->GetNativeMaintenanceSnapshot(),true)['fresh'],'no false-fresh from local history');
$m->WriteAttributeString('C2Session',json_encode(['phase'=>'REVIEW_A','faults'=>[]]));
$check(!$m->SetNativeMasterBaseID('FF910000','manual',true),'Master cannot change during confirmation');
$m->WriteAttributeString('C2Session','[]');
$check(!$m->ReviewNativeStoredTarget('history','FF930000'),'cross-gateway target rejected');
$check(count($GLOBALS['egmTest']['sent'])===$sent,'all inventory/UI actions have zero transport effects');
$before=json_decode($m->ExportProductInventory(),true);$m->Destroy();$after=json_decode($m->ExportProductInventory(),true);
$check(!isset($after['gateways'][$id]['managerInstanceID'])&&$after['events']===$before['events'],'Destroy retains history without recycled instance identity binding');
$check($after['gateways'][$id]['master']===$before['gateways'][$id]['master'],'Destroy preserves local Master');
$m->WriteAttributeString('LogicalGatewayID','');$new=json_decode($m->GetNativeMaintenanceSnapshot(),true);
$check($m->ReadAttributeString('LogicalGatewayID')!==$id&&$new['inventory']['gateway']['master']===null,'reused InstanceID never auto-adopts old Master/history');
$GLOBALS['egmTest']['instances'][101]['properties']['NativeGatewayInstanceID']=0;
echo "PASS: C2 inventory/module/UI {$n} assertions\n";
