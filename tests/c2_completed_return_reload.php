<?php
declare(strict_types=1);
// Technical return adapter only. Synthetic metadata/descriptors, no hardware I/O.
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
    public array $nodes=[10=>['ConnectionID'=>20],20=>['InstanceStatus'=>102]];
    public ?array $fds=[['pid'=>123]];
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
        $h=(new ReflectionClass(C2Handoff::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(C2Handoff::class,'s'))->setValue($h,['id'=>'synthetic-return','phase'=>'RESTORED','snapshot'=>[
            'nativeID'=>10,'ioID'=>20,'nativeConfiguration'=>['GatewayMode'=>2],
            'ioConfiguration'=>['Open'=>true,'Port'=>'SIMULATOR']]]);return $h;
    }
    private function c2Environment(): object
    {
        return new class($this) {
            public function __construct(private CompletedReturnFixture $m) {}
            public function configuration(int $id): array {$this->m->checks++;return $this->m->configs[$id];}
            public function instance(int $id): array {return $this->m->nodes[$id];}
            public function descriptors(string $path): ?array {if($path!=='SIMULATOR')throw new RuntimeException('Unexpected device');return $this->m->fds;}
            public function selfPID(): int {return 123;}
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
foreach(['native config','serial config','parent','I/O status','unknown descriptors','no owner','foreign owner','multiple owners']as$case){
    $m=new CompletedReturnFixture();
    match($case){
        'native config'=>$m->configs[10]['GatewayMode']=1,
        'serial config'=>$m->configs[20]['Open']=false,
        'parent'=>$m->nodes[10]['ConnectionID']=21,
        'I/O status'=>$m->nodes[20]['InstanceStatus']=201,
        'unknown descriptors'=>$m->fds=null,
        'no owner'=>$m->fds=[],
        'foreign owner'=>$m->fds=[['pid'=>456]],
        'multiple owners'=>$m->fds=[['pid'=>123],['pid'=>456]],
    };
    $m->ProcessC2Maintenance();
    $check(json_decode($m->attributes['C2Session'],true)['phase']==='FAULT_LATCHED','technical return still rejects '.$case);
    $check(!isset($m->buffers['C2NativeRestoredDisplay']),'failed technical return not presented as restored '.$case);
}
echo "PASS: {$n} focused technical-return checks; no native refresh observer, no hardware access\n";
