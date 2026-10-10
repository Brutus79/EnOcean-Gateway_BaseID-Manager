<?php
declare(strict_types=1);
// Focused A-H regression. Synthetic data and virtual time only; no I/O sink.
require_once __DIR__.'/../libs/C2Session.php';
require_once __DIR__.'/../libs/C2BlockedGate.php';
require_once __DIR__.'/../libs/ESP3TransportArbiterCore.php';
require_once __DIR__.'/../libs/ESP3StreamParser.php';
require_once __DIR__.'/../libs/TransactionalWrite.php';
require_once __DIR__.'/../libs/WriteJournal.php';
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Maintenance\C2BlockedGate;
use EnOceanGatewayManager\Transport\ESP3TransportArbiterCore;
use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\TransactionalWrite;
use EnOceanGatewayManager\Safety\WriteJournal;
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
$frame=static function(string $d,string $o='',int $type=2):string{
    $h=pack('nCC',strlen($d),strlen($o),$type);return "\x55".$h.chr(ESP3Codec::crc8($h)).$d.$o.chr(ESP3Codec::crc8($d.$o));
};
$v=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SYNTHETIC',16,"\0"));
$b=$frame(hex2bin('00FF900000'),"\x08");
$rf=$frame(hex2bin('F6000102030430'),hex2bin('03FFFFFFFF5500'),1);
$ctx=['session'=>'synthetic-session','transportBinding'=>'synthetic-transport','handoffBinding'=>'synthetic-handoff',
    'exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0,'writeLeaseActive'=>false,'noUnknownOutcome'=>true];
// Execute the actual adapter expressions rather than reimplementing its idle
// classification. Assert semantic identity with the previously strict gate.
$source=file_get_contents(__DIR__.'/../ESP3TransportArbiter/module.php');
preg_match('/\$otherwiseIdle = (.*?);\n/s',$source,$match);$otherwise=$match[1]??throw new RuntimeException('Missing predicate');
preg_match('/\$idle = (.*?);\n/s',$source,$match);$idleExpr=$match[1]??throw new RuntimeException('Missing idle');
preg_match("/'incomingOnlyBusy' => (.*?),\n/",$source,$match);$busyExpr=$match[1]??throw new RuntimeException('Missing busy');
$context=static function(array $state,int $epoch=0)use($ctx,$otherwise,$idleExpr,$busyExpr):array{
    $otherwiseIdle=eval('return '.$otherwise.';');$idle=eval('return '.$idleExpr.';');
    return ['session'=>$ctx['session'],'binding'=>$ctx['transportBinding'],'realConnectionActive'=>$state['connected'],
        'transportCorrelationSafe'=>!$state['correlationUnsafe'],'correlationSafeAndIdle'=>$idle,
        'incomingOnlyBusy'=>eval('return '.$busyExpr.';'),'exclusiveUARTOwner'=>true,'uartDescriptorCount'=>1,
        'writeLeaseActive'=>false,'noUnknownOutcome'=>true,'communicationFaultEpoch'=>$epoch];
};
$oldIdle=static fn(array $state):bool=>!($state['correlationUnsafe']??true)&&($state['activeToken']??null)===null
    &&($state['maintenanceQueueItems']??1)===0&&($state['nativeQueueItems']??1)===0
    &&($state['nativeResponseDebt']??1)===0&&($state['incomingBufferedBytes']??1)===0&&($state['outgoingBufferedBytes']??1)===0;
$ready=static function()use($ctx,$v,$b,$check):array{
    $s=new C2Session();$s->start($ctx,100);$core=new ESP3TransportArbiterCore();$core->setConnected(true,100000);$core->setMaintenanceEnabled(true);
    for($i=0;$i<20;$i++){
        $at=101+$i*.1;
        if($i===10){$r=$s->review('FF900080',$ctx,$at);$s->confirmA($r['token'],$at);$s->confirmB($r['token'],'FF900080',$ctx,$at);}
        $r=$s->request($ctx,$at);$q=$core->enqueueMaintenance(['operation'=>$r['operation'],'ownerInstanceId'=>1,'token'=>$r['token'],
            'frameHex'=>ESP3Codec::toHex(ESP3Codec::buildReadRequest($r['operation'])),'timeoutMs'=>500],(int)($at*1000));
        $check($q['accepted'],'read accepted');
        foreach($core->receiveBytes($i%2?$b:$v,(int)($at*1000)+1)as$a)if($a['type']==='maintenance_result')
            $check($s->response($a['token'],$a['operation'],ESP3Codec::fromHex($a['frameHex']),$ctx,$at+.001),'consistent response');
    }
    $check($s->prewriteGate('FF900080',$ctx,104),'five prewrite pairs completed');return[$s,$core];
};
$observe=static function(C2Session $s,array $actual,float $now,string $handoff='synthetic-handoff',mixed $pending=null)use($ctx):string{
    if(!$s->prewriteGate('FF900080',$ctx,$now))return 'FAULT';
    try{return C2BlockedGate::observe($actual,$ctx,$handoff,$pending);}
    catch(RuntimeException $e){$s->fault($e->getMessage(),$now);return 'FAULT';}
};
[$s,$core]=$ready();$proof=$s->state();
$check($observe($s,$context($core->state()),104)==='IDLE'&&$s->state()===$proof,'A idle valid');
// Unknown incomplete headers remain non-sendable too. Their eventual CRC or
// unexpected-response outcome is NOT pre-accepted by the busy classification.
foreach([1,5,6,10]as$split){
    $core->receiveBytes(substr($rf,0,$split),105000);
    $busy=$context($core->state());
    $check($observe($s,$busy,105)==='INCOMING_BUSY'&&!$busy['correlationSafeAndIdle'],'B fragmented RF not sendable');
    $check($s->state()===$proof,'B no latch or confirmation/proof mutation');
    $actions=$core->receiveBytes(substr($rf,$split),105001);
    $check(count($actions)===1&&$actions[0]['type']==='send_native_child','C completed RF processed');
    $check($observe($s,$context($core->state()),106)==='IDLE'&&$s->state()===$proof,'C proof resumes without rereads');
}
for($i=0;$i<64;$i++){
    $now=104+$i*31536000.0;$core->receiveBytes(substr($rf,0,6),(int)($now*1000));
    $check($observe($s,$context($core->state()),$now)==='INCOMING_BUSY','D/H repeated RF after long virtual time');
    $core->receiveBytes(substr($rf,6),(int)($now*1000)+1);
    $check($observe($s,$context($core->state()),$now+.001)==='IDLE'&&$s->state()===$proof,'D/H unchanged proof, no TTL');
    $check($s->request($ctx,$now)===null,'D/H no automatic extra reads');
}
// E: Existing adapter increments the warning epoch for parser warnings; that
// real fault remains decisive even if parsing has emptied the input buffer.
[$s,$core]=$ready();$core->receiveBytes(substr($rf,0,6),105000);
$corrupt=substr($rf,6,-1).chr(ord($rf[-1])^1);$actions=$core->receiveBytes($corrupt,105001);
$warnings=array_filter($actions,fn($a)=>$a['type']==='diagnostic'&&in_array($a['level'],['warning','error'],true));
$check(count($warnings)>0,'E corrupt data emits actual parser warning');
$check($observe($s,$context($core->state(),count($warnings)),106)==='FAULT'&&$s->state()['confirmation']===null,'E warning invalidates proof');
$check($observe($s,$context($core->state()),107)==='FAULT','E restoring healthy input never clears latch');
foreach(['header_crc','unsolicited_response']as$fault){
    [$s,$core]=$ready();
    $bytes=$fault==='header_crc'?substr($rf,0,5).chr(ord($rf[5])^1):$b;
    $actions=$core->receiveBytes($bytes,105000);
    $warnings=array_filter($actions,fn($a)=>$a['type']==='diagnostic'&&in_array($a['level'],['warning','error'],true));
    $check(count($warnings)>0&&$observe($s,$context($core->state(),count($warnings)),106)==='FAULT','E '.$fault.' still invalidates');
}
[$s,$core]=$ready();$core->receiveBytes(substr($rf,0,6),105000);
$check($observe($s,$context($core->state()),1e12)==='INCOMING_BUSY','incomplete unsolicited frame alone never becomes a timing proof');
$check(!$context($core->state())['correlationSafeAndIdle'],'incomplete frame remains unsendable indefinitely');
foreach(['exclusiveUARTOwner'=>false,'uartDescriptorCount'=>2,'session'=>'new','binding'=>'changed',
    'realConnectionActive'=>false,'transportCorrelationSafe'=>false,'noUnknownOutcome'=>false,
    'writeLeaseActive'=>true,'communicationFaultEpoch'=>1]as$key=>$value){
    [$s,$core]=$ready();$core->receiveBytes(substr($rf,0,6),105000);$actual=$context($core->state());$actual[$key]=$value;
    $check($observe($s,$actual,106)==='FAULT'&&$s->state()['confirmation']===null,'F busy never masks '.$key);
}
foreach(['handoff','pending']as$change){
    [$s,$core]=$ready();$core->receiveBytes(substr($rf,0,6),105000);
    $check($observe($s,$context($core->state()),106,$change==='handoff'?'changed':$ctx['handoffBinding'],$change==='pending'?['token'=>'x']:null)==='FAULT','F '.$change);
}
foreach(['activeToken'=>'active','maintenanceQueueItems'=>1,'nativeQueueItems'=>1,'nativeResponseDebt'=>1,
    'outgoingBufferedBytes'=>1,'correlationUnsafe'=>true]as$key=>$value){
    [$s,$core]=$ready();$state=$core->state();$state['incomingBufferedBytes']=6;$state[$key]=$value;$actual=$context($state);
    $check($actual['correlationSafeAndIdle']===$oldIdle($state),'unchanged strict idle for '.$key);
    $check(!$actual['incomingOnlyBusy']&&$observe($s,$actual,106)==='FAULT','F incoming plus '.$key.' is not benign');
}
[$s,$core]=$ready();$state=$core->state();
foreach([0,1,6,16]as$n){$state['incomingBufferedBytes']=$n;$check($context($state)['correlationSafeAndIdle']===$oldIdle($state),'unchanged strict incoming idle '.$n);}
// Expected read timeout remains active independently of benign RF occupancy.
[$s,$core]=$ready();$q=$core->enqueueMaintenance(['operation'=>'CO_RD_IDBASE','ownerInstanceId'=>1,'token'=>'timeout-test',
    'frameHex'=>ESP3Codec::toHex(ESP3Codec::buildReadRequest('CO_RD_IDBASE')),'timeoutMs'=>500],105000);
$check($q['accepted'],'expected timeout read accepted');
$core->receiveBytes(substr($rf,0,6),105001);$core->tick(105501);
$check($core->state()['correlationUnsafe']&&$observe($s,$context($core->state()),106)==='FAULT','E expected response timeout fail-closed');
// G: Exercise the UNCHANGED actual final-send policy without any transport sink.
[$s,$core]=$ready();$core->receiveBytes(substr($rf,0,6),105000);$actual=$context($core->state());
$actual+=['arbiterID'=>200,'ownerRevision'=>'synthetic-owner'];
$journal=new class implements WriteJournal{private array $rows=[];public function append(array $r):void{$this->rows[]=$r;}public function records():array{return$this->rows;}};
$t=new TransactionalWrite(['state'=>'PRE_WRITE_JOURNALED','target'=>'FF900080',
    'frameHex'=>ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest('FF900080')),'session'=>$actual['session'],
    'binding'=>$actual['binding'],'parentID'=>200,'ownerRevision'=>'synthetic-owner','expiresAt'=>200,
    'authorized'=>true,'confirmationHash'=>'synthetic-confirmed','sendAttempts'=>0,'journalStatus'=>'PREPARED_NOT_SENT','reads'=>[]]);
$check($t->prepareSend($actual,106,false,$journal)===null,'G incoming busy strictly denies even barrier-free pure send policy');
$check($t->snapshot()['sendAttempts']===0&&$t->snapshot()['state']==='CANCELLED','G no send attempt or uncertainty intent');
foreach($journal->records()as$row)$check(($row['journalStatus']??'')!=='MAY_HAVE_SENT','G no send WAL');
// The product still uses the classifier, and the arbiter barrier is untouched.
$module=file_get_contents(__DIR__.'/../libs/C2Module.php');
$check(str_contains($module,'C2BlockedGate::observe($idle,$context,$h->verifyActive(),$st[\'pending\']??null)'),'actual runtime wiring');
$check(str_contains($source,'private const B6_HARDWARE_WRITE_BARRIER = false;'),'final product barrier open; incoming-busy gate unchanged');
echo "PASS: {$count} focused A-H checks; transient RX preserves proof, true faults latch, final send remains denied, 63-year virtual lifetime\n";
