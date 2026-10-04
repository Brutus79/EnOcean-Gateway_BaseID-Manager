<?php
declare(strict_types=1);
// Test-only bridge: C2 five-round proof -> existing transactional policy ->
// in-memory sink. Not required by any module, no IPS/Parent/UART/send callback.
require_once __DIR__.'/../libs/C2Session.php';
require_once __DIR__.'/../libs/TransactionalWrite.php';
require_once __DIR__.'/../libs/BaseIDPreflight.php';
require_once __DIR__.'/../libs/WriteJournal.php';
require_once __DIR__.'/../libs/ESP3TransportArbiterCore.php';
require_once __DIR__.'/../libs/ESP3StreamParser.php';
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\TransactionalWrite;
use EnOceanGatewayManager\Safety\DurableWriteJournal;
use EnOceanGatewayManager\Safety\WriteJournal;
use EnOceanGatewayManager\Transport\ESP3TransportArbiterCore;
$count=0;$check=static function(bool$b,string$l)use(&$count):void{$count++;if(!$b)throw new RuntimeException($l);};
$throws=static function(callable$f,string$l)use($check):void{try{$f();}catch(Throwable){$check(true,$l);return;}$check(false,$l);};
$frame=static function(string$d,string$o=''):string{$h=pack('nCC',strlen($d),strlen($o),2);return"\x55".$h.chr(ESP3Codec::crc8($h)).$d.$o.chr(ESP3Codec::crc8($d.$o));};
$v=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SIMULATOR',16,"\0"));
$b=$frame(hex2bin('00FF900080'),"\x08");$new=$frame(hex2bin('00FF900000'),"\x07");$ok=$frame("\0");
$ctx=['session'=>'sim-C2-before','transportBinding'=>'sim-transport','handoffBinding'=>'sim-handoff','exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0,'writeLeaseActive'=>false,'noUnknownOutcome'=>true];
$bc=['arbiterID'=>200,'binding'=>'sim-transport','session'=>'sim-C2-before','ownerRevision'=>'sim-C2-binding',
 'realConnectionActive'=>true,'correlationSafeAndIdle'=>true,'noUnknownOutcome'=>true,'exclusiveUARTOwner'=>true];
$transport=static function(ESP3TransportArbiterCore$core,array$r,int$now,string$reply)use($check):array{
 $q=$core->enqueueMaintenance(['operation'=>$r['operation'],'ownerInstanceId'=>101,'token'=>$r['token'],
  'frameHex'=>ESP3Codec::toHex(ESP3Codec::buildReadRequest($r['operation'])),'timeoutMs'=>500],$now*1000);
 $check($q['accepted'],'existing arbiter schedules simulator read');
 $events=$core->receiveBytes($reply,$now*1000+1);
 foreach($events as$e)if($e['type']==='maintenance_result')return$e;
 throw new RuntimeException('Missing correlated arbiter read result');
};
$sync=static function(C2Session$s,array$c,int$now,string$v,string$b,ESP3TransportArbiterCore$core)use($check,$transport):void{
 for($i=0;$i<10;$i++){$r=$s->request($c,$now);$check($r!==null,'sequential C2 read');
  $e=$transport($core,$r,$now,$i%2?$b:$v);
  if(!$s->response($e['token'],$e['operation'],ESP3Codec::fromHex($e['frameHex']),$c,$now))throw new RuntimeException('C2 synchronization blocked');}
};
$readB6=static function(TransactionalWrite$t,WriteJournal$j,array$c,int$n,string$v,string$b,ESP3TransportArbiterCore$core)use($transport):void{
 foreach([$v,$b]as$f){$r=$t->nextRead($c,$n,$j);if(!$r)throw new RuntimeException('Missing transactional read');
  $e=$transport($core,$r,$n,$f);$t->receive($e['token'],$e['operation'],$e['outcome'],$e['frameHex'],$c,$n,$j);}
};
$ready=static function()use($ctx,$bc,$sync,$readB6,$v,$b,$check):array{
 $n=1000000000;$core=new ESP3TransportArbiterCore();$core->setMaintenanceEnabled(true);$core->setConnected(true,$n*1000);
 $s=new C2Session();$s->start($ctx,$n);$sync($s,$ctx,$n,$v,$b,$core);
 $review=$s->review('FF900000',$ctx,$n);$s->confirmA($review['token'],$n);$s->confirmB($review['token'],'FF900000',$ctx,$n);
 $sync($s,$ctx,$n,$v,$b,$core);$check($s->prewriteGate('FF900000',$ctx,$n),'full C2 prewrite proof');
 $j=new DurableWriteJournal(sys_get_temp_dir().'/egm-c2-sim-'.bin2hex(random_bytes(8)));
 $backup=['baseID'=>'FF900080','parentInstanceID'=>'200','binding'=>$bc['binding'],'observedEURID'=>'01020304',
  'identityConfirmation'=>['confirmedAt'=>gmdate('c',$n),'session'=>$bc['session'],'backupBaseID'=>'FF900080','eurid'=>'01020304']];
 $t=new TransactionalWrite();$t->begin(101,'FF900000',$bc,$backup,$n,$j);$readB6($t,$j,$bc,$n,$v,$b,$core);
 $p=$t->snapshot();$t->authorize(101,$p['transactionID'],$j);$t->confirm(101,$p['transactionID'],$p['token'],'FF900000',$bc,$n,$j);
 $readB6($t,$j,$bc,$n,$v,$b,$core);return[$s,$t,$j,$n,$core];
};
foreach(['normal','lost-response','badCRC','disconnect','wrong-post-base','wrong-post-counter','wrong-post-eurid','same-session','no-disconnect']as$fault){
 [$s,$t,$j,$n,$core]=$ready();$sink=[];
 $check($s->prewriteGate('FF900000',$ctx,$n),'C2 checked immediately before simulated send');
 $hex=$t->prepareSend($bc,$n,false,$j);$check(is_string($hex),'pure policy generates simulator effect');
 $rows=$j->records();$check(end($rows)['journalStatus']==='MAY_HAVE_SENT','durable WAL precedes simulator effect');
 $sink[]=$hex;$check(ESP3Codec::parseFrame(hex2bin($hex))['data']===hex2bin('07FF900000'),'simulated B to A frame');
 $rejected=$core->enqueueMaintenance(['operation'=>'CO_WR_IDBASE','ownerInstanceId'=>101,'token'=>'sim-write-rejection','frameHex'=>$hex,'timeoutMs'=>500],$n*1000);
 $check(!$rejected['accepted']&&$rejected['actions']===[],'real read arbiter still rejects simulator write injection');
 $throws(fn()=>$t->prepareSend($bc,$n,false,$j),'no second simulated attempt');
 if($fault==='lost-response')$t->observe($bc,$n+6,$j);
 elseif($fault==='badCRC')$t->writeResponse(bin2hex(substr($ok,0,-1).chr(ord($ok[-1])^1)),$n,$j);
 elseif($fault==='disconnect'){$off=$bc;$off['realConnectionActive']=false;$t->observe($off,$n,$j);}
 else $t->writeResponse(bin2hex($ok),$n,$j);
 if(in_array($fault,['lost-response','badCRC','disconnect'],true)){
  $check($t->snapshot()['state']==='UNKNOWN_OUTCOME','uncertainty is latched');
  $t=TransactionalWrite::restart($j);$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','restart preserves unknown, never resumes write');
  $throws(fn()=>$t->prepareSend($bc,$n,false,$j),'unknown cannot retry');
  $t->recover(101,$t->snapshot()['transactionID'],$j);
 }
 $off=$bc;$off['realConnectionActive']=false;$next=$bc;$next['session']='sim-C2-after';
 if($fault!=='no-disconnect'){$core->setConnected(false,($n+7)*1000);$t->observe($off,$n+7,$j);}
 $core->setConnected(true,($n+7)*1000+1);
 $t->observe($fault==='same-session'?$bc:$next,$n+7,$j);
 if(in_array($fault,['same-session','no-disconnect'],true)){
  $check($t->snapshot()['state']==='FORCE_RECONNECT','reconnect evidence cannot be guessed');continue;
 }
 $check($t->snapshot()['state']==='POST_VERIFY','real disconnect plus new session mandatory');
 // Fresh C2 synchronization after reconnect, no old snapshot reuse. Full five
 // rounds protect postverification too, before passing values to legacy policy.
 $post=new C2Session();$postctx=$ctx;$postctx['session']=$next['session'];$post->start($postctx,$n+7);
 $pv=$fault==='wrong-post-eurid'?str_replace(hex2bin('01020304'),hex2bin('01020305'),$v):$v;
 // Recompute CRC for different EURID (not a malformed-frame test).
 if($fault==='wrong-post-eurid')$pv=$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SIMULATOR',16,"\0"));
 $pb=match($fault){'wrong-post-base'=>$b,'wrong-post-counter'=>$frame(hex2bin('00FF900000'),"\x08"),default=>$new};
 $sync($post,$postctx,$n+8,$pv,$pb,$core);$readB6($t,$j,$next,$n+8,$pv,$pb,$core);
 $expected=in_array($fault,['wrong-post-base','wrong-post-counter','wrong-post-eurid'],true)?'UNKNOWN_OUTCOME':'VERIFIED';
 $check($t->snapshot()['state']===$expected,'postverification '.$fault);
 $check(count($sink)===1&&$t->snapshot()['sendAttempts']===1,'one simulator attempt, no hardware sends');
 $throws(fn()=>$t->prepareSend($next,$n+8,false,$j),'postverification never retries');
 foreach($j->records()as$at=>$row){
  $memory=new class(array_slice($j->records(),0,$at+1))implements WriteJournal{
   public function __construct(private array$r){}public function append(array$r):void{$this->r[]=$r;}public function records():array{return$this->r;}};
  $restart=TransactionalWrite::restart($memory);
  $throws(fn()=>$restart->prepareSend($bc,$n,false,$memory),'every durable crash cut blocks send resume');
 }
}
[$s,$t,$j,$n]=$ready();$bad=$ctx;$bad['faultEpoch']=1;
$check(!$s->prewriteGate('FF900000',$bad,$n),'warning prevents simulator bridge');
$check($t->snapshot()['sendAttempts']===0,'blocked bridge has no send effect');
[$s,$t,$j,$n]=$ready();$check(!$s->prewriteGate('FF900000',$ctx,$n+61),'C2 freshness not relaxed for simulator');
$check($t->snapshot()['sendAttempts']===0,'stale bridge never sends');
echo "PASS: C2 full simulated write/WAL/reconnect/postverification {$count} assertions\n";
