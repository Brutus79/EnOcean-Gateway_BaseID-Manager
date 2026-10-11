<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/SerialIOState.php';
use EnOceanGatewayManager\Maintenance\SerialIOState;
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
$n=0;$check=static function(bool $ok,string $label)use(&$n):void {if(!$ok)throw new RuntimeException($label);$n++;};
$io=['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::SERIAL],'ConnectionID'=>0,'InstanceStatus'=>102];
$nodes=[10=>['ConnectionID'=>20],20=>$io];$configs=[10=>[],20=>['Open'=>true,'Port'=>'SIMULATOR']];
$controlled=static function(array $nodes,array $configs):bool {
    return SerialIOState::controlled(20,10,static fn(int $id):array=>$nodes[$id],
        static fn(int $id):array=>$configs[$id],array_keys($nodes));
};
$check($controlled($nodes,$configs),'active single native connection controlled without OS inspection');
foreach(['inactive','error','unknown','wrongModule','parent','closed','missingPort','shared','duplicateEndpoint']as$fault){
    $ns=$nodes;$cs=$configs;
    switch($fault){
        case'inactive':$ns[20]['InstanceStatus']=104;break;
        case'error':$ns[20]['InstanceStatus']=201;break;
        case'unknown':unset($ns[20]['InstanceStatus']);break;
        case'wrongModule':$ns[20]['ModuleInfo']['ModuleID']='FOREIGN';break;
        case'parent':$ns[20]['ConnectionID']=99;break;
        case'closed':$cs[20]['Open']=false;break;
        case'missingPort':unset($cs[20]['Port']);break;
        case'shared':$ns[30]=['ConnectionID'=>20];break;
        case'duplicateEndpoint':$ns[30]=$io;$cs[30]=$cs[20];break;
    }
    $check(!$controlled($ns,$cs),'control not fabricated: '.$fault);
}
$nodes[30]=$io;$configs[30]=['Open'=>false,'Port'=>'SIMULATOR'];
$check($controlled($nodes,$configs),'closed original I/O does not block active temporary I/O');
$closed=$io;$closed['InstanceStatus']=104;
$check(SerialIOState::closed($closed,['Open'=>false]),'acknowledged inactive close succeeds');
foreach([102,0,201,101]as$status){$bad=$closed;$bad['InstanceStatus']=$status;
    $check(!SerialIOState::closed($bad,['Open'=>false]),'Open=false alone not accepted: '.$status);}
$check(!SerialIOState::closed($closed,['Open'=>true]),'inactive status alone not accepted');
// Production source has no process/FD inspection left. No Linux root or /proc
// is required, and descriptor counters are not fabricated as compatibility proof.
foreach(['C2Handoff','C2Module','C2SymconEnvironment','GatewayDiscovery','C2Session','C2BlockedGate','C2WriteBridge']as$file){
    $source=file_get_contents(__DIR__.'/../libs/'.$file.'.php');
    $check(!str_contains($source,'/proc/')&&!str_contains($source,'->descriptors(')
        &&!str_contains($source,'uartDescriptorCount'),'no OS ownership monitoring: '.$file);
}
$arbiter=file_get_contents(__DIR__.'/../ESP3TransportArbiter/module.php');
$check(!str_contains($arbiter,'/proc/')&&!str_contains($arbiter,'uartDescriptorCount'),'arbiter also has no hidden descriptor gate');
$check(str_contains($arbiter,'B6_HARDWARE_WRITE_BARRIER = false;'),'existing final-product hardware policy unchanged, never toggled for these tests');
echo "PASS: {$n} focused Symcon I/O control checks; no OS or hardware access\n";
