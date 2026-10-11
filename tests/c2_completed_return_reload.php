<?php
declare(strict_types=1);
// Technical return adapter only. Synthetic Symcon metadata, no hardware I/O.
require_once __DIR__.'/../libs/C2Module.php';
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\C2Session;
function IPS_GetSnapshotChanges(int $cursor): never {throw new RuntimeException('Removed native refresh observer invoked');}
function IPS_EnableDebug(int $id,int $seconds): never {throw new RuntimeException('Removed native debug observer invoked');}
class CompletedReturnFixture
{
    use GatewayC2Module;
    public array $attributes=['C2Handoff'=>'{}','C2Session'=>'{"phase":"NATIVE_REFRESH_PENDING","faults":[]}',
        'C2NativeRefresh'=>'{"status":"PENDING"}'];
    public array $buffers=['C2RuntimeStarted'=>'1'],$timers=[],$messages=[];
    public int $checks=0;
    public array $configs=[10=>['GatewayMode'=>2],20=>['Open'=>true,'Port'=>'SIMULATOR']];
    public array $nodes=[10=>['ConnectionID'=>20,'InstanceStatus'=>102],
        20=>['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>\EnOceanGatewayManager\Maintenance\NativeGatewayResolver::SERIAL]]];
    public function ReadAttributeString(string $name): string {return $this->attributes[$name]??'{}';}
    public function WriteAttributeString(string $name,string $value): void {$this->attributes[$name]=$value;}
    public function GetBuffer(string $name): string {return $this->buffers[$name]??'';}
    public function SetBuffer(string $name,string $value): void {$this->buffers[$name]=$value;}
    public function SetTimerInterval(string $name,int $value): void {$this->timers[$name]=$value;}
    private function c2Lock(callable $f): mixed {return $f();}
    private function c2RequestFormUpdate(): void {}
    private function productMessage(string $message,bool $refresh=false): void {$this->messages[]=$message;}
    private function c2Handoff(): C2Handoff
    {
        $journal=new class implements \EnOceanGatewayManager\Safety\WriteJournal {
            private array $rows=[];
            public function append(array $record): void {$this->rows[]=$record;}
            public function records(): array {return $this->rows;}
        };
        $h=new C2Handoff($this->c2Environment(),$journal);
        (new ReflectionProperty(C2Handoff::class,'s'))->setValue($h,['id'=>'synthetic-return','phase'=>'RESTORED','snapshot'=>[
            'nativeID'=>10,'ioID'=>20,'nativeConfiguration'=>['GatewayMode'=>2],
            'ioConfiguration'=>['Open'=>true,'Port'=>'SIMULATOR']]]);return $h;
    }
    private function c2Environment(): \EnOceanGatewayManager\Maintenance\C2Environment
    {
        return new class($this) implements \EnOceanGatewayManager\Maintenance\C2Environment {
            public function __construct(private CompletedReturnFixture $m) {}
            public function configuration(int $id): array {$this->m->checks++;return $this->m->configs[$id];}
            public function instance(int $id): array {return $this->m->nodes[$id];}
            public function instances(): array {return array_keys($this->m->nodes);}
            public function create(string $module): never {throw new RuntimeException('Unexpected create');}
            public function configure(int $id,array $configuration): never {throw new RuntimeException('Unexpected configure');}
            public function connect(int $child,int $parent): never {throw new RuntimeException('Unexpected connect');}
            public function disconnect(int $child): never {throw new RuntimeException('Unexpected disconnect');}
            public function delete(int $id): never {throw new RuntimeException('Unexpected delete');}
        };
    }
    private function c2Fail(string $reason): void {$s=$this->c2Session();$s->fault($reason,1);$this->c2SaveSession($s);}
}
$n=0;$check=static function(bool $ok,string $label)use(&$n):void {if(!$ok)throw new RuntimeException($label);$n++;};
$m=new CompletedReturnFixture();$m->ProcessC2Maintenance();
$check(json_decode($m->attributes['C2Session'],true)['phase']==='RETURNED','restored transport completes immediately without native observer');
$check($m->attributes['C2NativeRefresh']==='[]'&&$m->timers['C2Timer']===0,'no fabricated refresh evidence, no wait timer');
$before=$m->checks;for($i=0;$i<5;$i++)$m->ProcessC2Maintenance();
$check($m->checks===$before&&$m->timers['C2Timer']===0,'completed return timer callbacks stay idle');
foreach(['native config','serial config','parent','I/O status','native status','shared I/O','duplicate endpoint']as$case){
    $m=new CompletedReturnFixture();
    match($case){
        'native config'=>$m->configs[10]['GatewayMode']=1,
        'serial config'=>$m->configs[20]['Open']=false,
        'parent'=>$m->nodes[10]['ConnectionID']=21,
        'I/O status'=>$m->nodes[20]['InstanceStatus']=201,
        'native status'=>$m->nodes[10]['InstanceStatus']=201,
        'shared I/O'=>$m->nodes[30]=['ConnectionID'=>20],
        'duplicate endpoint'=>[$m->nodes[30]=['ConnectionID'=>0,'ModuleInfo'=>['ModuleID'=>\EnOceanGatewayManager\Maintenance\NativeGatewayResolver::SERIAL]],$m->configs[30]=['Open'=>true,'Port'=>'SIMULATOR']],
    };
    $m->ProcessC2Maintenance();
    $check(json_decode($m->attributes['C2Session'],true)['phase']==='FAULT_LATCHED','technical return still rejects '.$case);
    $check(!isset($m->buffers['C2NativeRestoredDisplay']),'failed technical return not presented as restored '.$case);
}
echo "PASS: {$n} focused technical-return checks; no native refresh observer, no hardware access\n";
