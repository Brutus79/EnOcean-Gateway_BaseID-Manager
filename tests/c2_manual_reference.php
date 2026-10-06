<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/C2Module.php';
require_once __DIR__.'/../libs/ManagerLifecycle.php';
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Product\C2Presentation;
$n=0;$check=static function(bool $ok,string $why)use(&$n):void{if(!$ok)throw new RuntimeException($why);$n++;};
$nodes=[10=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::NATIVE],'ConnectionID'=>0,'InstanceStatus'=>104],
    11=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::NATIVE],'ConnectionID'=>20,'InstanceStatus'=>201],
    12=>['ModuleInfo'=>['ModuleID'=>'foreign'],'ConnectionID'=>0]];
$r=new NativeGatewayResolver(static fn(int$id):array=>$nodes[$id]??throw new RuntimeException('deleted'),
    static fn(int$id):array=>throw new RuntimeException('Selection must not probe transport configuration'),static fn():array=>array_keys($nodes));
$rows=$r->references([10,11,12,13],static fn(int$id):string=>'Native '.$id);
$check(array_column($rows,'id')===[10,11],'existing detached/offline native references remain selectable; foreign/deleted excluded');
try{$r->resolve(10);throw new LogicException('takeover gate unexpectedly passed');}catch(RuntimeException$e){$check(str_contains($e->getMessage(),'configuration'),'strict resolve still separate from selection');}
$view=['session'=>['phase'=>'IDLE'],'handoff'=>[],'inventory'=>['gateway'=>['master'=>null],'history'=>[]],'gateways'=>$rows,'selectedReference'=>0];
$f=C2Presentation::fields(C2Presentation::form($view,'',[]));
$check(str_contains($f['C2Status']['caption'],'Bitte wählen')&&!$f['C2Start']['enabled'],'unselected neutral UI, no automatic maintenance');
$check($f['NativeGatewayInstanceID']['caption']==='EnOcean-Gateway auswählen','explicit manual caption');
$check(!isset($f['NativeGatewayInstanceID']['value']),'form never automatically chooses first or only gateway');
$check($f['NativeGatewayInstanceID']['options'][0]['value']===0,'unselected property has neutral placeholder rather than unavailable value');
$view['selectedReference']=10;$view['session']['phase']='MAINTENANCE_READY';$view['handoff']['phase']='ACTIVE';$view['fresh']=true;
$f=C2Presentation::fields(C2Presentation::form($view,'',[]));$check(in_array(10,array_column($f['NativeGatewayInstanceID']['options'],'value'),true),'selected native reference still present during detached maintenance');
$view['session']['phase']='RETURNED';$view['handoff']['phase']='RESTORED';
for($i=0;$i<3;$i++){$f=C2Presentation::fields(C2Presentation::form($view,'',[]));$check($view['selectedReference']===10&&in_array(10,array_column($f['NativeGatewayInstanceID']['options'],'value'),true)&&!isset($f['NativeGatewayInstanceID']['value']),'reload preserves chosen reference without fallback');}
function IPS_GetInstance(int$id):array{return['ConnectionID'=>0,'InstanceStatus'=>$GLOBALS['status']??201];}
function IPS_SemaphoreEnter(string$name,int$ms):bool{return true;}
function IPS_SemaphoreLeave(string$name):void{}
class UnselectedReferenceFixture
{
    use GatewayC2Module;
    use GatewayManagerLifecycle;
    public int $InstanceID=40;
    public array $attributes=['C2Session'=>'[]','C2Handoff'=>'[]','C2NativeRefresh'=>'[]'],$buffers=[];
    private function c2Handoff():C2Handoff{$h=(new ReflectionClass(C2Handoff::class))->newInstanceWithoutConstructor();(new ReflectionProperty(C2Handoff::class,'s'))->setValue($h,[]);return$h;}
    public function ReadPropertyInteger(string$key):int{return 0;}
    public function ReadPropertyBoolean(string$key):bool{return false;}
    public function ReadAttributeString(string$key):string{return$this->attributes[$key]??'';}
    public function WriteAttributeString(string$key,string$value):void{$this->attributes[$key]=$value;}
    public function GetBuffer(string$key):string{return$this->buffers[$key]??'';}
    public function SetBuffer(string$key,string$value):void{$this->buffers[$key]=$value;}
    private function productMessage(string$message,bool$force=false):void{$this->buffers['notice']=$message;}
    private function setDisplayValue(string$key,string$value):void{$this->attributes[$key]=$value;}
    private function refreshManagerForm():void{}
    public function SetStatus(int$code):void{$GLOBALS['status']=$code;}
}
$m=new UnselectedReferenceFixture();$before=$m->attributes;
$check(!$m->StartNativeMaintenance()&&$m->attributes===$before,'start without user reference does not begin handoff or latch fault');
$m->RefreshConnectionStatus();$check($GLOBALS['status']===104,'unselected parentless instance neutral');
$m->attributes['C2Session']=json_encode(['phase'=>'FAULT_LATCHED','faults'=>[['reason'=>'real_fault']]]);$m->RefreshConnectionStatus();
$check($GLOBALS['status']===201,'neutral state never hides real fault');
echo "PASS: {$n} focused manual-reference checks; no transport or hardware\n";
