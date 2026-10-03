<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ESP3Codec.php';
require_once __DIR__.'/../libs/TransactionalWrite.php';
require_once __DIR__.'/../libs/BaseIDPreflight.php';
require_once __DIR__.'/../libs/WriteJournal.php';
use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\TransactionalWrite;
use EnOceanGatewayManager\Safety\WriteJournal;
use EnOceanGatewayManager\Safety\BaseIDPreflight;
$passed=0;
$check=static function(bool $ok,string $name)use(&$passed):void{if(!$ok)throw new RuntimeException($name);$passed++;};
$throws=static function(callable $f,string $name)use($check):void{try{$f();}catch(Throwable){$check(true,$name);return;}$check(false,$name);};
$memory=static fn()=>new class implements WriteJournal{
    public array $rows=[];public bool $broken=false;
    public function append(array $r):void{$this->rows[]=$r;}
    public function records():array{if($this->broken)throw new RuntimeException('Invalid WAL chain');return $this->rows;}
};
foreach(['FF800000','FFFFFF80','FFC2F700','FFC2F780','FFC2F800','FFC2F880',' ffc2f780 ','0xffc2f700']as$input){
    $normalized=ESP3Codec::normalizeWritableBaseId($input);
    $check((hexdec(substr($normalized,-2))&127)===0,'Aligned normalized '.$input);
    $check(ESP3Codec::parseFrame(ESP3Codec::buildWriteIdBaseRequest($input))['data']===chr(7).hex2bin($normalized),'Aligned encoder '.$input);
}
for($low=1;$low<128;$low++){
    $target='FFC2F7'.sprintf('%02X',$low);
    $throws(fn()=>ESP3Codec::normalizeWritableBaseId($target),'All lower seven bits rejected '.$low);
}
foreach(['FF7FFF80','FFFFFF81','FFFFFFFF','FFC2F7','FFC2F7000','GFC2F700','FFC2 F700','']as$bad){$throws(fn()=>ESP3Codec::normalizeWritableBaseId($bad),'Format/range invalid '.$bad);}
$j=$memory();$t=new TransactionalWrite();$io=0;
try{$t->begin(101,'FFC2F740',[],[],100,$j);$io++;}catch(Throwable $e){$check(str_contains($e->getMessage(),'INVALID_BASE_ID_ALIGNMENT')&&str_contains($e->getMessage(),'FFC2F700'),'B7.1 explicit error and suggestion');}
$check($io===0&&$j->records()===[]&&$t->snapshot()['state']==='IDLE','B7.1 invalid target: no I/O, lease or WAL');
$throws(fn()=>ESP3Codec::buildWriteIdBaseRequest('FFC2F740'),'Encoder rejects B7.1');
$throws(fn()=>BaseIDPreflight::preview('FFC2F740',[]),'Preview rejects B7.1');
$throws(fn()=>$t->authorize(101,'old',$j),'No confirmation for B7.1');
$check($j->records()===[],'No invalid-target WAL intent');
$now=1000000000;
$c=['arbiterID'=>200,'binding'=>'binding','session'=>'before','ownerRevision'=>'revision','realConnectionActive'=>true,
    'correlationSafeAndIdle'=>true,'transportCorrelationSafe'=>true,'noUnknownOutcome'=>true,'exclusiveUARTOwner'=>true];
$version='55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F';
$reply=static function(string $data,string $optional=''):string{$d=hex2bin($data);$o=hex2bin($optional);$h=pack('nCC',strlen($d),strlen($o),2);return strtoupper(bin2hex(chr(85).$h.chr(ESP3Codec::crc8($h)).$d.$o.chr(ESP3Codec::crc8($d.$o))));};
$base=$reply('00FFC2F700','09');
$v=ESP3Codec::parseReadResponse('CO_RD_VERSION',hex2bin($version));$b=ESP3Codec::parseReadResponse('CO_RD_IDBASE',hex2bin($base));
// Historical failed intent is raw evidence; NEW encoder must never generate it.
$historical=['state'=>'UNKNOWN_OUTCOME','transactionID'=>str_repeat('a',48),'owner'=>101,'parentID'=>200,'binding'=>'binding',
    'session'=>'before','ownerRevision'=>'revision','target'=>'FFC2F740','backupEURID'=>'01020304','sendAttempts'=>1,
    'frameHex'=>'5500050005DB07FFC2F7401B','permanentFailure'=>true,
    'preview'=>['remaining'=>10,'expectedRemaining'=>9,'currentBaseID'=>'FFC2F780'],
    'reads'=>['CO_RD_VERSION'=>['at'=>$now,'session'=>'post','binding'=>'binding','values'=>$v],
        'CO_RD_IDBASE'=>['at'=>$now,'session'=>'post','binding'=>'binding','values'=>$b]]];
$start=static function(array $h,array $ctx)use($memory,$now):array{$j=$memory();$j->append($h);$t=new TransactionalWrite($h);$t->administrativeRecover(101,$h['transactionID'],$ctx,$now,$j);return[$t,$j];};
[$t,$j]=$start($historical,$c);$s=$t->snapshot();
$check($s['transactionID']!==$historical['transactionID']&&$s['readOnlyRecovery']&&$s['sendAttempts']===0,'Separate read-only recovery operation');
$check($j->records()[0]===$historical&&$s['originalIntent']===$historical,'Original failed intent/history preserved');
$throws(fn()=>$t->confirm(101,$historical['transactionID'],'old','FFC2F740',$c,$now,$j),'Old B7.1 confirmation replay denied');
$check($t->nextRead($c,$now,$j)===null,'Reconnect required before new recovery reads');
$t->observe($c,$now,$j);$check($t->snapshot()['state']==='ADMIN_RECOVERY_RECONNECT','Current session cannot fake reconnect');
$off=$c;$off['realConnectionActive']=false;$t->observe($off,$now,$j);
$t->observe($c,$now,$j);$check($t->snapshot()['state']==='ADMIN_RECOVERY_RECONNECT','Observed disconnect plus stale session still denied');
$on=$c;$on['session']='new';$t->observe($on,$now,$j);
$readPair=static function(TransactionalWrite $t,WriteJournal $j,array $ctx,string $ver,string $id)use($now):void{
    foreach([$ver,$id]as$hex){$e=$t->nextRead($ctx,$now,$j);if($e===null)throw new RuntimeException('No recovery read');
        $t->receive($e['token'],$e['operation'],'RESPONSE',$hex,$ctx,$now,$j);}
};
$readPair($t,$j,$on,$version,$base);$check($t->snapshot()['state']==='ADMIN_RECOVERY_READS','One sample alone does not resolve');
$readPair($t,$j,$on,$version,$base);$s=$t->snapshot();
$check($s['state']==='RECOVERED_WITH_DIFFERENT_APPLIED_VALUE'&&!$t->active(),'Stable two-pair read-only reconciliation releases old lease');
$check($s['recoveryResult']['requested']==='FFC2F740'&&$s['recoveryResult']['actuallyObserved']==='FFC2F700'
    &&$s['recoveryResult']['originalResult']==='FAIL / UNKNOWN_OUTCOME','No retroactive VERIFIED');
$check(!$s['authorized']&&$s['token']===''&&$s['sendAttempts']===0,'No old authorization or send reused');
$throws(fn()=>$t->prepareSend($on,$now,false,$j),'Resolved recovery can never send');
$check(TransactionalWrite::restart($j)->snapshot()['state']==='RECOVERED_WITH_DIFFERENT_APPLIED_VALUE','Resolved journal restart stays resolved without write');
foreach(['exclusiveUARTOwner'=>false,'ownerRevision'=>'other','binding'=>'other','realConnectionActive'=>false,'transportCorrelationSafe'=>false,'correlationSafeAndIdle'=>false]as$key=>$bad){
    $ctx=$c;$ctx[$key]=$bad;$throws(fn()=>$start($historical,$ctx),'Recovery prerequisite denied '.$key);
}
[$t,$j]=$start($historical,$c);$t->observe($off,$now,$j);$bad=$on;$bad['exclusiveUARTOwner']=false;$t->observe($bad,$now,$j);
$check($t->snapshot()['state']==='UNKNOWN_OUTCOME'&&$t->active(),'Foreign owner during reconnect stays locked');
foreach(['counter','eurid','unstable','session']as$badCase){
    [$t,$j]=$start($historical,$c);$t->observe($off,$now,$j);$t->observe($on,$now,$j);
    if($badCase==='unstable')$readPair($t,$j,$on,$version,$base);
    $ver=$version;$id=$base;$ctx=$on;
    if($badCase==='counter')$id=$reply('00FFC2F700','08');
    if($badCase==='unstable')$id=$reply('00FFC2F780','09');
    if($badCase==='eurid'){$d=ESP3Codec::parseFrame(hex2bin($version))['data'];$ver=$reply(bin2hex(str_replace(hex2bin('01020304'),hex2bin('DEADBEEF'),$d)));}
    if($badCase==='session'){$e=$t->nextRead($on,$now,$j);$ctx['session']='stale';$t->receive($e['token'],$e['operation'],'RESPONSE',$version,$ctx,$now,$j);}
    else $readPair($t,$j,$ctx,$ver,$id);
    $check($t->snapshot()['state']==='UNKNOWN_OUTCOME'&&$t->snapshot()['permanentFailure'],'Recovery contradiction '.$badCase);
}
[$t,$j]=$start($historical,$c);$check(TransactionalWrite::restart($j)->snapshot()['state']==='UNKNOWN_OUTCOME','Crash during recovery stays locked');
$broken=$memory();$broken->append($historical);$broken->broken=true;$t=new TransactionalWrite($historical);
$throws(fn()=>$t->administrativeRecover(101,$historical['transactionID'],$c,$now,$broken),'Corrupt journal blocks administrative operation');
$changed=$historical;$changed['reads']['CO_RD_IDBASE']['values']['remainingWriteCyclesRawHex']='08';
$throws(fn()=>$start($changed,$c),'Unexpected durable counter blocks recovery');
$forged=new TransactionalWrite(['state'=>'PRE_WRITE_JOURNALED','target'=>'FFC2F740']);
$throws(fn()=>$forged->prepareSend($c,$now,false,$memory()),'Arbiter policy defense against bypassed UI');
$check(ESP3Codec::normalizeWritableBaseId('FFC2F700')==='FFC2F700','Correction requires a separate explicit new input');
$sourceUnknown=$c;$sourceUnknown['noUnknownOutcome']=false;
[$t,$j]=$start($historical,$sourceUnknown);
$check($t->snapshot()['state']==='ADMIN_RECOVERY_RECONNECT','Known failed intent may be reconciled only with separately safe transport');
foreach(['FFC2F701','FFC2F740','FFC2F77F','FFC2F7FF']as$bad){
    $throws(fn()=>ESP3Codec::buildWriteIdBaseRequest($bad),'Encoder rejects full unaligned examples '.$bad);
}
// After resolution only a wholly new aligned intent may be prepared (not sent).
[$t,$j]=$start($historical,$c);$t->observe($off,$now,$j);$t->observe($on,$now,$j);
$readPair($t,$j,$on,$version,$base);$readPair($t,$j,$on,$version,$base);$oldRecoveryID=$t->snapshot()['transactionID'];
$backup=['baseID'=>'FFC2F780','parentInstanceID'=>'200','binding'=>'binding','observedEURID'=>'01020304',
    'identityConfirmation'=>['confirmedAt'=>gmdate('c',$now),'session'=>'new','backupBaseID'=>'FFC2F780','eurid'=>'01020304']];
$t->begin(101,'FFC2F780',$on,$backup,$now,$j);$readPair($t,$j,$on,$version,$base);
$check($t->snapshot()['state']==='READY_FOR_CONFIRMATION'&&$t->snapshot()['transactionID']!==$oldRecoveryID
    &&!$t->snapshot()['authorized'],'Resolved lease permits only fresh independent aligned preparation');
$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);$t->confirm(101,$s['transactionID'],$s['token'],'FFC2F780',$on,$now,$j);
$readPair($t,$j,$on,$version,$base);
$check($t->prepareSend($on,$now,true,$j)===null&&$t->snapshot()['sendAttempts']===0,'B7.2 active barrier blocks even valid fresh aligned intent');
$noop=$memory();$t=new TransactionalWrite();$t->begin(101,'FFC2F700',$on,$backup,$now,$noop);$readPair($t,$noop,$on,$version,$base);
$check($t->snapshot()['state']==='NO_OP'&&$t->snapshot()['sendAttempts']===0,'Aligned no-op consumes no cycle');
echo 'PASS: '.$passed.' B7.2 alignment/recovery assertions'.PHP_EOL;
