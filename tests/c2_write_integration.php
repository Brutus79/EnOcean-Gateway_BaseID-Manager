<?php
declare(strict_types=1);
// Isolated product-adapter test. Reuse the existing SDK double (not its tests).
// Only the C2 environment and OS descriptor inspection are replaced. All C2,
// B6, parser, WAL and sole final-send code, including barrier=true, is real.
require_once __DIR__.'/../libs/C2Handoff.php';
use EnOceanGatewayManager\Maintenance\C2Environment;
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\DurableWriteJournal;
$root=dirname(__DIR__);
$sdk=explode("require_once __DIR__ . '/../ESP3TransportArbiter/module.php';",file_get_contents(__DIR__.'/module_lifecycle.php'))[0];
$start=strpos($sdk,'function IPS_GetInstance(');$end=strpos($sdk,'function IPS_GetKernelDir(');
$sdk=substr_replace($sdk,'function IPS_GetInstance(int $id): array {return $GLOBALS["c2Env"]->instance($id);}' . "\n",$start,$end-$start);
$sdk=str_replace("public function RegisterTimer(string \$k, int \$v, string \$s): void {}",
    "public function RegisterTimer(string \$k, int \$v, string \$s): void {}\n public function SetTimerInterval(string \$k,int \$v): void {}",$sdk);
$sdk=str_replace("return \$GLOBALS['egmTest']['instances'][\$this->InstanceID]['active'] ?? true;",
    'return $GLOBALS["c2Env"]->active($this->InstanceID);',$sdk);
$sdk=str_replace("return \$GLOBALS['egmTest']['instances'][\$id]['properties'][\$key] ?? '';",
    'return $GLOBALS["c2Env"]->configuration($id)[$key] ?? "";',$sdk);
$sdk=str_replace("return json_encode(\$GLOBALS['egmTest']['instances'][\$id]['properties'] ?? []);",
    'return json_encode($GLOBALS["c2Env"]->configuration($id));',$sdk);
$sdk=str_replace('return [101,200,300];','return $GLOBALS["c2Env"]->instances();',$sdk);
eval('?>'.$sdk);
function EGMM_GetC2WriteProof(int $id):string{return(new EnOceanGatewayManager($id))->GetC2WriteProof();}
function EGMM_GetDiagnosticSnapshot(int $id):string{return(new EnOceanGatewayManager($id))->GetDiagnosticSnapshot();}
function EGMA_ProcessTimeouts(int $id):void{(new ESP3TransportArbiter($id))->ProcessTimeouts();}
function IPS_GetSnapshotChanges(int $cursor):string{return '[]';}
$load=static function(string$file,callable$edit):void{
    $source=$edit(file_get_contents($file));
    $source=str_replace('__DIR__',var_export(dirname($file),true),$source);
    eval('?>'.$source);
};
$load($root.'/libs/C2Module.php',static fn($s)=>str_replace(
    ['private function c2Environment(): \\EnOceanGatewayManager\\Maintenance\\C2SymconEnvironment',
     'return new \\EnOceanGatewayManager\\Maintenance\\C2SymconEnvironment();'],
    ['private function c2Environment(): \\EnOceanGatewayManager\\Maintenance\\C2Environment',
     'return $GLOBALS["c2Env"];'],$s));
$load($root.'/EnOceanGatewayManager/module.php',static fn($s)=>str_replace("require_once __DIR__ . '/../libs/C2Module.php';",'',$s));
$load($root.'/ESP3TransportArbiter/module.php',static function($s){
    $start=strpos($s,'            $owners = [];');$end=strpos($s,'            $otherwiseIdle =',$start);
    if($start===false||$end===false)throw new RuntimeException('OS seam not found');
    return substr_replace($s,'            $fds=$GLOBALS["c2Env"]->descriptors("SIMULATOR");
            $ownershipKnown=($GLOBALS["ownershipKnown"]??true);$descriptorCount=count($fds);
            $owners=$descriptorCount>0?[getmypid()]:[];
',$start,$end-$start);
});
$count=0;$check=static function(bool$b,string$l)use(&$count):void{$count++;if(!$b)throw new RuntimeException($l);};
$frame=static function(string$d,string$o=''):string{$h=pack('nCC',strlen($d),strlen($o),2);return'55'.strtoupper(bin2hex($h.chr(ESP3Codec::crc8($h)).$d.$o.chr(ESP3Codec::crc8($d.$o))));};
$version=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SIMULATOR',16,"\0"));
$base=$frame(hex2bin('00FF900080'),"\x08");
$GLOBALS['c2Env']=new class implements C2Environment{
    public array $nodes=[],$configs=[];
    public function reset():void{
        $this->nodes=[10=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::NATIVE],'ConnectionID'=>20,'InstanceStatus'=>102],
            20=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::SERIAL],'ConnectionID'=>0,'InstanceStatus'=>102],
            101=>['ModuleInfo'=>['ModuleID'=>C2Handoff::MANAGER],'ConnectionID'=>0,'InstanceStatus'=>102]];
        $this->configs=[10=>['GatewayMode'=>2,'BaseID'=>'FF900080'],20=>['Port'=>'SIMULATOR','BaudRate'=>'57600','DataBits'=>'8','Parity'=>'None','StopBits'=>'1','Open'=>true]];
    }
    public function instance(int$id):array{return$this->nodes[$id]??throw new RuntimeException('missing node');}
    public function configuration(int$id):array{return$GLOBALS['egmTest']['instances'][$id]['properties']??$this->configs[$id]??[];}
    public function instances():array{return array_keys($this->nodes);}
    public function create(string$module):int{$id=$module===NativeGatewayResolver::SERIAL?300:200;
        $this->nodes[$id]=['ModuleInfo'=>['ModuleID'=>$module],'ConnectionID'=>0,'InstanceStatus'=>102,'C2OwnershipIdent'=>'EGM_C2_SIM_'.$id];
        if($id===200){(new ESP3TransportArbiter($id))->Create();}else{$this->configs[$id]=['Port'=>'','Open'=>false];}return$id;}
    public function configure(int$id,array$c):void{
        if(isset($GLOBALS['egmTest']['instances'][$id]))$GLOBALS['egmTest']['instances'][$id]['properties']=$c;else$this->configs[$id]=$c;
        if(isset($this->nodes[200]))(new ESP3TransportArbiter(200))->ApplyChanges();
    }
    public function connect(int$c,int$p):void{$this->nodes[$c]['ConnectionID']=$p;}
    public function disconnect(int$c):void{$this->nodes[$c]['ConnectionID']=0;}
    public function delete(int$id):void{unset($this->nodes[$id],$this->configs[$id],$GLOBALS['egmTest']['instances'][$id]);}
    public function descriptors(string$path):?array{$fds=[];foreach($this->nodes as$id=>$n)
        if($n['ModuleInfo']['ModuleID']===NativeGatewayResolver::SERIAL&&($this->configuration($id)['Open']??false))$fds[]=['pid'=>getmypid(),'fd'=>(string)$id];return$fds;}
    public function selfPID():int{return getmypid();}
    public function active(int$id):bool{$p=$this->nodes[$id]['ConnectionID']??0;if($p<=0)return false;
        if($this->nodes[$p]['ModuleInfo']['ModuleID']===NativeGatewayResolver::SERIAL)return(bool)($this->configuration($p)['Open']??false);
        return$this->active($p);}
};
$attr=static fn(string$k):array=>json_decode($GLOBALS['egmTest']['instances'][101]['attributes'][$k],true);
$tx=static fn():array=>json_decode(EGMA_GetWriteTransactionView(200),true);
$deliver=static fn(string$hex)=>(new ESP3TransportArbiter(200))->ReceiveData(json_encode(['DataID'=>'{018EF6B5-AB94-40C6-AA53-46943E824ACF}','Buffer'=>$hex]));
$setup=static function()use($version,$base,$attr,$deliver,$check):void{
    $GLOBALS['egmTest']=['instances'=>[],'sent'=>[],'locks'=>[]];$GLOBALS['ownershipKnown']=true;$GLOBALS['c2Env']->reset();
    // Separate durable histories per case, as separate physical handoffs have.
    $GLOBALS['egmTest']['kernelDir']=sys_get_temp_dir().'/egm-c2-adapter-'.bin2hex(random_bytes(8));
    (new EnOceanGatewayManager(101))->Create();$GLOBALS['egmTest']['instances'][101]['properties']['NativeGatewayInstanceID']=10;
    $m=new EnOceanGatewayManager(101);$check($m->StartNativeMaintenance(),'actual manager starts handoff');$m->ProcessC2Maintenance();
    // Full initial five pairs via actual manager -> actual arbiter -> fake Parent.
    for($i=0;$i<10;$i++){$m->ProcessC2Maintenance();$deliver($i%2?$base:$version);}
    $m->ProcessC2Maintenance();$check($attr('C2Session')['phase']==='MAINTENANCE_READY','five real product read pairs reach ready');
    $check($m->ReviewNativeTarget('FF900000'),'actual review');$token=$attr('C2Session')['confirmation'];
    $check($m->ConfirmNativeTargetA($token)&&$m->ConfirmNativeTargetB($token,'FF900000'),'actual explicit A/B');
    for($i=0;$i<10;$i++){$m->ProcessC2Maintenance();$deliver($i%2?$base:$version);}
    $m->ProcessC2Maintenance();
};
$advance=static function(string$v,string$b,bool$final=true)use($deliver):void{
    for($i=0;$i<4;$i++){EGMA_ProcessTimeouts(200);$deliver($i%2?$b:$v);}
    if($final)EGMA_ProcessTimeouts(200);
};
$setup();$check($tx()['state']==='PREFLIGHT','C2 enters existing B6 transaction');$advance($version,$base);
$check((new ReflectionClass(ESP3TransportArbiter::class))->getConstant('B6_HARDWARE_WRITE_BARRIER')===true,'unaltered real product barrier constant');
$check($tx()['state']==='PRE_WRITE_JOURNALED'&&$tx()['finalGateBlocked'],'actual sole final-send gate reached and blocked');
$check($tx()['hardwareWriteBarrier']&&$tx()['sendAttempts']===0,'barrier true, zero attempt');
$j=new DurableWriteJournal(IPS_GetKernelDir().'/egm-write-journal-200');
foreach($j->records()as$r)$check(($r['sendAttempts']??0)===0&&($r['journalStatus']??'')!=='MAY_HAVE_SENT','no send-intent in WAL');
foreach(json_decode((new ESP3TransportArbiter(200))->GetTrafficAudit(),true)as$r)
    $check(ESP3Codec::parseFrame(hex2bin($r['frameHex']))['data']!==hex2bin('07FF900000'),'no hardware write in actual Parent traffic');
(new EnOceanGatewayManager(101))->ProcessC2Maintenance();$check($attr('C2Session')['phase']==='WRITE_BLOCKED','own validated lease does not fault C2');
$expired=json_decode($GLOBALS['egmTest']['instances'][200]['buffers']['WriteTransactionRuntime'],true);$expired['expiresAt']=time()-864000;
foreach($expired['reads']as&$row)$row['at']=time()-864000;unset($row);
$GLOBALS['egmTest']['instances'][200]['buffers']['WriteTransactionRuntime']=json_encode($expired);
EGMA_ProcessTimeouts(200);(new EnOceanGatewayManager(101))->ProcessC2Maintenance();
$check($tx()['finalGateBlocked']&&$attr('C2Session')['phase']==='WRITE_BLOCKED','long virtual idle does not expire completed blocked proof or send');
$check((new EnOceanGatewayManager(101))->BackToNativeTargetSelection(),'back cancels prepared lease');
$check($tx()['state']==='CANCELLED'&&$attr('C2Session')['phase']==='MAINTENANCE_READY','back restores selection, no attempt');
$m=new EnOceanGatewayManager(101);$check($m->ReviewNativeTarget('FF900100'),'new explicit selection after cancelled blocked intent');
$token=$attr('C2Session')['confirmation'];$check($m->ConfirmNativeTargetA($token)&&$m->ConfirmNativeTargetB($token,'FF900100'),'new explicit A/B after cancellation');
for($i=0;$i<10;$i++){$m->ProcessC2Maintenance();$deliver($i%2?$base:$version);}$m->ProcessC2Maintenance();
$advance($version,$base);$check($tx()['target']==='FF900100'&&$tx()['finalGateBlocked'],'new deliberate selection reaches final barrier');
$check($m->ReturnNativeMaintenance(),'blocked intent permits safe return without write');$m->ProcessC2Maintenance();
$check($attr('C2Handoff')['phase']==='RESTORED'&&IPS_GetInstance(10)['ConnectionID']===20,'original native connection restored');
$setup();$before=count($GLOBALS['egmTest']['sent']);
$result=json_decode((new ESP3TransportArbiter(200))->ForwardData(json_encode(['DataID'=>'{F5B497B9-0A7D-4F1A-A830-86B1B85A55D4}',
    'OwnerInstanceID'=>101,'Operation'=>'B6_C2_BEGIN','Target'=>'FF900001'])),true);
$check(!$result['accepted']&&count($GLOBALS['egmTest']['sent'])===$before,'invalid alignment rejected before hardware communication');

foreach(['gateway','ownership','maintenance','session','target','base','counter','eurid','confirmation','pending']as$fault){
    $setup();$v=$version;$b=$base;
    switch($fault){
        case'gateway':$GLOBALS['egmTest']['instances'][101]['properties']['NativeGatewayInstanceID']=11;break;
        case'ownership':$GLOBALS['ownershipKnown']=false;break;
        case'maintenance':$s=$attr('C2Session');$s['phase']='RETURNED';$GLOBALS['egmTest']['instances'][101]['attributes']['C2Session']=json_encode($s);break;
        case'session':$s=$attr('C2Session');$s['context']['session']='another';$GLOBALS['egmTest']['instances'][101]['attributes']['C2Session']=json_encode($s);break;
        case'target':$s=$attr('C2Session');$s['target']='FF900100';$GLOBALS['egmTest']['instances'][101]['attributes']['C2Session']=json_encode($s);break;
        case'base':$b=$frame(hex2bin('00FF900100'),"\x08");break;
        case'counter':$b=$frame(hex2bin('00FF900080'),"\x05");break;
        case'eurid':$v=$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SIMULATOR',16,"\0"));break;
        case'confirmation':$s=$attr('C2Session');$s['confirmation']=null;$GLOBALS['egmTest']['instances'][101]['attributes']['C2Session']=json_encode($s);break;
        case'pending':$s=$attr('C2Session');$s['pending']=['token'=>'foreign'];$GLOBALS['egmTest']['instances'][101]['attributes']['C2Session']=json_encode($s);break;
    }
    $advance($v,$b);$check(in_array($tx()['state'],['CANCELLED','UNKNOWN_OUTCOME','NO_OP'],true),'negative '.$fault.' stops before send');
    $check($tx()['sendAttempts']===0&&!$tx()['finalGateBlocked'],'negative '.$fault.' no send authorization');
}
$setup();$advance($version,$frame(hex2bin('00FF900000'),"\x08"));
$check($tx()['state']==='NO_OP'&&$tx()['sendAttempts']===0,'current target is not rewritten');

// Mutate only after the final fresh read pair, directly before the real send gate.
foreach(['gateway','ownership','transport','confirmation','parser-busy']as$fault){
    $setup();$advance($version,$base,false);$check($tx()['state']==='PRE_WRITE_JOURNALED'&&!$tx()['finalGateBlocked'],'last pair completed, gate not yet evaluated');
    if($fault==='gateway')$GLOBALS['egmTest']['instances'][101]['properties']['NativeGatewayInstanceID']=11;
    elseif($fault==='ownership')$GLOBALS['ownershipKnown']=false;
    elseif($fault==='transport')$GLOBALS['c2Env']->configs[300]['BaudRate']='115200';
    elseif($fault==='confirmation'){$s=$attr('C2Session');$s['confirmation']=null;$GLOBALS['egmTest']['instances'][101]['attributes']['C2Session']=json_encode($s);}
    else{$deliver('550005');}
    try{EGMA_ProcessTimeouts(200);}catch(RuntimeException){} // Adapter failure is also fail-closed.
    $check(in_array($tx()['state'],['CANCELLED','UNKNOWN_OUTCOME'],true)&&$tx()['sendAttempts']===0&&!$tx()['finalGateBlocked'],'final live '.$fault.' gate stops before send');
}

// No product barrier override and no Parent write: create a pure-policy simulated
// outcome, then feed it into the real runtime reconnect/postverification adapter.
foreach(['ret-ok','lost-applied','lost-not-applied','wrong-counter','wrong-eurid','inconsistent-pairs','extra-warning']as$case){
    $setup();$advance($version,$base);$arbiter=new ESP3TransportArbiter(200);
    $snapshot=json_decode($GLOBALS['egmTest']['instances'][200]['buffers']['WriteTransactionRuntime'],true);
    $t=new \EnOceanGatewayManager\Safety\TransactionalWrite($snapshot);
    (new ReflectionMethod($arbiter,'writeTransaction'))->invoke($arbiter);
    $raw=json_decode((new ReflectionMethod($arbiter,'readSafetyContextUnlocked'))->invoke($arbiter),true);
    $journal=new DurableWriteJournal(IPS_GetKernelDir().'/egm-write-journal-200');
    $check(is_string($t->prepareSend($raw,time(),false,$journal)),'pure simulated policy effect only');
    if($case!=='ret-ok')$t->observe($raw,time()+6,$journal);
    $GLOBALS['egmTest']['instances'][200]['buffers']['WriteTransactionRuntime']=json_encode($t->snapshot());
    $GLOBALS['egmTest']['instances'][200]['attributes']['WriteTransactionState']=json_encode($t->view());
    if($case==='ret-ok')$deliver($frame("\0"));
    $m=new EnOceanGatewayManager(101);$m->ProcessC2Maintenance(); // Recover, or close.
    $m->ProcessC2Maintenance();$m->ProcessC2Maintenance();$m->ProcessC2Maintenance();
    $check($tx()['state']==='POST_VERIFY','actual new-session postverification '.$case);
    $fields=\EnOceanGatewayManager\Product\C2Presentation::fields(\EnOceanGatewayManager\Product\C2Presentation::form(
        ['session'=>$attr('C2Session'),'handoff'=>$attr('C2Handoff'),'writeTransaction'=>$tx(),'selectedReference'=>10],'',[]));
    $check(!$fields['C2Return']['enabled']&&!$fields['C2Back']['enabled'],'no UI return/reselection during actual postverification');
    $pb=$case==='lost-not-applied'?$base:$frame(hex2bin('00FF900000'),$case==='wrong-counter'?"\x08":"\x07");
    $pv=$case==='wrong-eurid'?$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SIMULATOR',16,"\0")):$version;
    for($i=0;$i<10;$i++){
        if($i>0)EGMA_ProcessTimeouts(200);
        $deliver($i%2?($case==='inconsistent-pairs'&&$i===3?$base:$pb):$pv);
        if($tx()['state']==='UNKNOWN_OUTCOME')break;
    }
    if($case==='extra-warning'){
        $GLOBALS['egmTest']['instances'][200]['attributes']['CommunicationFaultEpoch']='2';
        $GLOBALS['egmTest']['instances'][200]['attributes']['CommunicationFaultHistory']=json_encode([['epoch'=>2,'level'=>'warning','reason'=>'unexpected_response']]);
    }
    $m->ProcessC2Maintenance();
    $expected=match($case){'ret-ok','lost-applied','extra-warning'=>'VERIFIED','lost-not-applied'=>'RECOVERY_NOT_APPLIED',default=>'UNKNOWN_OUTCOME'};
    $check($tx()['state']===$expected,'actual post adapter '.$case.' outcome');
    $check($tx()['sendAttempts']===1,'simulated attempt never retried');
    if($case==='extra-warning')$check($attr('C2Session')['phase']==='FAULT_LATCHED','extra warning cannot become C2 ready, latch never cleared');
    elseif(in_array($expected,['VERIFIED','RECOVERY_NOT_APPLIED'],true)){
        $check($attr('C2Session')['phase']==='MAINTENANCE_READY','five post pairs publish fresh C2 session: '.json_encode($attr('C2Session')['faults']));
        $check($attr('C2Session')['snapshot']['idbase']['baseIdRawHex']===($expected==='VERIFIED'?'FF900000':'FF900080'),'return uses newly measured base');
    }else{$check($attr('C2Session')['phase']==='FAULT_LATCHED','uncertain post outcome fail-closed');}
    foreach($GLOBALS['egmTest']['sent']as[$id,$packet])if($id===200)
        $check(ESP3Codec::parseFrame(hex2bin($packet['Buffer']))['data']!==hex2bin('07FF900000'),'post simulation never sends real write through Parent');
}
echo "PASS: actual C2 -> B6 -> sole blocked send adapter {$count} assertions\n";
