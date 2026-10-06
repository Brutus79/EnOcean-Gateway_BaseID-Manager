<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/C2Session.php';
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$n=0;$check=static function(bool $ok,string $why)use(&$n):void{if(!$ok)throw new RuntimeException($why);$n++;};
$ctx=['session'=>'synthetic','transportBinding'=>'binding','handoffBinding'=>'handoff','exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0,'writeLeaseActive'=>false,'noUnknownOutcome'=>true];
$frame=static function(string $d,string $o=''):string{$h=pack('nCC',strlen($d),strlen($o),2);return "\x55".$h.chr(ESP3Codec::crc8($h)).$d.$o.chr(ESP3Codec::crc8($d.$o));};
$v=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SYNTHETIC',16,"\0"));$b=$frame(hex2bin('00FF900000'),"\x08");
$s=new C2Session();$s->start($ctx,100);
for($i=0;$i<10;$i++){$q=$s->request($ctx,101+$i*.1);$check($s->response($q['token'],$q['operation'],$i%2?$b:$v,$ctx,101+$i*.1+.01),'initial pair');}
$ready=$s->state();
foreach(['REVIEW_A','REVIEW_B','WRITE_BLOCKED']as$phase){
    $s=new C2Session($ready);$r=$s->review('FF900080',$ctx,110);
    if($phase!=='REVIEW_A')$s->confirmA($r['token'],111);
    if($phase==='WRITE_BLOCKED'){
        $s->confirmB($r['token'],$r['target'],$ctx,112);
        for($i=0;$i<10;$i++){$q=$s->request($ctx,113+$i*.1);$check($s->response($q['token'],$q['operation'],$i%2?$b:$v,$ctx,113+$i*.1+.01),'prewrite pair');}
    }
    $prior=$s->state();$s->discardSelection($ctx,100000);
    $st=$s->state();$check($st['phase']==='MAINTENANCE_READY'&&$st['target']===null&&$st['confirmation']===null&&$st['prewrite']===[],'selection evidence discarded '.$phase);
    foreach(['id','context','snapshot','initial','history','faults']as$key)$check($st[$key]===$prior[$key],'preserve '.$key);
    $new=$s->review('FF900100',$ctx,100001);$check($new['token']!==$r['token'],'new target new token same session');
    $bad=new C2Session($s->state());try{$bad->confirmA($r['token'],100002);throw new LogicException('old token accepted');}catch(RuntimeException){}
    $check($bad->state()['phase']==='FAULT_LATCHED','old confirmation rejected');
}
foreach(['FAULT_LATCHED','PREWRITE_VERIFYING','NATIVE_REFRESH_PENDING','RETURNED']as$phase){
    $state=$ready;$state['phase']=$phase;$state['faults']=$phase==='FAULT_LATCHED'?[['reason'=>'real_fault','at'=>100]]:[];
    $s=new C2Session($state);try{$s->discardSelection($ctx,120);throw new LogicException('unsafe back accepted');}catch(RuntimeException){}
    $check($s->state()['phase']===$phase&&$s->state()['faults']===$state['faults'],'back cannot clear unsafe phase '.$phase);
}
$s=new C2Session($ready);$s->review('FF900080',$ctx,110);$changed=$ctx;$changed['transportBinding']='changed';
try{$s->discardSelection($changed,120);throw new LogicException('changed context accepted');}catch(RuntimeException){}
$check($s->state()['phase']==='FAULT_LATCHED','context event still fail closed');
echo "PASS: {$n} focused selection-back checks; no transport or send path\n";
