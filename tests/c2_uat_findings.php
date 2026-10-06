<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/C2Presentation.php';
require_once __DIR__.'/../libs/C2InstanceStatus.php';
use EnOceanGatewayManager\Product\C2Presentation;
use EnOceanGatewayManager\Product\C2InstanceStatus;
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;};
$base=['baseIdRawHex'=>'FF900000','remainingWriteCycles'=>8,'remainingWriteCyclesMode'=>'finite'];
$s=['phase'=>'RETURNED','faults'=>[],'snapshot'=>['idbase'=>$base]];
$h=['phase'=>'RESTORED','snapshot'=>['nativeID'=>10,'ioID'=>20]];
$r=['status'=>'OBSERVED_NATIVE_REFRESH','base'=>'FF900000','counter'=>8,'native'=>10,'io'=>20];
$check(C2InstanceStatus::code($s,$h,$r,0,false,true)===102,'verified parentless return healthy');
$check(C2InstanceStatus::code([],[],[],0,false,true)===102,'valid native reference idle healthy');
$check(C2InstanceStatus::code($s,$h,$r,0,false,false)===201,'native topology/configuration loss error');
foreach(['FAULT_LATCHED','RETURN_WARNING','UNKNOWN']as$p){$t=$s;$t['phase']=$p;$check(C2InstanceStatus::code($t,$h,$r,0,false,true)===201,'real fault/unknown not cosmetically hidden '.$p);}
$t=$s;$t['faults']=[['reason'=>'real_fault']];$check(C2InstanceStatus::code($t,$h,$r,0,false,true)===201,'latched fault stays error');
foreach(['status'=>'PENDING','base'=>'FF900080','counter'=>7,'native'=>11,'io'=>21]as$k=>$v){$bad=$r;$bad[$k]=$v;$check(C2InstanceStatus::code($s,$h,$bad,0,false,true)===201,'invalid return proof error '.$k);}
$t=$s;$t['phase']='NATIVE_REFRESH_PENDING';$check(C2InstanceStatus::code($t,$h,[],0,false,true)===104,'valid physical return pending not completed');
$check(C2InstanceStatus::code($t,$h,[],0,false,false)===201,'inconsistent restored context error');
$t=$s;$t['phase']='MAINTENANCE_READY';$active=['phase'=>'ACTIVE'];
$check(C2InstanceStatus::code($t,$active,[],30,true,false)===102,'exclusive active maintenance healthy');
$check(C2InstanceStatus::code($t,$active,[],30,false,false)===201,'lost maintenance connection error');
$v=['session'=>$s,'handoff'=>$h,'nativeRefresh'=>$r,'nativeContextValid'=>true,'fresh'=>false,'lastKnown'=>['idbase'=>$base],
    'selectedReference'=>10,'gateways'=>[['id'=>10,'name'=>'Synthetic native gateway']],
    'inventory'=>['gateway'=>['master'=>'FF900000'],'history'=>[['baseID'=>'FF900080','lastSeen'=>'2026-10-04T18:42:00+02:00','observed'=>false]],'replacement'=>false]];
$fields=C2Presentation::fields(C2Presentation::form($v,'FF900000',[]));
$check($fields['C2Base']['caption']==='Aktuelle Base-ID des Gateways: FF900000','verified returned current wording');
$check(array_column($fields['NativeGatewayInstanceID']['options'],'value')===[10],'only existing native gateways; no zero sentinel');
$check(str_contains($fields['C2KnownHistory']['caption'],'FF900080')&&str_contains($fields['C2KnownHistory']['caption'],'2026'),'selectable history independently visible with date');
$check(str_contains($fields['C2LocalValues']['caption'],'Master Base-ID: FF900000'),'master not replaced by history');
foreach(['RETURN_WARNING','FAULT_LATCHED','IDLE']as$p){$bad=$v;$bad['session']['phase']=$p;$f=C2Presentation::fields(C2Presentation::form($bad,'',[]));$check(str_starts_with($f['C2Base']['caption'],'Zuletzt gelesene'),'cache wording not current '.$p);}
$bad=$v;$bad['nativeContextValid']=false;$f=C2Presentation::fields(C2Presentation::form($bad,'',[]));$check(str_starts_with($f['C2Base']['caption'],'Zuletzt gelesene'),'changed native context not current');
$bad=$v;$bad['nativeRefresh']['counter']=7;$f=C2Presentation::fields(C2Presentation::form($bad,'',[]));$check(str_starts_with($f['C2Base']['caption'],'Zuletzt gelesene'),'inconsistent proof not current');
class IPSModuleStrict {public int $InstanceID=40;public int $ref=0;public function ReadPropertyInteger(string$n):int{return$this->ref;}}
function IPS_GetInstance(int$id):array{return$GLOBALS['nodes'][$id]??['ConnectionID'=>$GLOBALS['parent']??0];}
function IPS_GetConfiguration(int$id):string{return json_encode($GLOBALS['configs'][$id]);}
require_once __DIR__.'/../EnOceanGatewayManager/module.php';
$m=new EnOceanGatewayManager();$GLOBALS['parent']=0;
$check($m->GetCompatibleParents()==='{}','unselected parentless C2 never proposes internal arbiter');
$m->ref=10;$check($m->GetCompatibleParents()==='{}','native gateway reference never parent assistant');
$GLOBALS['parent']=30;$check($m->GetCompatibleParents()==='{}','active native-reference C2 keeps console assistant empty');
$m->ref=0;$check(str_contains($m->GetCompatibleParents(),'C5D65AB1'),'explicit legacy attached arbiter mode retained');
$m->ref=10;
$GLOBALS['nodes']=[10=>['ConnectionID'=>20,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}']],
    20=>['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}']]];
$GLOBALS['configs']=[10=>['GatewayMode'=>2],20=>['Open'=>true,'Port'=>'SIMULATOR']];
$h['snapshot']+=['nativeConfiguration'=>$GLOBALS['configs'][10],'ioConfiguration'=>$GLOBALS['configs'][20]];
$health=new ReflectionMethod($m,'c2NativeContextValid');
$check($health->invoke($m,$h),'actual native metadata health adapter valid restored context');
$GLOBALS['nodes'][10]['ConnectionID']=21;$check(!$health->invoke($m,$h),'changed native parent not healthy');$GLOBALS['nodes'][10]['ConnectionID']=20;
$GLOBALS['configs'][20]['Open']=false;$check(!$health->invoke($m,$h),'changed restored configuration not healthy');$GLOBALS['configs'][20]['Open']=true;
$GLOBALS['nodes'][20]['InstanceStatus']=201;$check(!$health->invoke($m,$h),'native I/O failure not healthy');
echo "PASS: {$n} focused UAT 1–5 presentation/status/parent checks; no hardware access\n";
