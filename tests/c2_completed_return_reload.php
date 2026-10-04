<?php
declare(strict_types=1);
// Only the library-reload callback of an already restored handoff. No I/O.
require_once __DIR__.'/../libs/C2Module.php';
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\C2Session;
class CompletedReturnFixture
{
    use GatewayC2Module;
    public string $phase='RETURNED',$status='OBSERVED_NATIVE_REFRESH';
    public int $observations=0;
    public array $timers=[];
    public function ReadAttributeString(string $name): string
    { return $name==='C2NativeRefresh'?json_encode(['status'=>$this->status]):'{}'; }
    public function GetBuffer(string $name): string { return '1'; }
    public function SetTimerInterval(string $name,int $value): void { $this->timers[$name]=$value; }
    private function c2Lock(callable $f): mixed { return $f(); }
    private function c2Handoff(): C2Handoff
    {
        $h=(new ReflectionClass(C2Handoff::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(C2Handoff::class,'s'))->setValue($h,['phase'=>'RESTORED']);return $h;
    }
    private function c2SaveHandoff(C2Handoff $h): void {}
    private function c2Session(): C2Session { return new C2Session(['phase'=>$this->phase]); }
    private function c2ObserveNativeRefresh(C2Handoff $h,float $now): void { $this->observations++; }
    private function c2Fail(string $reason): void { throw new RuntimeException($reason); }
}
$m=new CompletedReturnFixture();
for($i=0;$i<5;$i++)$m->ProcessC2Maintenance();
if($m->observations!==0||($m->timers['C2Timer']??null)!==0)throw new RuntimeException('Completed return must not requery an expired cursor');
foreach([['NATIVE_REFRESH_PENDING','PENDING'],['NATIVE_REFRESH_PENDING','OBSERVED_NATIVE_REFRESH'],
    ['RETURNED','PENDING'],['FAULT_LATCHED','OBSERVED_NATIVE_REFRESH'],['RETURN_WARNING','WARNING']]as[$phase,$status]){
    $m=new CompletedReturnFixture();$m->phase=$phase;$m->status=$status;$m->ProcessC2Maintenance();
    if($m->observations!==1||isset($m->timers['C2Timer']))throw new RuntimeException('Uncompleted or inconsistent proof must still be examined');
}
echo "PASS: completed return stops a re-registered timer; five non-completed/inconsistent cases still require observation\n";
