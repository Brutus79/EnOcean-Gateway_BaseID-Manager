<?php
declare(strict_types=1);
// Only selection -> review; real session/adapter, synthetic context, no send path.
require_once __DIR__.'/../libs/C2Module.php';
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Product\C2Presentation;
use EnOceanGatewayManager\Protocol\ESP3Codec;

class TargetReviewFixture
{
    use GatewayC2Module;
    public array $attributes=['C2Review'=>'[]'],$buffers=[];
    public array $context=['session'=>'synthetic-session','transportBinding'=>'transport','handoffBinding'=>'handoff',
        'exclusive'=>true,'faultEpoch'=>0,'writeLeaseActive'=>false,'noUnknownOutcome'=>true];
    public bool $replacement=false,$contextUnavailable=false;
    public int $contextChecks=0;
    public function __construct()
    {
        $s=new C2Session();$s->start($this->context,1);
        $frame=static function(string $d,string $o=''):string{
            $h=pack('nCC',strlen($d),strlen($o),2);
            return "\x55".$h.chr(ESP3Codec::crc8($h)).$d.$o.chr(ESP3Codec::crc8($d.$o));
        };
        $version=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SIMULATOR',16,"\0"));
        $base=$frame(hex2bin('00FF900000'),"\x07");
        for($i=0;$i<10;$i++){
            $r=$s->request($this->context,2+$i/10);
            $s->response($r['token'],$r['operation'],$i%2?$base:$version,$this->context,2+$i/10+.01);
        }
        $this->attributes['C2Session']=json_encode($s->state(),JSON_THROW_ON_ERROR);
    }
    public function state(): array {return json_decode($this->attributes['C2Session'],true);}
    public function ReadPropertyInteger(string $n): int {return 10;}
    public function ReadAttributeString(string $n): string {return $this->attributes[$n]??'[]';}
    public function WriteAttributeString(string $n,string $v): void {$this->attributes[$n]=$v;}
    public function GetBuffer(string $n): string {return $this->buffers[$n]??'';}
    public function SetBuffer(string $n,string $v): void {$this->buffers[$n]=$v;}
    private function c2Lock(callable $f): mixed {return $f();}
    private function c2RequestFormUpdate(): void {}
    public function ProcessNativeFormUpdates(): void {}
    private function productMessage(string $m,bool $refresh=false): void {$this->attributes['ProductMessage']=$m;}
    private function c2InventoryView(): array {return ['gateway'=>['master'=>'FF900000'],'history'=>[['baseID'=>'FF900000']], 'replacement'=>$this->replacement];}
    private function c2Context(C2Handoff $h): array {
        $this->contextChecks++;
        if($this->contextUnavailable)throw new RuntimeException('Synthetic transport unavailable');
        return $this->context;
    }
    private function c2Handoff(): C2Handoff {
        $e=new class implements \EnOceanGatewayManager\Maintenance\C2Environment {
            public function instance(int $id): never {throw new RuntimeException('Unexpected I/O');}
            public function configuration(int $id): never {throw new RuntimeException('Unexpected I/O');}
            public function instances(): never {throw new RuntimeException('Unexpected I/O');}
            public function create(string $m): never {throw new RuntimeException('Unexpected I/O');}
            public function configure(int $id,array $c): never {throw new RuntimeException('Unexpected I/O');}
            public function connect(int $c,int $p): never {throw new RuntimeException('Unexpected I/O');}
            public function disconnect(int $c): never {throw new RuntimeException('Unexpected I/O');}
            public function delete(int $id): never {throw new RuntimeException('Unexpected I/O');}
        };
        $j=new class implements \EnOceanGatewayManager\Safety\WriteJournal {
            public function append(array $r): never {throw new RuntimeException('Unexpected journal write');}
            public function records(): array {return [];}
        };
        return new C2Handoff($e,$j);
    }
    public function SendDataToParent(string $p): never {throw new RuntimeException('Unexpected hardware command');}
}
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;};
foreach(['manual','master','history']as$source){
    $m=new TargetReviewFixture();$before=$m->state();
    $check($m->ValidateNativeSelectedBaseID($source,'FF900000','FF900000'),'exact selection validates');
    $check(!$m->ReviewNativeSelectedTarget($source,'FF900000','FF900000'),'identical target never starts a write review');
    if(in_array('--expect-old',$argv,true)){
        $check($m->state()['phase']==='FAULT_LATCHED'&&$m->state()['faults'][0]['reason']==='Target already current.','old no-op selection reproduces safety stop');
        continue;
    }
    $check($m->state()===$before&&$m->attributes['C2Review']==='[]','no-op preserves ready proof, counter and empty confirmation');
    $check($m->contextChecks===1,'live context still verified');
    $v=['session'=>$m->state(),'fresh'=>true,'handoff'=>['phase'=>'ACTIVE'],'selectedReference'=>10,
        'inventory'=>['gateway'=>['master'=>'FF900000'],'history'=>[]],'message'=>$m->attributes['ProductMessage']];
    $fields=array_column(C2Presentation::form($v,'',[])['actions'],null,'name');
    $check($fields['C2Notice']['visible']&&str_contains($fields['C2Notice']['caption'],'bereits')&&str_contains($fields['C2Notice']['caption'],'Keine Änderung erforderlich'),'no-op clearly visible');
    $check(str_starts_with($fields['C2Notice']['caption'],'!!! HINWEIS: ')&&str_ends_with($fields['C2Notice']['caption'],' !!!'),'no-op notice explicitly highlighted');
    $check($fields['C2Return']['enabled'],'normal return remains available');
}
if(in_array('--expect-old',$argv,true)){echo "PASS: {$n} checks reproduce old target-already-current safety stop; no hardware access\n";exit;}
$m=new TargetReviewFixture();
$check($m->ValidateNativeSelectedBaseID('manual','FF900080','')&&$m->ReviewNativeSelectedTarget('manual','FF900080',''),'different validated target follows existing review');
$check($m->state()['phase']==='REVIEW_A'&&$m->state()['target']==='FF900080'&&$m->state()['faults']===[],'confirmation still mandatory for a change');
foreach(['session','transportBinding','handoffBinding','exclusive','faultEpoch','writeLeaseActive','noUnknownOutcome','transport','replacement']as$key){
    $m=new TargetReviewFixture();
    if($key==='transport')$m->contextUnavailable=true;
    elseif($key==='replacement')$m->replacement=true;
    else $m->context[$key]=match($key){'exclusive','noUnknownOutcome'=>false,'writeLeaseActive'=>true,'faultEpoch'=>1,default=>'changed'};
    $m->ValidateNativeSelectedBaseID('manual','FF900000','');
    $check(!$m->ReviewNativeSelectedTarget('manual','FF900000','')&&$m->state()['phase']==='FAULT_LATCHED','real context fault still stops even identical target: '.$key);
}
$m=new TargetReviewFixture();$before=$m->state();
$check(!$m->ReviewNativeSelectedTarget('manual','FF900000','')&&$m->state()===$before,'missing exact validation never reaches review');
$check(!$m->ValidateNativeSelectedBaseID('manual','FF900010','')&&$m->state()===$before,'alignment validation retained');
echo "PASS: {$n} focused target-review checks; no hardware access or write commands\n";
