<?php
declare(strict_types=1);
// Actual C2 runtime entry points with a pure Handoff environment. No UART/API.
require_once __DIR__.'/../libs/C2Handoff.php';
use EnOceanGatewayManager\Maintenance\C2Environment;
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Maintenance\NativeRefreshVerifier;
use EnOceanGatewayManager\Safety\WriteJournal;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
$factory=static function():array{
    $e=new class implements C2Environment{
        public array $nodes=[],$configs=[],$mutations=[];public int $next=100,$faultAt=0;
        public function __construct(){
            $this->nodes=[10=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::NATIVE],'ConnectionID'=>20,'InstanceStatus'=>102],
                20=>['ModuleInfo'=>['ModuleID'=>NativeGatewayResolver::SERIAL],'ConnectionID'=>0,'InstanceStatus'=>102],
                40=>['ModuleInfo'=>['ModuleID'=>C2Handoff::MANAGER],'ConnectionID'=>0,'InstanceStatus'=>102]];
            $this->configs=[10=>['GatewayMode'=>2,'BaseID'=>'0000A000'],
                20=>['Port'=>'SIMULATOR','BaudRate'=>'57600','DataBits'=>'8','Parity'=>'None','StopBits'=>'1','Open'=>true],40=>[]];
        }
        public function instance(int$id):array{if(!isset($this->nodes[$id]))throw new RuntimeException('missing');return$this->nodes[$id];}
        public function configuration(int$id):array{return$this->configs[$id]??throw new RuntimeException('missing');}
        public function instances():array{return array_keys($this->nodes);}
        private function after(string$op):void{$this->mutations[]=$op;if($this->faultAt===count($this->mutations))throw new RuntimeException('Injected after mutation');}
        public function create(string$module):int{$id=$this->next++;$this->nodes[$id]=['ModuleInfo'=>['ModuleID'=>$module],'ConnectionID'=>0,'InstanceStatus'=>102,'C2OwnershipIdent'=>'EGM_C2_SIM_'.$id];
            $this->configs[$id]=$module===NativeGatewayResolver::SERIAL?['Port'=>'','Open'=>false]:['EnableMaintenance'=>false,'IsolatedReadOnly'=>false];$this->after('create');return$id;}
        public function configure(int$id,array$config):void{$this->configs[$id]=$config;$this->after('configure');}
        public function connect(int$c,int$p):void{$this->nodes[$c]['ConnectionID']=$p;$this->after('connect');}
        public function disconnect(int$c):void{$this->nodes[$c]['ConnectionID']=0;$this->after('disconnect');}
        public function delete(int$id):void{unset($this->nodes[$id],$this->configs[$id]);$this->after('delete');}
        public function descriptors(string$path):?array{$fds=[];foreach($this->nodes as$id=>$n)if($n['ModuleInfo']['ModuleID']===NativeGatewayResolver::SERIAL
            &&($this->configs[$id]['Port']??'')===$path&&($this->configs[$id]['Open']??false))$fds[]=['pid'=>1,'fd'=>(string)$id];return$fds;}
        public function selfPID():int{return 1;}
    };
    $j=new class implements WriteJournal{private array$r=[];public function append(array$r):void{$this->r[]=$r;}public function records():array{return$this->r;}};
    return[$e,$j,new C2Handoff($e,$j)];
};
$path=$argv[1]??(__DIR__.'/../libs/C2Module.php');
$source=file_get_contents($path);
$source=str_replace("__DIR__.'/",var_export(realpath(__DIR__.'/../libs').'/',true).".'",$source);
eval(substr($source,5));
function IPS_SemaphoreEnter(string $name,int $timeout):bool{return true;}
function IPS_SemaphoreLeave(string $name):void{}
function IPS_GetSnapshotChanges(int $cursor):string{return json_encode([['TimeStamp'=>++$GLOBALS['cursor']]]);}
function IPS_EnableDebug(int $id,int $seconds):void{}
class C2CycleHarness
{
    use GatewayC2Module;
    public int $InstanceID=40;
    public array $attributes=['C2Session'=>'[]','C2Handoff'=>'[]','C2NativeRefresh'=>'[]','C2Review'=>'[]','C2ResultInbox'=>'[]'];
    public array $buffers=[],$timers=[];
    private function c2Handoff():C2Handoff{return new C2Handoff($GLOBALS['environment'],$GLOBALS['journal']);}
    public function ReadPropertyInteger(string $key):int{return 10;}
    public function ReadAttributeString(string $key):string{return$this->attributes[$key]??'[]';}
    public function WriteAttributeString(string $key,string $value):void{$this->attributes[$key]=$value;}
    public function GetBuffer(string $key):string{return$this->buffers[$key]??'';}
    public function SetBuffer(string $key,string $value):void{$this->buffers[$key]=$value;}
    public function SetTimerInterval(string $key,int $value):void{$this->timers[$key]=$value;}
    private function c2RequestFormUpdate():void{}
    private function productMessage(string $message,bool $force=false):void{}
}
[$GLOBALS['environment'],$GLOBALS['journal'],$h]=$factory();$GLOBALS['cursor']=100;
$m=new C2CycleHarness();$check($m->StartNativeMaintenance(),'first start');$before=$m->attributes;
$check(!$m->StartNativeMaintenance(),'second start rejected');
if(in_array('--expect-bug',$argv,true)){
    $s=json_decode($m->attributes['C2Session'],true);
    $check($s['phase']==='FAULT_LATCHED'&&$s['snapshot']===null&&!isset($s['context']),'baseline repeated start poisons uninitialized session');
    $m->ReturnNativeMaintenance();$h=new C2Handoff($GLOBALS['environment'],$GLOBALS['journal']);$h->finishRestore(102);
    $check(json_decode($m->attributes['C2Session'],true)['phase']==='NATIVE_REFRESH_PENDING'&&$m->attributes['C2NativeRefresh']==='[]','baseline pending without observer reproduced');
    echo "REPRODUCED: duplicate start faults an empty session; return has no observer/snapshot\n";exit;
}
$check($m->attributes===$before,'duplicate start preserves empty synchronization and existing handoff');
$h=new C2Handoff($GLOBALS['environment'],$GLOBALS['journal']);$h->restore(102);$h->finishRestore(103);
$s=new C2Session();$s->returning();$check($s->state()['phase']==='NATIVE_REFRESH_PENDING','early return has explicit phase');
$s->returned(false);$check($s->state()['phase']==='RETURN_WARNING','missing proof never produces RETURNED');
$m->attributes['C2Session']=json_encode($s->state());
$frame=static function(string $d,string $o=''):string{$head=pack('nCC',strlen($d),strlen($o),2);return "\x55".$head.chr(ESP3Codec::crc8($head)).$d.$o.chr(ESP3Codec::crc8($d.$o));};
$v=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SYNTHETIC',16,"\0"));$b=$frame(hex2bin('00FF900000'),"\x08");
$seenIDs=[];$seenCursors=[];$original=$GLOBALS['environment']->configs;
for($cycle=0;$cycle<4;$cycle++){
    $check($m->StartNativeMaintenance(),'new cycle '.$cycle);$h=new C2Handoff($GLOBALS['environment'],$GLOBALS['journal']);
    $check(!in_array($h->state()['id'],$seenIDs,true),'new handoff identity');$seenIDs[]=$h->state()['id'];
    $check($m->attributes['C2Session']==='[]'&&$m->attributes['C2NativeRefresh']==='[]','old evidence cleared only after accepted begin');
    $before=$m->attributes;$check(!$m->StartNativeMaintenance()&&$m->attributes===$before,'stale repeated start harmless');
    $h->advance(101);$ctx=['session'=>'synthetic-'.$cycle,'transportBinding'=>'binding-'.$cycle,'handoffBinding'=>$h->verifyActive(),
        'exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0,'writeLeaseActive'=>false,'noUnknownOutcome'=>true];
    $s=new C2Session();$s->start($ctx,100);
    for($i=0;$i<10;$i++){$at=101+$i*.1;$q=$s->request($ctx,$at);$check($s->response($q['token'],$q['operation'],$i%2?$b:$v,$ctx,$at+.01),'new initial pair');}
    $m->attributes['C2Session']=json_encode($s->state());$check($m->ReturnNativeMaintenance(),'return accepted');
    $refresh=json_decode($m->attributes['C2NativeRefresh'],true);$check(!in_array($refresh['cursor'],$seenCursors,true),'new pre-reconnect cursor');$seenCursors[]=$refresh['cursor'];
    $before=$m->attributes;$check($m->ReturnNativeMaintenance()&&$m->attributes===$before,'repeated closing return does not replace observer/cursor');
    $check(!$m->StartNativeMaintenance()&&$m->attributes===$before,'no maintenance during return closing');
    $h=new C2Handoff($GLOBALS['environment'],$GLOBALS['journal']);$h->finishRestore(110);
    $check(!$m->StartNativeMaintenance()&&$m->attributes===$before,'no maintenance while physically restored but refresh pending');
    $event=static fn(int $t,string $name,string $bytes):array=>['TimeStamp'=>$t,'SenderID'=>10,'Message'=>10206,'Data'=>[$name,base64_encode($bytes),1,0]];
    $observer=new NativeRefreshVerifier(10,20,$refresh['cursor'],'FF900000',8,$refresh['startedAt']);$at=$refresh['startedAt'];$cursor=$refresh['cursor'];
    $old=[$event($cursor,'TRANSMIT',ESP3Codec::buildReadRequest('CO_RD_IDBASE')),$event($cursor,'Parse Buffer',$b),$event($cursor,'RESULT',hex2bin('00FF900000'))];
    $check($observer->consume($old,$at+.1)==='PENDING','old cursor cannot prove fresh return');
    $check($observer->consume([$event($cursor+1,'TRANSMIT',ESP3Codec::buildReadRequest('CO_RD_IDBASE'))],$at+.2)==='PENDING','TX alone not proof');
    $check($observer->consume([$event($cursor+2,'Parse Buffer',$b)],$at+.3)==='PENDING','RX alone not proof');
    $check($observer->consume([$event($cursor+3,'RESULT',hex2bin('00FF900000'))],$at+.4)==='OBSERVED_NATIVE_REFRESH','fresh TX/RX/RESULT proof');
    $s=new C2Session(json_decode($m->attributes['C2Session'],true));$s->returned(true);$m->attributes['C2Session']=json_encode($s->state());
    $m->attributes['C2NativeRefresh']=json_encode($observer->state());$GLOBALS['cursor']+=10;
    $before=$m->attributes;$check($m->ReturnNativeMaintenance()&&$m->attributes===$before,'completed return remains idempotent');
    $check($GLOBALS['environment']->configs===$original&&count($GLOBALS['environment']->nodes)===3,'complete native restore and no leftovers');
}
$m->attributes['C2NativeRefresh']=json_encode(['status'=>'PENDING']);$before=$m->attributes;
$check(!$m->StartNativeMaintenance()&&$m->attributes===$before,'inconsistent terminal session plus pending observer cannot start');
$m->attributes['C2NativeRefresh']='[]';
foreach(['REVIEW_A','FAULT_LATCHED','NATIVE_REFRESH_PENDING']as$phase){$m->attributes['C2Session']=json_encode(['phase'=>$phase]);$before=$m->attributes;$check(!$m->StartNativeMaintenance()&&$m->attributes===$before,'invalid/pending cycle never overrun '.$phase);}
echo "PASS: {$count} focused checks; four complete cycles, duplicate start/return, new identities/cursors, no pending takeover or false proof\n";
