<?php
declare(strict_types=1);
// Actual Symcon adapter + handoff; SDK metadata is in memory, never a UART.
require_once __DIR__.'/../libs/C2SymconEnvironment.php';
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\C2SymconEnvironment;
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
use EnOceanGatewayManager\Safety\WriteJournal;

function IPS_InstanceExists(int $id):bool{return isset($GLOBALS['sdk']['nodes'][$id]);}
function IPS_GetInstance(int $id):array{return $GLOBALS['sdk']['nodes'][$id]??throw new RuntimeException('Missing instance');}
function IPS_GetObject(int $id):array{return ['ObjectIdent'=>$GLOBALS['sdk']['idents'][$id]??''];}
function IPS_GetInstanceList():array{return array_keys($GLOBALS['sdk']['nodes']);}
function IPS_GetConfiguration(int $id):string|false{
    $GLOBALS['sdk']['configurationReads'][]=$id;
    return isset($GLOBALS['sdk']['configs'][$id])?json_encode($GLOBALS['sdk']['configs'][$id],JSON_THROW_ON_ERROR):false;
}
function IPS_CreateInstance(string $module):int{
    $id=$GLOBALS['sdk']['next']++;$GLOBALS['sdk']['created']++;
    $GLOBALS['sdk']['nodes'][$id]=['ModuleInfo'=>['ModuleID'=>$module],'ConnectionID'=>0,'InstanceStatus'=>104];
    $GLOBALS['sdk']['configs'][$id]=$module===NativeGatewayResolver::SERIAL?['Port'=>'','Open'=>false]:['EnableMaintenance'=>false,'IsolatedReadOnly'=>false];
    return $id;
}
function IPS_SetIdent(int $id,string $ident):void{$GLOBALS['sdk']['idents'][$id]=$ident;}
function IPS_SetName(int $id,string $name):void{}
function IPS_SetConfiguration(int $id,string $json):void{
    if(!IPS_InstanceExists($id))throw new RuntimeException('Configure deleted instance');
    $GLOBALS['sdk']['configs'][$id]=json_decode($json,true,512,JSON_THROW_ON_ERROR);
}
function IPS_ApplyChanges(int $id):void{
    $n=&$GLOBALS['sdk']['nodes'][$id];
    if($n['ModuleInfo']['ModuleID']===NativeGatewayResolver::SERIAL)$n['InstanceStatus']=$GLOBALS['sdk']['configs'][$id]['Open']?102:104;
}
function IPS_ConnectInstance(int $child,int $parent):void{$GLOBALS['sdk']['nodes'][$child]['ConnectionID']=$parent;}
function IPS_DisconnectInstance(int $child):void{$GLOBALS['sdk']['nodes'][$child]['ConnectionID']=0;}
function IPS_GetChildrenIDs(int $id):array{return [];}
function IPS_DeleteVariable(int $id):never{throw new RuntimeException('Unexpected variable deletion');}
function IPS_DeleteInstance(int $id):void{
    unset($GLOBALS['sdk']['nodes'][$id],$GLOBALS['sdk']['configs'][$id],$GLOBALS['sdk']['idents'][$id]);
    // Symcon disconnects children when a parent is removed.
    foreach($GLOBALS['sdk']['nodes']as&$n)if($n['ConnectionID']===$id)$n['ConnectionID']=0;
    unset($n);
}
function SPRT_SendText(int $id,string $data):never{$GLOBALS['sdk']['hardwareCalls']++;throw new RuntimeException('Hardware commands forbidden');}

$count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
$factory=static function():array{
    $GLOBALS['sdk']=['nodes'=>[
        10=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::NATIVE],'ConnectionID'=>20,'InstanceStatus'=>102],
        20=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::SERIAL],'ConnectionID'=>0,'InstanceStatus'=>102],
        40=>['ModuleInfo'=>['ModuleID'=>C2Handoff::MANAGER],'ConnectionID'=>0,'InstanceStatus'=>102]],
        'configs'=>[10=>['GatewayMode'=>2,'BaseID'=>'0000A000'],20=>['Port'=>'SIMULATOR','BaudRate'=>'57600','DataBits'=>'8','Parity'=>'None','StopBits'=>'1','Open'=>true],40=>[]],
        'idents'=>[],'next'=>100,'created'=>0,'configurationReads'=>[],'hardwareCalls'=>0];
    $j=new class implements WriteJournal{
        public array $rows=[];
        public function append(array $record):void{$this->rows[]=$record;}
        public function records():array{return $this->rows;}
    };
    $h=new C2Handoff(new C2SymconEnvironment(),$j);$h->begin(40,10,100);$h->advance(101);
    return [$h,$j,$h->state(),$GLOBALS['sdk']['configs'][10],array_replace($GLOBALS['sdk']['configs'][20],['Open'=>true])];
};
[$h,$j,$s]=$factory();IPS_DeleteInstance($s['ownArbiter']);IPS_DeleteInstance($s['ownIO']);
if(in_array('--expect-old',$argv,true)){
    try{$h->retire(40,110);throw new RuntimeException('Expected original failure');}
    catch(TypeError $e){$check(str_contains($e->getMessage(),'false given'),'exact production JSON false failure');}
    $check($h->state()['phase']==='ACTIVE'&&end($j->rows)['event']==='MANAGER_RETIRE_REQUESTED','original failure leaves retirement incomplete');
    $check(IPS_GetInstance(10)['ConnectionID']===0&&!$GLOBALS['sdk']['configs'][20]['Open'],'original native connection stranded');
    echo "REPRODUCED: deleted temporary I/O causes json_decode(false) in actual return adapter\n";exit;
}

foreach(['both','arbiter','io','after-return-start','missing-manager','resumed-retirement']as$case){
    [$h,$j,$s,$native,$serial]=$factory();
    if($case==='after-return-start')$h->retire(40,110);
    if($case!=='io')IPS_DeleteInstance($s['ownArbiter']);
    if($case!=='arbiter')IPS_DeleteInstance($s['ownIO']);
    if($case==='missing-manager')IPS_DeleteInstance(40);
    if($case==='resumed-retirement'){
        // Durable retirement left by old Destroy, after the same JSON error.
        $s['retiring']=true;$j->append(['event'=>'MANAGER_RETIRE_REQUESTED','handoff'=>$s]);IPS_DeleteInstance(40);
        $h=new C2Handoff(new C2SymconEnvironment(),$j);
    }
    $GLOBALS['sdk']['configurationReads']=[];
    if($case==='after-return-start')$phase='RETURN_CLOSING';
    elseif($case==='resumed-retirement')$phase=$h->restore(120);
    else $phase=$h->retire(40,110);
    $check($phase==='RETURN_CLOSING'&&$h->finishRestore(121)==='RESTORED','retirement restores: '.$case);
    $check(IPS_GetInstance(10)['ConnectionID']===20&&$GLOBALS['sdk']['configs'][10]===$native&&$GLOBALS['sdk']['configs'][20]===$serial,'exact original configuration and native parent: '.$case);
    $check(!IPS_InstanceExists($s['ownIO'])&&!IPS_InstanceExists($s['ownArbiter']),'temporary structures removed: '.$case);
    $check($GLOBALS['sdk']['created']===2&&$GLOBALS['sdk']['hardwareCalls']===0,'no new maintenance structures or gateway commands: '.$case);
    if($case!=='arbiter')$check(!in_array($s['ownIO'],$GLOBALS['sdk']['configurationReads'],true),'no configuration read of removed I/O: '.$case);
    $rows=count($j->rows);$check($h->retire(40,130)==='RESTORED'&&count($j->rows)===$rows,'completed retirement remains idempotent: '.$case);
}
foreach(['no-retirement','native-config','original-config','foreign-original-child','manager-parent','reused-io','reused-arbiter']as$fault){
    [$h,$j,$s]=$factory();IPS_DeleteInstance($s['ownArbiter']);IPS_DeleteInstance($s['ownIO']);
    switch($fault){
        case'native-config':$GLOBALS['sdk']['configs'][10]['BaseID']='FF910000';break;
        case'original-config':$GLOBALS['sdk']['configs'][20]['BaudRate']='115200';break;
        case'foreign-original-child':$GLOBALS['sdk']['nodes'][99]=['ConnectionID'=>20];break;
        case'manager-parent':$GLOBALS['sdk']['nodes'][40]['ConnectionID']=999;break;
        case'reused-io':case'reused-arbiter':
            $key=$fault==='reused-io'?'ownIO':'ownArbiter';$id=$s[$key];
            $GLOBALS['sdk']['nodes'][$id]=['ModuleInfo'=>['ModuleID'=>$key==='ownIO'?NativeGatewayResolver::SERIAL:C2Handoff::ARBITER],'ConnectionID'=>0,'InstanceStatus'=>104];
            $GLOBALS['sdk']['configs'][$id]=$s[$key==='ownIO'?'ownIOConfiguration':'ownArbiterConfiguration'];
            $GLOBALS['sdk']['idents'][$id]='FOREIGN';break;
    }
    $failed=false;
    try{$fault==='no-retirement'?$h->restore(110):$h->retire(40,110);$h->finishRestore(111);}
    catch(Throwable){$failed=true;}
    $check($failed&&$h->state()['phase']!=='RESTORED','existing safety rejection remains: '.$fault);
    $check(IPS_GetInstance(10)['ConnectionID']===0&&!$GLOBALS['sdk']['configs'][20]['Open'],'no unsafe native reopen: '.$fault);
    $check($GLOBALS['sdk']['hardwareCalls']===0,'failed retirement sends no gateway command: '.$fault);
    if(str_starts_with($fault,'reused-'))$check(IPS_InstanceExists($id)&&$GLOBALS['sdk']['idents'][$id]==='FOREIGN','reused foreign object not deleted: '.$fault);
}
echo "PASS: {$count} focused retirement/deletion-order checks; SDK metadata only, no hardware access\n";
