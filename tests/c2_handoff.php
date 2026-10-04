<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/C2Handoff.php';
use EnOceanGatewayManager\Maintenance\C2Environment;
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\NativeGatewayResolver;
use EnOceanGatewayManager\Safety\WriteJournal;

$count=0;$check=static function(bool$b,string$l)use(&$count):void{$count++;if(!$b)throw new RuntimeException($l);};
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
[$e,$j,$h]=$factory();$original=$e->configs;
$h->begin(40,10,100);$check($h->state()['phase']==='CLOSING_NATIVE','closed before detach');
$check($e->nodes[10]['ConnectionID']===20,'native still connected during close');
$check($e->descriptors('SIMULATOR')===[],'zero descriptor handoff');
$h->advance(101);$check($h->verifyActive()!=='','active ownership');
$check($e->nodes[10]['ConnectionID']===0,'native detached');
$check(count($e->descriptors('SIMULATOR'))===1,'one descriptor');
$h=new C2Handoff($e,$j);$h->restore(110);$h->finishRestore(111);
$check($h->state()['phase']==='RESTORED','restart return');
$check($e->configs===$original&&count($e->nodes)===3,'complete restore and own deletion');
$check($e->nodes[10]['ConnectionID']===20&&$e->nodes[40]['ConnectionID']===0,'parents restored');
foreach(['nativeConfig','nativeParent','ioConfig','ownConfig','ownMarker','foreignChild','owner']as$fault){
    [$e,$j,$h]=$factory();$h->begin(40,10,100);$h->advance(101);$s=$h->state();
    switch($fault){
        case'nativeConfig':$e->configs[10]['BaseID']='FF910000';break;
        case'nativeParent':$e->nodes[10]['ConnectionID']=20;break;
        case'ioConfig':$e->configs[20]['BaudRate']='115200';break;
        case'ownConfig':$e->configs[$s['ownIO']]['Port']='FOREIGN';break;
        case'ownMarker':$e->nodes[$s['ownIO']]['C2OwnershipIdent']='FOREIGN';break;
        case'foreignChild':$e->nodes[41]=['ConnectionID'=>$s['ownIO']];break;
        case'owner':$e->configs[20]['Open']=true;break;
    }
    try{$h->verifyActive();$check(false,'context fault '.$fault);}catch(RuntimeException$ex){$check(true,'context fault '.$fault);}
}
// Fail after each ordinary mutation in the start path; no write/session resume.
// A crash inside Create before the returned ID is recorded leaves a CLOSED orphan,
// but cannot acquire the UART. No unknown/foreign object is deleted automatically.
for($at=1;$at<=9;$at++){
    [$e,$j,$h]=$factory();$e->faultAt=$at;
    try{$h->begin(40,10,100);$h->advance(101);}catch(RuntimeException$ex){}
    $e->faultAt=0;$h=new C2Handoff($e,$j);
    try{$h->restore(110);$h->finishRestore(111);}
    catch(RuntimeException$ex){throw new RuntimeException('Start crash recovery mutation '.$at.':'.$ex->getMessage());}
    $check($e->nodes[10]['ConnectionID']===20&&$e->configs[20]['Open']===true,'restore start cut '.$at);
    $check(count($e->descriptors('SIMULATOR'))===1,'no duplicate owner after crash '.$at);
}
// Foreign configuration remains untouched on restore failure.
[$e,$j,$h]=$factory();$h->begin(40,10,100);$h->advance(101);$e->configs[20]['BaudRate']='115200';
$h->restore(110);
try{$h->finishRestore(111);$check(false,'CAS restore must reject');}catch(RuntimeException$ex){$check(true,'CAS restore rejected');}
$check($e->configs[20]['BaudRate']==='115200'&&$e->configs[20]['Open']===false,'foreign data preserved closed');
echo "PASS: C2 handoff {$count} assertions\n";
