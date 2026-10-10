<?php
declare(strict_types=1);
// Shared selection validation and its UI callbacks only. No transport or hardware.
require_once __DIR__.'/../libs/C2Module.php';
use EnOceanGatewayManager\Product\C2Presentation;
class SelectionValidationFixture
{
    use GatewayC2Module;
    public array $buffers=[],$updates=[],$delegations=[];
    public int $reference=10;
    public array $view=['selectedReference'=>10,'session'=>['phase'=>'MAINTENANCE_READY'],
        'fresh'=>true,'handoff'=>['phase'=>'ACTIVE'],'gateways'=>[['id'=>10,'name'=>'Synthetic gateway']],
        'inventory'=>['gateway'=>['master'=>'FF900000'],'history'=>[['baseID'=>'FF900080'],['baseID'=>'FF900100']],'replacement'=>false]];
    public function ReadPropertyInteger(string $n): int {return $this->reference;}
    public function ReadAttributeString(string $n): string {return $n==='C2Review'?'[]':'';}
    public function GetBuffer(string $n): string {return $this->buffers[$n]??'';}
    public function SetBuffer(string $n,string $v): void {$this->buffers[$n]=$v;}
    public function GetNativeMaintenanceSnapshot(): string {return json_encode($this->view);}
    private function c2InventoryView(): array {return $this->view['inventory'];}
    private function productMessage(string $m,bool $update=false): void {$this->buffers['message']=$m;}
    public function UpdateFormField(string $n,string $k,mixed $v): bool {$this->updates[]=[$n,$k,$v];return true;}
    public function SendDebug(string $n,string $v,int $f): void {throw new RuntimeException($v);}
    public function SetNativeMasterBaseID(string $b,string $s,bool $c): bool {$this->delegations[]=['master',$s,$b,$c];return true;}
    public function ReviewNativeTarget(string $b): bool {$this->delegations[]=['target','manual',$b];return true;}
    public function ReviewNativeStoredTarget(string $s,string $b): bool {$this->delegations[]=['target',$s,$b];return true;}
    public function render(): array {return json_decode($this->c2Form(),true);}
    public function fields(): array {return json_decode($this->GetBuffer('C2FormFields'),true);}
}
$n=0;$check=static function(bool $ok,string $label)use(&$n):void {if(!$ok)throw new RuntimeException($label);$n++;};
$m=new SelectionValidationFixture();$form=$m->render();$names=array_column($form['actions'],'name');$f=$m->fields();
$check(array_search('C2Validate',$names)<array_search('C2MasterSave',$names)&&array_search('C2Validate',$names)<array_search('C2Review',$names),'validation button precedes both actions');
$check($f['C2Validate']['caption']==='BASE-ID PRÜFEN'&&!$f['C2MasterSave']['enabled']&&!$f['C2Review']['enabled'],'both actions initially blocked');
foreach(['ManualBaseID','C2HistoryChoice']as$name)$check(str_contains(C2Presentation::form($m->view,'',[])['actions'][array_search($name,$names)]['onChange'],'InvalidateNativeBaseIDSelection'),'input change callback '.$name);
$check(!$m->SaveSelectedNativeMaster('manual','FF900000','')&&!$m->ReviewNativeSelectedTarget('manual','FF900000','')&&$m->delegations===[],'server refuses both actions before validation');
foreach(['FFC2F790'=>'128-Adressen','123'=>'acht Hexzeichen','GGGGGGGG'=>'acht Hexzeichen','00000000'=>'Wertebereich','FFFFFFFF'=>'Wertebereich']as$value=>$reason){
    $check(!$m->ValidateNativeSelectedBaseID('manual',(string)$value,''),'invalid value rejected '.$value);
    $f=$m->fields();$check($f['C2SelectionResult']['visible']&&str_contains($f['C2SelectionResult']['caption'],'Base-ID ungültig')&&str_contains($f['C2SelectionResult']['caption'],$reason),'visible reason '.$value);
    $check(!$f['C2MasterSave']['enabled']&&!$f['C2Review']['enabled'],'invalid value cannot unlock actions '.$value);
}
$check($m->ValidateNativeSelectedBaseID('manual','FFC2F780',''),'valid aligned manual value');$f=$m->fields();
$check($f['C2MasterSave']['enabled']&&$f['C2Review']['enabled']&&$f['C2SelectionResult']['caption']==='Base-ID FFC2F780 ist gültig.','success clearly visible and both actions unlocked');
$check($m->SaveSelectedNativeMaster('manual','FFC2F780','')&&$m->ReviewNativeSelectedTarget('manual','FFC2F780',''),'same value delegates existing actions');
$check($m->delegations===[['master','manual','FFC2F780',true],['target','manual','FFC2F780']],'unchanged downstream arguments');
$m->InvalidateNativeBaseIDSelection('manual','FFC2F700','');$f=$m->fields();
$check(!$f['C2MasterSave']['enabled']&&!$f['C2Review']['enabled']&&!str_contains($f['C2SelectionResult']['caption'],'ist gültig'),'manual edit immediately invalidates positive status');
$check(!$m->SaveSelectedNativeMaster('manual','FFC2F700','')&&!$m->ReviewNativeSelectedTarget('manual','FFC2F700',''),'edited value denied until rechecked');
$check($m->ValidateNativeSelectedBaseID('manual','FFC2F700','')&&$m->ReviewNativeSelectedTarget('manual','FFC2F700',''),'new manual value accepted only after explicit check');
$before=count($m->delegations);
$check(!$m->SaveSelectedNativeMaster('manual','FFC2F780','')&&!$m->ReviewNativeSelectedTarget('manual','FFC2F780','')&&count($m->delegations)===$before,'changed action value denied even if change callback absent');
$m->SelectNativeTargetSource('history');
$check(!$m->fields()['C2MasterSave']['enabled'],'source change invalidates');
$check($m->ValidateNativeSelectedBaseID('history','','FF900080')&&$m->SaveSelectedNativeMaster('history','','FF900080')&&$m->ReviewNativeSelectedTarget('history','','FF900080'),'one history validation serves both existing actions');
$m->InvalidateNativeBaseIDSelection('history','','FF900100');
$check(!$m->fields()['C2MasterSave']['enabled']&&!$m->fields()['C2Review']['enabled'],'different history selection immediately invalidates');
$check(!$m->SaveSelectedNativeMaster('history','','FF900100')&&!$m->ReviewNativeSelectedTarget('history','','FF900100'),'different history value requires validation');
$check($m->ValidateNativeSelectedBaseID('history','','FF900100')&&$m->fields()['C2Review']['enabled'],'history can be revalidated');
$check(!$m->ValidateNativeSelectedBaseID('history','','FF900180')&&!$m->fields()['C2MasterSave']['enabled'],'non-member history denied');
$m->SelectNativeTargetSource('master');
$check($m->ValidateNativeSelectedBaseID('master','','')&&!$m->fields()['C2MasterSave']['enabled']&&$m->fields()['C2Review']['enabled'],'stored master target also explicitly validated');
$m->view['inventory']['gateway']['master']='FF900080';$before=count($m->delegations);
$check(!$m->ReviewNativeSelectedTarget('master','','')&&count($m->delegations)===$before,'changed stored master cannot reuse prior validation');
$m->ValidateNativeSelectedBaseID('manual','FFC2F780','');$m->reference=11;
$check(!$m->SaveSelectedNativeMaster('manual','FFC2F780',''),'different native reference cannot reuse validation');$m->reference=10;
$m->ValidateNativeSelectedBaseID('manual','FFC2F780','');$m->render();
$check(!$m->fields()['C2MasterSave']['enabled'],'reopened form never inherits a proof for an old editor value');
$m->view['session']['phase']='RETURNED';$m->view['fresh']=false;$m->SelectNativeTargetSource('manual');
$m->ValidateNativeSelectedBaseID('manual','FFC2F780','');$f=$m->fields();
$check($f['C2MasterSave']['enabled']&&!$f['C2Review']['enabled'],'local master remains available outside maintenance, target keeps existing ready gate');
$check(!str_contains(json_encode($form),'CO_WR_IDBASE'),'no direct hardware write action');
echo "PASS: {$n} focused Base-ID selection-validation checks; no hardware access\n";
