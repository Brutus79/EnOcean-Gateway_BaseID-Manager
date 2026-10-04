<?php
declare(strict_types=1);
// Only C2 confirmation/completed-proof lifetime and adjacent event invalidations.
// Synthetic frames, virtual time, no transport, simulator write or hardware send.
require_once __DIR__.'/../libs/C2Session.php';
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$count=0;$check=static function(bool $ok,string $label)use(&$count):void {
    if(!$ok)throw new RuntimeException($label);$count++;
};
$frame=static function(string $data,string $optional=''):string {
    $h=pack('nCC',strlen($data),strlen($optional),2);
    return "\x55".$h.chr(ESP3Codec::crc8($h)).$data.$optional.chr(ESP3Codec::crc8($data.$optional));
};
$version=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SYNTHETIC',16,"\0"));
$base=$frame(hex2bin('00FF900000'),"\x08");
$ctx=['session'=>'synthetic-session','transportBinding'=>'synthetic-transport','handoffBinding'=>'synthetic-handoff',
    'exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0,'writeLeaseActive'=>false,'noUnknownOutcome'=>true];
$rounds=static function(C2Session $s,float $at)use($ctx,$version,$base,$check):void {
    for($i=0;$i<C2Session::ROUNDS*2;$i++){
        $r=$s->request($ctx,$at+$i/10);
        $check($r!==null&&$s->response($r['token'],$r['operation'],$i%2?$base:$version,$ctx,$at+$i/10+.01),'five consistent read pairs');
    }
};
$reviewed=static function()use($ctx,$rounds):array {
    $s=new C2Session();$s->start($ctx,100);$rounds($s,101);
    return [$s,$s->review('FF900080',$ctx,110)];
};
$confirmed=static function()use($reviewed,$ctx):C2Session {
    [$s,$r]=$reviewed();$s->confirmA($r['token'],111);$s->confirmB($r['token'],'FF900080',$ctx,112);return $s;
};
$blocked=static function()use($confirmed,$rounds,$ctx,$check):C2Session {
    $s=$confirmed();$rounds($s,150);
    $check($s->prewriteGate('FF900080',$ctx,151),'completed blocked proof initially valid');return $s;
};
$fails=static function(callable $f,C2Session $s,string $label)use($check):void {
    $thrown=false;try{$f();}catch(RuntimeException){$thrown=true;}
    $check($thrown&&$s->state()['phase']==='FAULT_LATCHED'&&$s->state()['confirmation']===null,$label);
};
if(in_array('--expect-expiry',$argv,true)){
    $s=$blocked();$before=$s->state();
    $check(172-$before['prewrite'][0]['readAt']<60,'read proof still younger than 60s');
    $check(!$s->prewriteGate('FF900080',$ctx,172),'baseline confirmation age alone breaks completed proof');
    $check($s->state()['faults'][0]['reason']==='prewrite_gate_failed','exact reported latch reproduced');
    $s=$blocked();$check(!$s->prewriteGate('FF900080',$ctx,1000000000),'baseline long idle breaks WRITE_BLOCKED');
    [$s,$r]=$reviewed();$fails(fn()=>$s->confirmA($r['token'],171),$s,'baseline delayed A expires');
    [$s,$r]=$reviewed();$s->confirmA($r['token'],111);
    $fails(fn()=>$s->confirmB($r['token'],'FF900080',$ctx,172),$s,'baseline delayed B expires');
    echo "REPRODUCED: {$count} checks; confirmation age alone causes prewrite_gate_failed; long blocked idle and delayed A/B fail\n";
    exit;
}
[$s,$r]=$reviewed();$year=365*86400.0;
$check($s->checkContext($ctx,$year),'fresh unchanged context before delayed A');$s->confirmA($r['token'],$year);
$check($s->checkContext($ctx,10*$year),'fresh unchanged context before delayed B');
$s->confirmB($r['token'],'FF900080',$ctx,10*$year);
$check($s->state()['phase']==='PREWRITE_VERIFYING'&&$s->state()['prewrite']===[],'delayed A/B still require new read proof');
$rounds($s,10*$year+1);$stable=$s->state();
foreach([10*$year+3,11*$year,50*$year]as$now){
    $check($s->prewriteGate('FF900080',$ctx,$now),'completed proof survives elapsed time');
    $check($s->state()===$stable&&$s->request($ctx,$now)===null,'stable WRITE_BLOCKED: no mutation or automatic reads');
}
$states=[];[$a,$r]=$reviewed();$states[]=$a->state();$a->confirmA($r['token'],111);$states[]=$a->state();$states[]=$blocked()->state();
foreach($states as$state)foreach(['exclusive'=>false,'descriptorCount'=>0,'faultEpoch'=>1,'session'=>'new-session',
    'transportBinding'=>'changed-transport','handoffBinding'=>'changed-gateway','writeLeaseActive'=>true,'noUnknownOutcome'=>false]as$key=>$value){
    $s=new C2Session($state);$changed=$ctx;$changed[$key]=$value;
    $check(!$s->checkContext($changed,50*$year),'real context event invalidates '.$state['phase'].'/'.$key);
    $check($s->state()['phase']==='FAULT_LATCHED'&&$s->state()['confirmation']===null,'event discards confirmation');
    $check(!$s->checkContext($ctx,50*$year+1),'restoration does not clear latch');
}
[$s,$r]=$reviewed();$fails(fn()=>$s->confirmA('wrong-token',$year),$s,'wrong A token');
[$s,$r]=$reviewed();$s->confirmA($r['token'],111);$fails(fn()=>$s->confirmA($r['token'],$year),$s,'replayed A');
[$s,$r]=$reviewed();$s->confirmA($r['token'],111);$fails(fn()=>$s->confirmB('wrong-token','FF900080',$ctx,$year),$s,'wrong B token');
[$s,$r]=$reviewed();$s->confirmA($r['token'],111);$fails(fn()=>$s->confirmB($r['token'],'FF910000',$ctx,$year),$s,'target changed before B');
$s=$blocked();$check(!$s->prewriteGate('FF910000',$ctx,$year)&&$s->state()['confirmation']===null,'target changed after proof');
$state=$blocked()->state();array_pop($state['prewrite']);$s=new C2Session($state);
$check(!$s->prewriteGate('FF900080',$ctx,$year),'incomplete proof rejected regardless of age');
foreach(['identity','base','counter']as$change){
    $s=$confirmed();$r=$s->request($ctx,150);
    if($change==='identity')$bytes=$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SYNTHETIC',16,"\0"));
    else{
        $check($s->response($r['token'],$r['operation'],$version,$ctx,150.01),'unchanged version');$r=$s->request($ctx,150.02);
        $bytes=$frame(hex2bin($change==='base'?'00FF910000':'00FF900000'),$change==='counter'?"\x07":"\x08");
    }
    $check(!$s->response($r['token'],$r['operation'],$bytes,$ctx,150.03)&&$s->state()['faults'][0]['reason']==='gateway_state_changed','contradictory '.$change.' invalidates');
    $check(!$s->prewriteGate('FF900080',$ctx,$year),'contradictory hardware stays latched');
}
$s=$blocked();$check(!$s->response('unsolicited','CO_RD_IDBASE',$base,$ctx,$year),'unexpected response after proof latches');
$s=$confirmed();$r=$s->request($ctx,150);
$check(!$s->response($r['token'],$r['operation'],$version,$ctx,211),'in-flight response timeout remains');
$s=$confirmed();
for($i=0;$i<10;$i++){
    $at=150+$i*7;$r=$s->request($ctx,$at);$s->response($r['token'],$r['operation'],$i%2?$base:$version,$ctx,$at+.01);
}
$check($s->state()['phase']==='FAULT_LATCHED'&&$s->state()['faults'][0]['reason']==='reads_expired','slow proof acquisition still expires');
$s=$blocked();$s->returning();$check(!$s->prewriteGate('FF900080',$ctx,$year),'return invalidates proof');
echo "PASS: {$count} targeted checks; delayed A/B, 50-year virtual blocked lifetime, event invalidations, read acquisition timeouts retained\n";
