<?php
declare(strict_types=1);
// Actual return entry points and handoff gates, with in-memory metadata only.
$root=getenv('EGM_RECOVERY_SOURCE_ROOT')?:dirname(__DIR__);
require_once $root.'/libs/C2Module.php';
require_once $root.'/libs/C2Presentation.php';
use EnOceanGatewayManager\Maintenance\C2Environment;
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
use EnOceanGatewayManager\Safety\WriteJournal;
use EnOceanGatewayManager\Product\C2Presentation;

function EGMA_GetWriteTransactionView(int $id): string {return '{"state":"IDLE"}';}
final class ReturnRecoveryJournal implements WriteJournal
{
    public array $rows=[];
    public function append(array $record): void {$this->rows[]=$record;}
    public function records(): array {return $this->rows;}
    public function last(): array {return $this->rows[array_key_last($this->rows)];}
    public function expireCloseObservation(): void
    {
        // Virtual elapsed time; no sleep and no production clock/gate change.
        $this->rows[array_key_last($this->rows)]['handoff']['closingAt']=microtime(true)-4;
    }
}
final class ReturnRecoveryEnvironment implements C2Environment
{
    public array $nodes,$configs,$checks=[];
    public int $next=100,$mutations=0;
    public ?array $lingering=[];
    public function __construct()
    {
        $this->nodes=[10=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::NATIVE],'ConnectionID'=>20,'InstanceStatus'=>102],
            20=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::SERIAL],'ConnectionID'=>0,'InstanceStatus'=>102],
            40=>['ModuleInfo'=>['ModuleID'=>C2Handoff::MANAGER],'ConnectionID'=>0,'InstanceStatus'=>102]];
        $this->configs=[10=>['GatewayMode'=>2,'BaseID'=>'0000A000'],
            20=>['Port'=>'SIMULATOR','BaudRate'=>'57600','DataBits'=>'8','Parity'=>'None','StopBits'=>'1','Open'=>true],40=>[]];
    }
    public function instance(int $id): array {$this->checks[]='instance:'.$id;return $this->nodes[$id]??throw new RuntimeException('Missing instance');}
    public function configuration(int $id): array {$this->checks[]='configuration:'.$id;return $this->configs[$id]??throw new RuntimeException('Missing configuration');}
    public function instances(): array {$this->checks[]='instances';return array_keys($this->nodes);}
    public function create(string $module): int
    {
        $id=$this->next++;$this->mutations++;
        $this->nodes[$id]=['ModuleInfo'=>['ModuleID'=>$module],'ConnectionID'=>0,'InstanceStatus'=>102,'C2OwnershipIdent'=>'EGM_C2_SIM_'.$id];
        $this->configs[$id]=$module===NativeGatewayResolver::SERIAL?['Port'=>'','Open'=>false]:['EnableMaintenance'=>false,'IsolatedReadOnly'=>true];return $id;
    }
    public function configure(int $id,array $configuration): void {$this->mutations++;$this->configs[$id]=$configuration;}
    public function connect(int $child,int $parent): void {$this->mutations++;$this->nodes[$child]['ConnectionID']=$parent;}
    public function disconnect(int $child): void {$this->mutations++;$this->nodes[$child]['ConnectionID']=0;}
    public function delete(int $id): void {$this->mutations++;unset($this->nodes[$id],$this->configs[$id]);}
    public function descriptors(string $path): ?array
    {
        $this->checks[]='descriptors';if($path!=='SIMULATOR')throw new RuntimeException('Unexpected endpoint');
        if($this->lingering===null)return null;
        $fds=$this->lingering;
        foreach($this->nodes as $id=>$n)if(($n['ModuleInfo']['ModuleID']??'')===NativeGatewayResolver::SERIAL
            &&($this->configs[$id]['Port']??'')===$path&&($this->configs[$id]['Open']??false))$fds[]=['pid'=>1,'fd'=>(string)$id];
        return $fds;
    }
    public function selfPID(): int {return 1;}
}
final class ReturnRecoveryManager
{
    use GatewayC2Module;
    public int $InstanceID=40,$hardwareCalls=0;
    public array $attributes=['C2Handoff'=>'[]','C2Session'=>'{"phase":"MAINTENANCE_READY","faults":[]}',
        'C2NativeRefresh'=>'[]'], $buffers=['C2RuntimeStarted'=>'1'], $timers=[],$messages=[];
    public function __construct(public ReturnRecoveryEnvironment $environment,public ReturnRecoveryJournal $journal) {}
    private function c2Handoff(): C2Handoff {return new C2Handoff($this->environment,$this->journal);}
    private function c2Environment(): C2Environment {return $this->environment;}
    private function c2Lock(callable $f): mixed {return $f();}
    public function ReadAttributeString(string $key): string {return $this->attributes[$key]??'[]';}
    public function WriteAttributeString(string $key,string $value): void {$this->attributes[$key]=$value;}
    public function GetBuffer(string $key): string {return $this->buffers[$key]??'';}
    public function SetBuffer(string $key,string $value): void {$this->buffers[$key]=$value;}
    public function SetTimerInterval(string $key,int $value): void {$this->timers[$key]=$value;}
    private function c2RequestFormUpdate(): void {}
    private function productMessage(string $message,bool $refresh=false): void {$this->messages[]=$message;}
    public function SendDataToParent(string $data): never {$this->hardwareCalls++;throw new RuntimeException('Hardware command forbidden');}
}
$count=0;$check=static function(bool $ok,string $label)use(&$count):void {if(!$ok)throw new RuntimeException($label);$count++;};
$factory=static function()use($check):array {
    $e=new ReturnRecoveryEnvironment();$original=$e->configs;$j=new ReturnRecoveryJournal();$h=new C2Handoff($e,$j);
    // Establish synthetic ownership without any gateway read or write.
    $h->begin(40,10,microtime(true));$h->advance(microtime(true));$active=$h->state();
    $m=new ReturnRecoveryManager($e,$j);$m->attributes['C2Handoff']=json_encode($active);
    $check($m->ReturnNativeMaintenance(),'first return accepted');
    $e->lingering=[['pid'=>2,'fd'=>'synthetic-lingering']];$j->expireCloseObservation();
    $m->ProcessC2Maintenance();
    $check($j->last()['event']==='RETURN_STARTED'&&$j->last()['handoff']['phase']==='RETURN_CLOSING','failed close preserves authoritative return');
    $s=json_decode($m->attributes['C2Session'],true);
    $check($s['phase']==='FAULT_LATCHED'&&$s['faults'][0]['reason']==='Temporary UART close not proven.','specific close failure latched');
    $check($m->timers['C2Timer']===0&&$e->nodes[10]['ConnectionID']===0,'timer stopped and native remains detached');
    $check(!$e->configs[20]['Open']&&!$e->configs[$active['ownIO']]['Open'],'both I/Os remain closed');
    return [$m,$e,$j,$active,$original];
};
[$m,$e,$j,$active,$original]=$factory();$e->lingering=[];$rows=$j->rows;$attributes=$m->attributes;$mutations=$e->mutations;
$check($m->ReturnNativeMaintenance(),'explicit repeated return accepted');
if(in_array('--expect-retry-bug',$argv,true)){
    $check($m->timers['C2Timer']===0,'baseline explicit return leaves timer stopped');
    $check($j->rows===$rows&&$e->nodes[10]['ConnectionID']===0,'baseline recovery never progresses');
    echo "REPRODUCED: explicit RETURN_CLOSING retry leaves timer disabled and native detached\n";exit;
}
if(in_array('--expect-stale-bug',$argv,true)){
    // Model the already completed one-off restore, then reload stale settings.
    $m->ProcessC2Maintenance();$m->attributes=$attributes;$m->buffers=[];$m->ReturnNativeMaintenance();
    $check(json_decode($m->attributes['C2Handoff'],true)['phase']==='RETURN_CLOSING','baseline direct Return leaves stale handoff attribute');
    echo "REPRODUCED: RESTORED journal with stale settings remains RETURN_CLOSING after direct Return\n";exit;
}
$check($m->timers['C2Timer']===100,'explicit return rearms existing timer');
$check($j->rows===$rows&&$e->mutations===$mutations&&$m->attributes['C2Session']===$attributes['C2Session'],'rearming starts no session, transport mutation or new intent');
$faultView=['selectedReference'=>10,'session'=>json_decode($attributes['C2Session'],true),'handoff'=>$j->last()['handoff']];
$findButton=static function(array $form)use(&$findButton):?array {
    if(($form['name']??'')==='C2Return')return $form;
    foreach($form as $v)if(is_array($v)&&($found=$findButton($v))!==null)return $found;
    return null;
};
$check($findButton(C2Presentation::form($faultView,'',[]))['enabled'],'failed closing return can be consciously retried from UI');
$faultView['session']['phase']='NATIVE_REFRESH_PENDING';
$check(!$findButton(C2Presentation::form($faultView,'',[]))['enabled'],'running return still prevents duplicate UI requests');
$e->checks=[];$m->ProcessC2Maintenance();
$check($j->last()['handoff']['phase']==='RESTORED','resumed return fully restored');
$check($e->configs===$original&&array_keys($e->nodes)===[10,20,40],'original configurations restored and temporary instances deleted');
$check($e->nodes[10]['ConnectionID']===20&&$e->nodes[40]['ConnectionID']===0,'native parent restored and manager detached');
$check(count($e->descriptors('SIMULATOR'))===1,'only native UART owner after return');
$check(json_decode($m->attributes['C2Handoff'],true)['phase']==='RESTORED'&&json_decode($m->attributes['C2Session'],true)['phase']==='RETURNED','live attributes reflect completed journal');
$check($m->timers['C2Timer']===0&&$m->hardwareCalls===0,'completed return stops timer and sends no hardware command');
foreach(['instance:'.$active['ownIO'],'instance:'.$active['ownArbiter'],'configuration:10','configuration:20','configuration:'.$active['ownIO'],'configuration:'.$active['ownArbiter'],'instances','descriptors']as $gate)
    $check(in_array($gate,$e->checks,true),'existing restore gate repeated: '.$gate);
// settings.json can lag behind the journal. Simulate loading the stale attributes.
$m->attributes=$attributes;$m->buffers=[];$m->ProcessC2Maintenance();
$check(json_decode($m->attributes['C2Handoff'],true)['phase']==='RESTORED'&&json_decode($m->attributes['C2Session'],true)['phase']==='RETURNED','restart reconciles stale settings from authoritative journal');
$check($m->buffers['C2NativeRestoredDisplay']===$active['id']&&$m->hardwareCalls===0,'restart marks technical return without hardware commands');
// A conscious Return can also precede that first reconciliation timer callback.
$m->attributes=$attributes;$m->buffers=[];$m->ReturnNativeMaintenance();
$check(json_decode($m->attributes['C2Handoff'],true)['phase']==='RESTORED'&&json_decode($m->attributes['C2Session'],true)['phase']==='RETURNED','explicit Return reconciles stale handoff before displaying success');
// Narrow negative controls: retry never permits a failing restore gate to pass.
foreach(['busy','unknown','ownMarker','ownModule','ownConfig','arbiterMarker','arbiterParent','arbiterConfig','foreignChild','managerParent','nativeParent','nativeConfig','originalConfig']as $fault){
    [$m,$e,$j,$active]=$factory();$e->lingering=[];
    switch($fault){
        case 'busy':$e->lingering=[['pid'=>2,'fd'=>'still-open']];break;
        case 'unknown':$e->lingering=null;break;
        case 'ownMarker':$e->nodes[$active['ownIO']]['C2OwnershipIdent']='FOREIGN';break;
        case 'ownModule':$e->nodes[$active['ownIO']]['ModuleInfo']['ModuleID']='FOREIGN';break;
        case 'ownConfig':$e->configs[$active['ownIO']]['BaudRate']='115200';break;
        case 'arbiterMarker':$e->nodes[$active['ownArbiter']]['C2OwnershipIdent']='FOREIGN';break;
        case 'arbiterParent':$e->nodes[$active['ownArbiter']]['ConnectionID']=999;break;
        case 'arbiterConfig':$e->configs[$active['ownArbiter']]['EnableMaintenance']='FOREIGN';break;
        case 'foreignChild':$e->nodes[99]=['ConnectionID'=>$active['ownIO']];break;
        case 'managerParent':$e->nodes[40]['ConnectionID']=999;break;
        case 'nativeParent':$e->nodes[10]['ConnectionID']=999;break;
        case 'nativeConfig':$e->configs[10]['BaseID']='FF800000';break;
        case 'originalConfig':$e->configs[20]['BaudRate']='115200';break;
    }
    $m->ReturnNativeMaintenance();$m->ProcessC2Maintenance();
    $check($j->last()['handoff']['phase']!=='RESTORED'&&json_decode($m->attributes['C2Session'],true)['phase']==='FAULT_LATCHED','restore rejects changed context: '.$fault);
    $check(!$e->configs[20]['Open']&&$m->timers['C2Timer']===0&&$m->hardwareCalls===0,'failed retry stays closed, stopped and command-free: '.$fault);
}
echo "PASS: {$count} focused return/recovery assertions; no hardware I/O\n";
