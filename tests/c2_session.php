<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ESP3Codec.php';
require_once __DIR__.'/../libs/C2Session.php';
require_once __DIR__.'/../libs/NativeGatewayResolver.php';
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
use EnOceanGatewayManager\Protocol\ESP3Codec;

$assertions=0;
$check=static function(bool $b,string $label)use(&$assertions):void{ $assertions++;if(!$b)throw new RuntimeException($label); };
$fails=static function(callable $f,string $label)use($check):void{try{$f();}catch(Throwable $e){$check(true,$label);return;}$check(false,$label);};
$frame=static function(string $data,string $optional=''):string{
    $h=pack('nCC',strlen($data),strlen($optional),2);
    return "\x55".$h.chr(ESP3Codec::crc8($h)).$data.$optional.chr(ESP3Codec::crc8($data.$optional));
};
$v=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SIMULATOR',16,"\0"));
$b=$frame(hex2bin('00FF900000'),"\x08");
$otherV=$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SIMULATOR',16,"\0"));
$otherB=$frame(hex2bin('00FF910000'),"\x08");
$context=['session'=>'test-session','transportBinding'=>'transport','handoffBinding'=>'handoff',
    'exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0];
$run=static function(C2Session $s,array $c,float $start,array $frames)use($check):void{
    foreach($frames as$i=>$f){$r=$s->request($c,$start+$i/10);$check(is_array($r),'request');
        if($r===null)return;$s->response($r['token'],$r['operation'],$f,$c,$start+$i/10+.01);}
};
$pairs=static fn(string$v,string$b):array=>array_merge(...array_fill(0,C2Session::ROUNDS,[$v,$b]));
$initial=static function()use($context,$run,$v,$b,$pairs):C2Session{
    $s=new C2Session();$s->start($context,100);$run($s,$context,101,$pairs($v,$b));return$s;
};
$s=$initial();$check($s->state()['phase']==='MAINTENANCE_READY','initial 5 rounds');
$check(count($s->state()['history'])===10,'full history retained');
$review=$s->review('FF900080',$context,110);$s->confirmA($review['token'],111);
$s->confirmB($review['token'],'FF900080',$context,112);
$run($s,$context,113,$pairs($v,$b));
$check($s->state()['phase']==='WRITE_BLOCKED','physical write always blocked');
$check($s->prewriteGate('FF900080',$context,114),'valid proof gate');
$check(!$s->prewriteGate('FF900080',$context,175),'60 second freshness unchanged');
$check($s->state()['phase']==='FAULT_LATCHED','expiry sticky');
foreach(['exclusive'=>false,'descriptorCount'=>2,'faultEpoch'=>1,'session'=>'new','transportBinding'=>'new','handoffBinding'=>'new'] as$key=>$value){
    $s=$initial();$c=$context;$c[$key]=$value;$check(!$s->checkContext($c,110),'context change '.$key);
    $check(!$s->checkContext($context,111),'restoring context cannot clear latch');
}
foreach(['crc','timeout','unexpected_response','parse','ownership','lease','restart'] as$reason){
    $s=$initial();$s->fault($reason,110);$check($s->request($context,111)===null,'sticky '.$reason);
    $fails(fn()=>$s->review('FF900080',$context,111),'review blocked '.$reason);
}
foreach(['','FF900001','FF7FFF80','FFFFFFFF','bad'] as$target){$s=$initial();$before=$s->state();
    $fails(fn()=>$s->review($target,$context,110),'invalid target');$check($s->state()===$before,'validation before state or I/O');}
$s=$initial();$fails(fn()=>$s->review('FF900000',$context,110),'no identical write');
$s=$initial();$r=$s->review('FF900080',$context,110);$fails(fn()=>$s->confirmA($r['token'],171),'expired confirmation');
$s=$initial();$r=$s->review('FF900080',$context,110);$s->confirmA($r['token'],111);
$fails(fn()=>$s->confirmB($r['token'],'FF910000',$context,112),'target changed');
// Every single changed VERSION/IDBASE, both initial and immediately prewrite.
for($phase=0;$phase<2;$phase++)for($at=0;$at<10;$at++){
    $s=$phase?$initial():new C2Session();
    if(!$phase)$s->start($context,100);else{$r=$s->review('FF900080',$context,110);$s->confirmA($r['token'],111);$s->confirmB($r['token'],'FF900080',$context,112);}
    for($i=0;$i<10;$i++){
        $r=$s->request($context,120+$i/10);if(!$r)break;
        $f=$i===$at?($i%2?$otherB:$otherV):($i%2?$b:$v);
        $s->response($r['token'],$r['operation'],$f,$context,120+$i/10+.01);
    }
    $check($s->state()['phase']==='FAULT_LATCHED','single changed response position '.$at);
}
// Bounded combined stale-substitution model: up to FOUR out of TEN prewrite
// responses are old, all other responses represent a changed real gateway.
$cases=0;
foreach(['identity','base','counter','all']as$change)for($mask=0;$mask<1024;$mask++){
    if(substr_count(decbin($mask),'1')>4)continue;
    $s=$initial();$r=$s->review('FF900080',$context,110);$s->confirmA($r['token'],111);$s->confirmB($r['token'],'FF900080',$context,112);
    for($i=0;$i<10;$i++){
        $r=$s->request($context,113+$i/10);if(!$r)break;
        $newV=in_array($change,['identity','all'],true)?$otherV:$v;
        $newB=match($change){'base','all'=>$otherB,'counter'=>$frame(hex2bin('00FF900000'),"\x07"),default=>$b};
        $old=($mask&(1<<$i))!==0;$f=$old?($i%2?$b:$v):($i%2?$newB:$newV);
        $s->response($r['token'],$r['operation'],$f,$context,113+$i/10+.01);
    }
    $check($s->state()['phase']==='FAULT_LATCHED','combined '.$change.' stale mask '.$mask);$cases++;
}
// Hardware replacement across NEW sessions is legitimate, never against old inventory.
$s=$initial();$s->returning();$s->returned(true);$s->start($context,200);
$run($s,$context,201,$pairs($otherV,$otherB));
$check($s->state()['phase']==='MAINTENANCE_READY','replacement fresh session accepted');
foreach(["\xFF","\x00",'']as$counter){$s=new C2Session();$s->start($context,100);$cb=$frame(hex2bin('00FF900000'),$counter);
    $run($s,$context,101,$pairs($v,$cb));
    if($counter==="\xFF"){$r=$s->review('FF900080',$context,110);$check($r['remaining']===255&&$r['expectedRemaining']===255,'unlimited');}
    else $fails(fn()=>$s->review('FF900080',$context,110),'zero or unknown counter blocked');}
// Resolver reads current chain on every invocation, even with the same reference.
$nodes=[10=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::NATIVE],'ConnectionID'=>20,'InstanceStatus'=>102],
    20=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::SERIAL],'ConnectionID'=>0,'InstanceStatus'=>102],
    30=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::SERIAL],'ConnectionID'=>0,'InstanceStatus'=>102]];
$configs=[10=>['GatewayMode'=>2,'BaseID'=>'0000A000'],20=>['Port'=>'SIMULATOR_A','BaudRate'=>'57600','DataBits'=>'8','Parity'=>'None','StopBits'=>'1','Open'=>true],
    30=>['Port'=>'SIMULATOR_B','BaudRate'=>'115200','DataBits'=>'8','Parity'=>'None','StopBits'=>'1','Open'=>true]];
$resolver=new NativeGatewayResolver(static function(int$id)use(&$nodes):array{if(!isset($nodes[$id]))throw new RuntimeException('missing');return$nodes[$id];},
    static function(int$id)use(&$configs):array{return$configs[$id];},static function()use(&$nodes):array{return array_keys($nodes);});
$a=$resolver->resolve(10);$nodes[10]['ConnectionID']=30;$c=$resolver->resolve(10);
$check($a['ioID']===20&&$c['ioID']===30&&$c['ioConfiguration']['Port']==='SIMULATOR_B','fresh parent/parameters');
$check($a['binding']!==$c['binding'],'binding changes');
$nodes[30]['ModuleInfo']['ModuleID']=NativeGatewayResolver::SOCKET;$fails(fn()=>$resolver->resolve(10),'LAN unverified');
$nodes[30]['ModuleInfo']['ModuleID']=NativeGatewayResolver::SERIAL;$configs[30]['DataBits']='7';$fails(fn()=>$resolver->resolve(10),'unsupported profile');
$configs[30]['DataBits']='8';unset($nodes[30]);$fails(fn()=>$resolver->resolve(10),'deleted parent');
echo "PASS: C2 safety/resolver {$assertions} assertions, {$cases} bounded combined faults; zero false-ready within this model\n";
