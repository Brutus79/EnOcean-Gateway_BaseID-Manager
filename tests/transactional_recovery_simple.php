<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/TransactionalWrite.php';
require_once __DIR__.'/../libs/ESP3Codec.php';
require_once __DIR__.'/../libs/BaseIDPreflight.php';
require_once __DIR__.'/../libs/WriteJournal.php';
use EnOceanGatewayManager\Safety\TransactionalWrite;
use EnOceanGatewayManager\Safety\WriteJournal;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$n=0;$check=static function(bool$b,string$why)use(&$n):void{if(!$b)throw new RuntimeException($why);$n++;};
$throws=static function(callable$f,string$why)use($check):void{try{$f();}catch(Throwable){$check(true,$why);return;}$check(false,$why);};
$at=1000000000;
$ctx=['arbiterID'=>200,'binding'=>'synthetic-port','session'=>'before','ownerRevision'=>'synthetic-owner',
    'realConnectionActive'=>true,'correlationSafeAndIdle'=>true,'noUnknownOutcome'=>true,'exclusiveUARTOwner'=>true];
$frame=static function(string$data,string$optional=''):string{$h=pack('nCC',strlen($data),strlen($optional),2);return strtoupper(bin2hex("\x55".$h.chr(ESP3Codec::crc8($h)).$data.$optional.chr(ESP3Codec::crc8($data.$optional))));};
$version=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SIMULATOR',16,"\0"));
$base=static fn(string$id,string$count):string=>$frame(hex2bin('00'.$id),hex2bin($count));
$read=static function(TransactionalWrite$t,WriteJournal$j,array$c,int$now,string$v,string$b):void{
    foreach([$v,$b]as$hex){$r=$t->nextRead($c,$now,$j);if($r===null)throw new RuntimeException('Expected correlated read');$t->receive($r['token'],$r['operation'],'RESPONSE',$hex,$c,$now,$j);}
};
$prepare=static function(string$count='08')use($ctx,$at,$version,$base,$read):array{
    $j=new class implements WriteJournal{private array$r=[];public function records():array{return$this->r;}public function append(array$r):void{$this->r[]=$r;}};
    $backup=['baseID'=>'FF900080','parentInstanceID'=>'200','binding'=>$ctx['binding'],'observedEURID'=>'01020304',
        'identityConfirmation'=>['confirmedAt'=>gmdate('c',$at),'session'=>$ctx['session'],'backupBaseID'=>'FF900080','eurid'=>'01020304']];
    $t=new TransactionalWrite();$t->begin(101,'FF900000',$ctx,$backup,$at,$j);$read($t,$j,$ctx,$at,$version,$base('FF900080',$count));
    $s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);$t->confirm(101,$s['transactionID'],$s['token'],'FF900000',$ctx,$at,$j);
    $read($t,$j,$ctx,$at,$version,$base('FF900080',$count));return[$t,$j];
};
foreach([
    ['FF900000','07','VERIFIED'],['FF900080','08','RECOVERY_NOT_APPLIED'],
    ['FF900000','08','UNKNOWN_OUTCOME'],['FF900080','07','UNKNOWN_OUTCOME'],
    ['FF900100','07','UNKNOWN_OUTCOME'],['FF900000','06','UNKNOWN_OUTCOME'],
    ['FF900000','','UNKNOWN_OUTCOME'],
]as[$id,$counter,$expected]){
    [$t,$j]=$prepare();$check(is_string($t->prepareSend($ctx,$at,false,$j)),'one pure simulated effect');
    $t->observe($ctx,$at+6,$j);$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','lost response is not success');
    $intent=$t->snapshot()['transactionID'];$t->recover(101,$intent,$j);
    $off=$ctx;$off['realConnectionActive']=false;$t->observe($off,$at+7,$j);
    $next=$ctx;$next['session']='after';$t->observe($next,$at+8,$j);$read($t,$j,$next,$at+8,$version,$base($id,$counter));
    $check($t->snapshot()['state']===$expected,'fresh Base-ID/counter comparison '.$id.'/'.$counter);
    $check($t->snapshot()['sendAttempts']===1&&$t->snapshot()['transactionID']===$intent,'recovery never creates another attempt');
    $check($t->classification()['newTransactionStructurallyAllowed']===($expected!=='UNKNOWN_OUTCOME'),'only conclusive results release the transaction');
    $throws(fn()=>$t->prepareSend($next,$at+8,false,$j),'resolved or unknown old intent cannot resend');
}
// Without RET_OK, fresh measured state still determines the outcome; unlimited
// counters are handled as FF, never as a fictitious decrement from 255 to 254.
foreach([['FF900000','VERIFIED'],['FF900080','RECOVERY_NOT_APPLIED']]as[$id,$expected]){
    [$t,$j]=$prepare('FF');$t->prepareSend($ctx,$at,false,$j);$t->unknown('simulated missing response',$j);
    $t->recover(101,$t->snapshot()['transactionID'],$j);$off=$ctx;$off['realConnectionActive']=false;$t->observe($off,$at+7,$j);
    $next=$ctx;$next['session']='after';$t->observe($next,$at+8,$j);$read($t,$j,$next,$at+8,$version,$base($id,'FF'));
    $check($t->snapshot()['state']===$expected,'unlimited counter remains unlimited');
}
[$t,$j]=$prepare();$t->prepareSend($ctx,$at,false,$j);$t->unknown('simulated missing response',$j);$t->recover(101,$t->snapshot()['transactionID'],$j);
$next=$ctx;$next['session']='after';$t->observe($next,$at+7,$j);
$check($t->snapshot()['state']==='FORCE_RECONNECT'&&$t->nextRead($next,$at+7,$j)===null,'new session alone is not disconnect evidence');
$off=$ctx;$off['realConnectionActive']=false;$t->observe($off,$at+8,$j);$t->observe($ctx,$at+9,$j);
$check($t->snapshot()['state']==='FORCE_RECONNECT','disconnect alone without a new session is insufficient');
foreach(['binding'=>'changed-port','exclusiveUARTOwner'=>false]as$key=>$value){
    [$t,$j]=$prepare();$t->prepareSend($ctx,$at,false,$j);$t->unknown('simulated missing response',$j);$t->recover(101,$t->snapshot()['transactionID'],$j);
    $t->observe($off,$at+7,$j);$bad=$next;$bad[$key]=$value;$t->observe($bad,$at+8,$j);
    $check($t->snapshot()['state']==='UNKNOWN_OUTCOME','recovery rejects changed '.$key);
}
[$t,$j]=$prepare();$t->prepareSend($ctx,$at,false,$j);$t->unknown('simulated missing response',$j);$t->recover(101,$t->snapshot()['transactionID'],$j);
$t->observe($off,$at+7,$j);$t->observe($next,$at+8,$j);
$wrongVersion=$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SIMULATOR',16,"\0"));
$read($t,$j,$next,$at+8,$wrongVersion,$base('FF900000','07'));
$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','matching Base-ID/counter on a different chip is not recovery');
[$t,$j]=$prepare();$t->prepareSend($ctx,$at,false,$j);$t->writeResponse($frame("\0"),$at,$j);
$check($t->snapshot()['state']==='FORCE_RECONNECT','RET_OK alone is not verified success');
[$t,$j]=$prepare();$check($t->prepareSend($ctx,$at,true,$j)===null&&$t->snapshot()['sendAttempts']===0,'real build barrier keeps transport closed');
[$t,$j]=$prepare();$check($t->prepareSend($ctx,$at-1,false,$j)===null&&$t->snapshot()['state']==='CANCELLED','future-dated final reads reject a backwards clock step');
[$t,$j]=$prepare();$t->prepareSend($ctx,$at,false,$j);$t->unknown('simulated missing response',$j);$t->recover(101,$t->snapshot()['transactionID'],$j);
$off=$ctx;$off['realConnectionActive']=false;$t->observe($off,$at+7,$j);$t->observe($next,$at+8,$j);
$r=$t->nextRead($next,$at+8,$j);$t->receive($r['token'],$r['operation'],'RESPONSE',$version,$next,$at+8,$j);
$r=$t->nextRead($next,$at+8,$j);$t->receive($r['token'],$r['operation'],'RESPONSE',$base('FF900000','07'),$next,$at+7,$j);
$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','backwards clock during recovery cannot validate a future identity read');
echo "PASS: {$n} focused read-only recovery/clock checks; no IPS or hardware transport\n";
