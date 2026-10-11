<?php
declare(strict_types=1);
// Real Create/Destroy, local inventory and C2 UI; SDK metadata only, no transport.
$sdk=explode("require_once __DIR__ . '/../ESP3TransportArbiter/module.php';",file_get_contents(__DIR__.'/module_lifecycle.php'))[0];
$start=strpos($sdk,'function IPS_GetInstance(');$end=strpos($sdk,'function IPS_GetKernelDir(');
$sdk=substr_replace($sdk,'function IPS_GetInstance(int $id): array {return ["ConnectionID"=>0,"InstanceStatus"=>102,"ModuleInfo"=>["ModuleID"=>$id===10?"{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}":"{ED8F6F0C-D57F-4E0F-B23B-63CF05AE9643}"]];}' . "\n",$start,$end-$start);
$start=strpos($sdk,'    public function SendDataToParent(');$end=strpos($sdk,'    public function SendDataToChildren(');
$sdk=substr_replace($sdk,'    public function SendDataToParent(string $json): never {throw new RuntimeException("Unexpected hardware/control command");}' . "\n",$start,$end-$start);
$sdk=str_replace('    public function RegisterTimer(string $k, int $v, string $s): void {}',
    '    public function RegisterTimer(string $k, int $v, string $s): void {}' . "\n" .
    '    public function UpdateFormField(string $name,string $key,mixed $value): bool {return true;}',$sdk);
eval('?>'.$sdk);
function IPS_GetInstanceListByModuleID(string $id): array {return $id==='{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}'?[10]:[];}
function IPS_GetName(int $id): string {return 'Synthetic native gateway';}
function IPS_LogMessage(string $title,string $message): void {throw new RuntimeException($message);}
require_once __DIR__.'/../EnOceanGatewayManager/module.php';
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;};
$fields=static function(array $form):array{
    $result=[];$visit=static function(array $rows)use(&$visit,&$result):void{
        foreach($rows as$row){if(isset($row['name']))$result[$row['name']]=$row;
            if(isset($row['items']))$visit($row['items']);}
    };$visit($form['actions']);return $result;
};
$old=new EnOceanGatewayManager(101);$old->Create();
$GLOBALS['egmTest']['instances'][101]['properties']['NativeGatewayInstanceID']=10;
$check($old->SetNativeMasterBaseID('FF900000','manual',true),'local master saved');
$before=json_decode($old->ExportProductInventory(),true,512,JSON_THROW_ON_ERROR);
$oldID=$old->ReadAttributeString('LogicalGatewayID');
$view=json_decode($old->GetNativeMaintenanceSnapshot(),true);
$check($view['inventory']['gateway']['master']==='FF900000'&&$view['inventory']['storedMasters']===[],'current master not duplicated in retained list');
$old->Destroy();
$after=json_decode($old->ExportProductInventory(),true);
$check($after['gateways'][$oldID]['master']==='FF900000'&&$after['events']===$before['events'],'real Destroy retains master and complete history');
$check(!isset($after['gateways'][$oldID]['managerInstanceID']),'deleted owner binding removed without deleting data');
unset($GLOBALS['egmTest']['instances'][101]);
$file=rtrim(IPS_GetKernelDir(),'/').'/egm-product-inventory/inventory.json';$hash=hash_file('sha256',$file);
$new=new EnOceanGatewayManager(102);$new->Create();
$GLOBALS['egmTest']['instances'][102]['properties']['NativeGatewayInstanceID']=10;
$view=json_decode($new->GetNativeMaintenanceSnapshot(),true);
$check($view['inventory']['gateway']['master']===null,'reinstall does not automatically adopt another logical master');
$check($view['inventory']['storedMasters']===[['name'=>'EnOcean Gateway','baseID'=>'FF900000']],'reinstalled manager can find retained master');
$f=$fields(json_decode($new->GetConfigurationForm(),true));
$check($f['C2StoredMasters']['visible']&&str_contains($f['C2StoredMasters']['caption'],'FF900000'),'retained value visible under saved Base IDs');
$check(str_contains($f['C2InventoryHint']['caption'],'außerhalb des Modulordners'),'storage persistence explained in UI');
$check(hash_file('sha256',$file)===$hash,'listing retained master does not mutate inventory');
$check(!$new->SaveSelectedNativeMaster('manual','FF900000',''),'retained master cannot be reused without exact validation');
$check($new->ValidateNativeSelectedBaseID('manual','FF900000','')&&$new->SaveSelectedNativeMaster('manual','FF900000',''),'explicit validation and local save reuse retained value');
$db=json_decode($new->ExportProductInventory(),true);$newID=$new->ReadAttributeString('LogicalGatewayID');
$check($newID!==$oldID&&$db['gateways'][$oldID]['master']==='FF900000'&&$db['gateways'][$newID]['master']==='FF900000','reuse preserves old record and creates only consciously chosen local master');
$check($GLOBALS['egmTest']['sent']===[],'all retention/reinstall actions have no transport effects');
echo "PASS: {$n} focused Master-retention/reinstall checks; no hardware access\n";
