<?php
declare(strict_types=1);
// Presentation and incremental SDK callbacks only; no environment, I/O or sends.
require_once __DIR__.'/../libs/C2Module.php';
require_once __DIR__.'/../libs/ProductModule.php';
use EnOceanGatewayManager\Product\C2Presentation;
class GuidedFormFixture
{
    use GatewayC2Module;
    use GatewayProductModule;
    public int $reads=0;
    public array $buffers=[],$updates=[],$attributes=['SavedBaseID'=>'FF900000','C2Review'=>'[]','ProductMessage'=>''];
    public array $view=['session'=>['phase'=>'IDLE'],'handoff'=>['phase'=>'IDLE'],
        'fresh'=>false,'inventory'=>['gateway'=>['master'=>'FF900000'],'history'=>[
            ['baseID'=>'FF900080','observed'=>false]],'replacement'=>false],
        'gateways'=>[['name'=>'Synthetic gateway','id'=>10]],'message'=>''];
    public function ReadPropertyInteger(string $name): int { return 10; }
    public function ReadAttributeString(string $name): string { return $this->attributes[$name]??''; }
    public function WriteAttributeString(string $name,string $value): void { $this->attributes[$name]=$value; }
    public function GetBuffer(string $name): string|false { return $this->buffers[$name]??false; }
    public function SetBuffer(string $name,string $value): void { $this->buffers[$name]=$value; }
    public function GetNativeMaintenanceSnapshot(): string { $this->reads++;return json_encode($this->view); }
    public function UpdateFormField(string $name,string $key,mixed $value): bool { $this->updates[]=[$name,$key,$value];return true; }
    public function ReloadForm(): void { throw new RuntimeException('Native UI must not reload the full form'); }
    public function SendDebug(string $name,string $value,int $format): void { throw new RuntimeException($value); }
    public function ReviewNativeTarget(string $target): bool { $this->buffers['manualTarget']=$target;return true; }
    public function ReviewNativeStoredTarget(string $source,string $target): bool { $this->buffers['storedTarget']=$source.':'.$target;return true; }
    public function render(): array { return json_decode($this->c2Form(),true); }
    public function dirty(): void { $this->c2RequestFormUpdate(); }
    public function notice(string $message): void { $this->productMessage($message,true); }
}
$count=0;$check=static function(bool $ok,string $label)use(&$count):void {if(!$ok)throw new RuntimeException($label);$count++;};
$m=new GuidedFormFixture();$m->dirty();$m->ProcessNativeFormUpdates();
$check($m->updates===[]&&$m->reads===0,'missing real SDK buffer before opening form is harmless');
$initial=$m->render();$fields=C2Presentation::fields($initial);
$check($fields['C2Start']['enabled']&&!$fields['C2Return']['visible'],'idle next action');
$m->view['session']=['phase'=>'MAINTENANCE_READY','snapshot'=>['idbase'=>['baseIdRawHex'=>'FF900000','remainingWriteCycles'=>8,'remainingWriteCyclesMode'=>'finite']]];
$m->view['handoff']=['phase'=>'ACTIVE'];$m->view['fresh']=true;$m->dirty();$m->ProcessNativeFormUpdates();
$check(count($m->updates)>0,'READY incremental update');
$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2Review']['visible']&&$fields['C2Review']['enabled']&&!$fields['C2Start']['visible'],'ready next action');
$check(!$fields['NativeGatewayInstanceID']['enabled'],'selection locked during maintenance');
$before=count($m->updates);$reads=$m->reads;
for($i=0;$i<150;$i++)$m->ProcessNativeFormUpdates();
$check(count($m->updates)===$before&&$m->reads===$reads,'stable callbacks have no UI/metadata work');
$m->SelectNativeTargetSource('history');$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2HistoryChoice']['visible']&&!$fields['ManualBaseID']['visible'],'history selects one input');
$check($m->ReviewNativeSelectedTarget('manual','FF900080',''),'manual routing');
$check($m->buffers['manualTarget']==='FF900080','manual input unchanged');
$check($m->ReviewNativeSelectedTarget('history','','FF900080')&&$m->buffers['storedTarget']==='history:FF900080','history uses existing source validation');
$check(!$m->ReviewNativeSelectedTarget('unknown','FF900080',''),'unknown source denied');
$m->attributes['C2Review']=json_encode(['current'=>'FF900000','target'=>'FF900080','remaining'=>8,'expectedRemaining'=>7,'token'=>'new-ui-token']);
$m->view['session']['phase']='REVIEW_A';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2ConfirmA']['visible']&&!$fields['C2ConfirmB']['visible'],'only first confirmation visible');
$check(str_contains($fields['C2ConfirmA']['onClick'],'new-ui-token'),'actual confirmation token carried to action');
$check(str_contains($fields['C2ReviewSummary']['caption'],'8 → 7'),'human counter preview');
$m->view['session']['phase']='REVIEW_B';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(!$fields['C2ConfirmA']['visible']&&$fields['C2ConfirmB']['visible'],'only second confirmation visible');
$check(str_contains($fields['C2ConfirmB']['onClick'],'new-ui-token')&&str_contains($fields['C2ConfirmB']['onClick'],'FF900080'),'B retains actual token and target');
$m->view['session']['phase']='WRITE_BLOCKED';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(!$fields['C2ConfirmB']['visible']&&$fields['C2Return']['enabled'],'blocked boundary leads to return, never a write button');
$m->view['session']['phase']='NATIVE_REFRESH_PENDING';$m->view['handoff']['phase']='RESTORED';$m->view['fresh']=false;$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(str_contains($fields['C2Status']['caption'],'1–2 Minuten')&&!$fields['C2Return']['enabled'],'return wait explained; no unnecessary repeated return');
$m->view['session']['phase']='FAULT_LATCHED';$m->view['handoff']['phase']='ACTIVE';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2Return']['enabled']&&!$fields['C2Review']['enabled'],'fault allows only safe return in main flow');
foreach($m->updates as[$name,$key,$value])$check($key!=='expanded'&&$key!=='items'&&!(in_array($name,['ManualBaseID','C2MasterEntry','C2HistoryChoice'],true)&&$key==='value'),'no scroll/input/panel reset');
$check(!str_contains(json_encode($initial),'CO_WR_IDBASE'),'no direct write action');
echo "PASS: guided native form {$count} targeted checks; stable incremental updates; unchanged token/target routing; no form reloads\n";
