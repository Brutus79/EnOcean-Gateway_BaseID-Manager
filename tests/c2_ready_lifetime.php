<?php
declare(strict_types=1);
// Only the READY lifetime change and its adjacent prewrite/context boundaries.
require_once (getenv('EGM_LIFETIME_SOURCE_ROOT')?:dirname(__DIR__)).'/libs/C2Session.php';
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void {
    if(!$ok)throw new RuntimeException($label);$count++;
};
$frame=static function(string $data,string $optional=''):string {
    $header=pack('nCC',strlen($data),strlen($optional),2);
    return "\x55".$header.chr(ESP3Codec::crc8($header)).$data.$optional.chr(ESP3Codec::crc8($data.$optional));
};
$version=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SYNTHETIC',16,"\0"));
$base=$frame(hex2bin('00FF900000'),"\x08");
$context=['session'=>'synthetic-session','transportBinding'=>'synthetic-transport','handoffBinding'=>'synthetic-handoff',
    'exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0,'writeLeaseActive'=>false,'noUnknownOutcome'=>true];
$rounds=static function(C2Session $s,float $at)use($context,$version,$base,$check):void {
    for($i=0;$i<C2Session::ROUNDS*2;$i++){
        $request=$s->request($context,$at+$i/10);
        $check($request!==null&&$s->response($request['token'],$request['operation'],$i%2?$base:$version,$context,$at+$i/10+.01),'consistent read pair');
    }
};
$s=new C2Session();$s->start($context,100);$rounds($s,101);
$initial=$s->state();
foreach([164.0,1000.0,1000000.0,2678500.0]as$now){
    // Same READY branch as the runtime; baseline fallback is the negative control.
    $check($s->checkContext($context,$now),'unchanged live context');
    $valid=method_exists($s,'verifiedSnapshot')?$s->verifiedSnapshot():$s->freshSnapshot($now);
    if(!$valid)$s->fault('Initial synchronization expired',$now);
    $check($s->state()===$initial,'READY must survive elapsed time without reads or faults');
    $check($s->request($context,$now)===null,'idle READY must not auto-refresh hardware');
}
foreach(['exclusive'=>false,'session'=>'disconnected/new-session','faultEpoch'=>1]as$key=>$value){
    $faulted=new C2Session($initial);$changed=$context;$changed[$key]=$value;
    $check(!$faulted->checkContext($changed,2678500),'real context fault rejected: '.$key);
    $check($faulted->state()['phase']==='FAULT_LATCHED'&&!$faulted->verifiedSnapshot(),'real fault latches: '.$key);
    $check(!$faulted->checkContext($context,2678501),'context restoration cannot clear fault: '.$key);
}
// Long contemplation is allowed; new confirmation and five prewrite pairs are not optional.
$late=2678500.0;
$review=$s->review('FF900080',$context,$late);
$s->confirmA($review['token'],$late+.1);$s->confirmB($review['token'],'FF900080',$context,$late+.2);
$check($s->state()['phase']==='PREWRITE_VERIFYING'&&$s->state()['prewrite']===[],'old initial proof cannot become a prewrite proof');
$rounds($s,$late+1);
$check($s->state()['phase']==='WRITE_BLOCKED'&&$s->prewriteGate('FF900080',$context,$late+2),'fresh five-round proof accepted, hardware still blocked');
$check($s->prewriteGate('FF900080',$context,$late+2678400)&&$s->state()['phase']==='WRITE_BLOCKED','completed prewrite proof has no idle expiry');
$s=new C2Session($initial);$review=$s->review('FF900080',$context,$late);
$s->confirmA($review['token'],$late+2678400);
$check($s->state()['phase']==='REVIEW_B','confirmation has no idle expiry');
echo "PASS: READY lifetime {$count} targeted checks; one month idle; real faults latched; completed proof and confirmation remain valid\n";
