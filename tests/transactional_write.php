<?php
declare(strict_types=1);
use EnOceanGatewayManager\Safety\TransactionalWrite;
use EnOceanGatewayManager\Safety\WriteJournal;
use EnOceanGatewayManager\Safety\DurableWriteJournal;
use EnOceanGatewayManager\Protocol\ESP3Codec;
require_once __DIR__ . '/../libs/ESP3Codec.php';
require_once __DIR__ . '/../libs/ESP3StreamParser.php';
require_once __DIR__ . '/../libs/BaseIDPreflight.php';
require_once __DIR__ . '/../libs/WriteJournal.php';
require_once __DIR__ . '/../libs/TransactionalWrite.php';

$passed = 0;
$check = static function(bool $ok, string $name) use (&$passed): void { if (!$ok) { throw new RuntimeException($name); } $passed++; };
$throws = static function(callable $f, string $name) use ($check): void { try { $f(); } catch (Throwable) { $check(true, $name); return; } $check(false, $name); };
$memory = static fn (): WriteJournal => new class implements WriteJournal {
    public array $rows = []; public bool $fail = false;
    public function append(array $r): void { if ($this->fail) { throw new RuntimeException('fsync failure'); } $this->rows[] = $r; }
    public function records(): array { return $this->rows; }
};
$now = 1000000000;
$context = ['arbiterID' => 200, 'binding' => 'fingerprint', 'session' => 'session-one', 'ownerRevision' => 'revision',
    'realConnectionActive' => true, 'correlationSafeAndIdle' => true, 'noUnknownOutcome' => true, 'exclusiveUARTOwner' => true];
$backup = ['baseID' => 'FFC2F780', 'parentInstanceID' => '200', 'binding' => 'fingerprint', 'observedEURID' => '01020304',
    'identityConfirmation' => ['confirmedAt' => gmdate('c', $now), 'session' => 'session-one', 'backupBaseID' => 'FFC2F780', 'eurid' => '01020304']];
$version = '55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F';
$frame = static function(string $data, string $optional = ''): string { $d=hex2bin($data);$o=hex2bin($optional);$h=pack('nCC',strlen($d),strlen($o),2);return strtoupper(bin2hex("\x55".$h.chr(ESP3Codec::crc8($h)).$d.$o.chr(ESP3Codec::crc8($d.$o)))); };
$old = $frame('00FFC2F780', '0A'); $new = $frame('00FFC2F700', '09'); $ok = $frame('00');
$reads = static function(TransactionalWrite $t, WriteJournal $j, array $c, int $at, string $v, string $id): void {
    foreach ([$v, $id] as $reply) { $effect=$t->nextRead($c,$at,$j); if ($effect===null) { throw new RuntimeException('Read effect missing: '.$t->snapshot()['state']); } $t->receive($effect['token'],$effect['operation'],'RESPONSE',$reply,$c,$at,$j); }
};
$ready = static function() use($memory,$context,$backup,$now,$reads,$version,$old): array {
    $j=$memory();$t=new TransactionalWrite();$t->begin(101,'FFC2F700',$context,$backup,$now,$j);$reads($t,$j,$context,$now,$version,$old);return [$t,$j];
};
$prepared = static function() use($ready,$context,$now,$reads,$version,$old): array {
    [$t,$j]=$ready();$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);$t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$context,$now,$j);$reads($t,$j,$context,$now,$version,$old);return [$t,$j];
};
[$t,$j]=$ready();$s=$t->snapshot();
$check($s['state']==='READY_FOR_CONFIRMATION' && strlen($s['token'])===64, 'Fresh preflight yields 256-bit transaction-bound challenge');
$throws(fn()=> $t->begin(101,'FFC2F700',$context,$backup,$now,$j),'Lease exclusive');
$throws(fn()=> $t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$context,$now,$j),'Stage B without stage A rejected');
foreach (['session'=>'other','binding'=>'other','arbiterID'=>999,'ownerRevision'=>'other','exclusiveUARTOwner'=>false,'realConnectionActive'=>false,'noUnknownOutcome'=>false] as $key=>$value) {
    [$t,$j]=$ready();$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);$bad=$context;$bad[$key]=$value;
    $throws(fn()=> $t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$bad,$now,$j),'Changed confirmation context '.$key);
    $check($t->snapshot()['state']==='CANCELLED','Changed context discards confirmation');
}
foreach (['FFC2F780','FFFFFF00'] as $wrongTarget) {
    [$t,$j]=$ready();$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);
    $throws(fn()=> $t->confirm(101,$s['transactionID'],$s['token'],$wrongTarget,$context,$now,$j),'Wrong target confirmation');
}
[$t,$j]=$ready();$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);
$throws(fn()=> $t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$context,$now+61,$j),'Token timeout');
[$t,$j]=$ready();$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);$t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$context,$now,$j);
$throws(fn()=> $t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$context,$now,$j),'Token replay');
foreach ([$frame('00FFC2F780','09'),$frame('00FFC2F600','0A')] as $changed) {
    [$t,$j]=$ready();$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);$t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$context,$now,$j);
    $reads($t,$j,$context,$now,$version,$changed);$check($t->snapshot()['state']==='CANCELLED','Fresh mandatory reads invalidate changed base/counter');
}
[$t,$j]=$prepared();$check($t->snapshot()['state']==='PRE_WRITE_JOURNALED' && $j->records()[array_key_last($j->records())]['journalStatus']==='PREPARED_NOT_SENT','Intent durably acknowledged before send state');
$check($t->prepareSend($context,$now,true,$j)===null && $t->snapshot()['sendAttempts']===0,'B6 barrier creates no send effect');
[$t,$j]=$prepared();$j->fail=true;$throws(fn()=> $t->prepareSend($context,$now,false,$j),'Journal failure prevents send effect');
[$t,$j]=$prepared();$bad=$context;$bad['exclusiveUARTOwner']=false;
$check($t->prepareSend($bad,$now,false,$j)===null,'Ownership UNKNOWN/multiple owner final gate');
[$t,$j]=$prepared();$bytes=$t->prepareSend($context,$now,false,$j);
$check($bytes==='5500050005DB07FFC2F700DC','Dynamic encoder produces offline expected frame');
$check($j->records()[array_key_last($j->records())]['journalStatus']==='MAY_HAVE_SENT' && $t->snapshot()['sendAttempts']===1,'Uncertainty fsynced before virtual transport accepts one frame');
$virtualSent=[];$virtualSent[]=$bytes;
$throws(fn()=> $t->prepareSend($context,$now,false,$j),'No second send effect');
$t->writeResponse($ok,$now,$j);$check($t->snapshot()['state']==='FORCE_RECONNECT','Lease held until actual disconnect/reconnect');
$t->observe($context,$now,$j);$check($t->snapshot()['state']==='FORCE_RECONNECT','Connected cache cannot fake reconnect');
$off=$context;$off['realConnectionActive']=false;$t->observe($off,$now,$j);
$reconnected=$context;$reconnected['session']='session-two';$t->observe($reconnected,$now,$j);
$check($t->snapshot()['state']==='POST_VERIFY','Observed reconnect changes session and permits only verification reads');
$reads($t,$j,$reconnected,$now,$version,$new);
$check($t->snapshot()['state']==='VERIFIED' && count($virtualSent)===1,'EURID, base and counter postverified with exactly one virtual send');

// Crash audit at each critical durable transition: no engine resumes writes.
[$t,$j]=$prepared();$t->prepareSend($context,$now,false,$j);$t->writeResponse($ok,$now,$j);
$records=$j->records();
foreach ($records as $i=>$record) {
    $crash=$memory();$crash->rows=array_slice($records,0,$i+1);$restart=TransactionalWrite::restart($crash);
    $check(in_array($restart->snapshot()['state'],['CANCELLED','UNKNOWN_OUTCOME'],true),'Crash transition '. $record['state']);
    $check(($restart->snapshot()['token']??'')==='' && !($restart->snapshot()['authorized']??false),'Old token/authorization invalid after restart');
    $throws(fn()=> $restart->prepareSend($context,$now,false,$crash),'No automatic write after restart');
}
$empty=$memory();$check(TransactionalWrite::restart($empty)->snapshot()['state']==='IDLE','Crash before first journal cannot resume a transaction');
foreach (['timeout','disconnect','badCRC'] as $failure) {
    [$t,$j]=$prepared();$t->prepareSend($context,$now,false,$j);
    if ($failure==='badCRC') { $t->writeResponse(substr($ok,0,-2).'FF',$now,$j); }
    else { $c=$context;if($failure==='disconnect'){$c['realConnectionActive']=false;} $t->observe($c,$now+6,$j); }
    $check($t->snapshot()['state']==='UNKNOWN_OUTCOME','Failure never retries '.$failure);
    $throws(fn()=> $t->prepareSend($context,$now,false,$j),'No retry for '.$failure);
    $t=TransactionalWrite::restart($j);$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','Restart preserves uncertainty '.$failure);
}
foreach ([$new,$old,$frame('00FFC2F700','0A'),$frame('00FFC2F600','09')] as $index=>$post) {
    [$t,$j]=$prepared();$t->prepareSend($context,$now,false,$j);$t->unknown('Lost response',$j);
    $s=$t->snapshot();$t->recover(101,$s['transactionID'],$j);$t->observe($off,$now,$j);$t->observe($reconnected,$now,$j);$reads($t,$j,$reconnected,$now,$version,$post);
    $expected=match($index){0=>'VERIFIED',1=>'RECOVERY_NOT_APPLIED',default=>'UNKNOWN_OUTCOME'};
    $check($t->snapshot()['state']===$expected,'Recovery cases A/B/C '.$index);
    $throws(fn()=> $t->prepareSend($reconnected,$now,false,$j),'Recovery never retries');
    if($index>=2){$throws(fn()=> $t->recover(101,$s['transactionID'],$j),'Contradictory state remains permanently locked');}
}
$noop=$memory();$t=new TransactionalWrite();$t->begin(101,'FFC2F780',$context,$backup,$now,$noop);$reads($t,$noop,$context,$now,$version,$old);
$check($t->snapshot()['state']==='NO_OP' && ($t->snapshot()['sendAttempts']??0)===0,'No-op consumes no write');
$check($t->snapshot()['preview']['expectedRemaining']===10,'No-op UI preserves unchanged hardware counter');
[$t,$j]=$ready();$t->observe($context,$now+61,$j);$check($t->snapshot()['state']==='CANCELLED','Lease expires');
// Late/wrong operation packets cannot fill a different pending read.
[$t,$j]=$ready();$before=$t->snapshot();$t->receive('old-token','CO_RD_VERSION','RESPONSE',$version,$context,$now,$j);$check($t->snapshot()===$before,'Late token ignored');
$dir=sys_get_temp_dir().'/egm-durable-'.bin2hex(random_bytes(8));$disk=new DurableWriteJournal($dir);
$disk->append(['state'=>'PRE_WRITE_JOURNALED','transactionID'=>'test','journalStatus'=>'PREPARED_NOT_SENT']);
$again=new DurableWriteJournal($dir);$check($again->records()[0]['journalStatus']==='PREPARED_NOT_SENT','Fsynced journal survives newly constructed reader');
$check(TransactionalWrite::restart($again)->snapshot()['state']==='CANCELLED','Disk journal restart discards old lease');
$code=file_get_contents(__DIR__.'/../ESP3TransportArbiter/module.php');
$check(substr_count($code,'$this->SendDataToParent(')===2,'Exactly two Parent send sites: read-only and private transactional write');
$check(str_contains($code,'private function sendPreparedWrite()') && str_contains($code,'private const B6_HARDWARE_WRITE_BARRIER = false;'),'Single private write site enabled for the final product, all policy gates retained');
$parser = new \EnOceanGatewayManager\Protocol\ESP3StreamParser();
[$t,$j]=$prepared();$t->prepareSend($context,$now,false,$j);
$part=substr(hex2bin($ok),0,4);$check($parser->feed($part)===[] && $parser->bufferedBytes()===4,'Fragmented write response waits for complete CRC-validated frame');
$events=$parser->feed(substr(hex2bin($ok),4));$t->writeResponse($events[0]['rawHex'],$now,$j);
$check($t->snapshot()['state']==='FORCE_RECONNECT','Complete fragmented response accepted once');
$throws(fn()=> $t->writeResponse($ok,$now,$j),'Late duplicate response cannot be reused');
[$t,$j]=$prepared();$t->prepareSend($context,$now,false,$j);$t->writeResponse($ok,$now,$j);$t->observe($off,$now,$j);$t->observe($reconnected,$now,$j);
$request=$t->nextRead($reconnected,$now,$j);$t->receive($request['token'],$request['operation'],'RESPONSE',$ok,$reconnected,$now,$j);
$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','Old generic write response after reconnect cannot satisfy version postverification');
$altered=ESP3Codec::parseFrame(hex2bin($version))['data'];$altered=str_replace(hex2bin('01020304'),hex2bin('DEADBEEF'),$altered);$otherVersion=$frame(bin2hex($altered));
foreach (['prewrite','postwrite'] as $where) {
    if($where==='prewrite') {[$t,$j]=$ready();$s=$t->snapshot();$t->authorize(101,$s['transactionID'],$j);$t->confirm(101,$s['transactionID'],$s['token'],'FFC2F700',$context,$now,$j);$c=$context;}
    else {[$t,$j]=$prepared();$t->prepareSend($context,$now,false,$j);$t->writeResponse($ok,$now,$j);$t->observe($off,$now,$j);$t->observe($reconnected,$now,$j);$c=$reconnected;}
    $reads($t,$j,$c,$now,$otherVersion,$new);$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','Changed EURID '.$where);
}
$wrongPacket=ESP3Codec::buildReadRequest('CO_RD_VERSION');
[$t,$j]=$prepared();$t->prepareSend($context,$now,false,$j);$t->writeResponse(strtoupper(bin2hex($wrongPacket)),$now,$j);
$check($t->snapshot()['state']==='UNKNOWN_OUTCOME','Wrong response packet type fails closed');
$broken=new DurableWriteJournal(sys_get_temp_dir().'/egm-torn-'.bin2hex(random_bytes(8)));
$broken->append(['state'=>'IDLE']);$info=new ReflectionProperty($broken,'directory');$brokenDir=$info->getValue($broken);
file_put_contents($brokenDir.'/transactions.ndjson','{"partial":',FILE_APPEND);
$throws(fn()=> $broken->records(),'Torn WAL blocks recovery and is never silently truncated');
if (PHP_SAPI === 'cli') {
    $script='require $argv[1]."/libs/ESP3Codec.php";require $argv[1]."/libs/BaseIDPreflight.php";require $argv[1]."/libs/WriteJournal.php";require $argv[1]."/libs/TransactionalWrite.php";$j=new \\EnOceanGatewayManager\\Safety\\DurableWriteJournal($argv[2]);echo \\EnOceanGatewayManager\\Safety\\TransactionalWrite::restart($j)->snapshot()["state"];';
    $process=proc_open([PHP_BINARY,'-r',$script,dirname(__DIR__),$dir],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $check($exit===0 && $stdout==='CANCELLED' && $stderr==='','A separate PHP process recovers the durable journal without a write');
}
echo 'PASS: '.$passed.' B6 transactional assertions'.PHP_EOL;
