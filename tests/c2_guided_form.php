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
        'selectedReference'=>10,'fresh'=>false,'inventory'=>['gateway'=>['master'=>'FF900000'],'history'=>[
            ['baseID'=>'FF900080','observed'=>false,'lastSeen'=>'2026-10-04T18:42:00+02:00'],['baseID'=>'FF900100','observed'=>false]],'replacement'=>false],
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
    public function SetNativeMasterBaseID(string $base,string $source,bool $confirmed): bool { $this->buffers['master']=$source.':'.$base;return $confirmed; }
    public function render(): array { return json_decode($this->c2Form(),true); }
    public function dirty(): void { $this->c2RequestFormUpdate(); }
    public function notice(string $message): void { $this->productMessage($message,true); }
}
$count=0;$check=static function(bool $ok,string $label)use(&$count):void {if(!$ok)throw new RuntimeException($label);$count++;};
$m=new GuidedFormFixture();$m->dirty();$m->ProcessNativeFormUpdates();
$check($m->updates===[]&&$m->reads===0,'missing real SDK buffer before opening form is harmless');
$initial=$m->render();$fields=C2Presentation::fields($initial);
$check($fields['C2Start']['enabled']&&!$fields['C2Return']['visible'],'idle next action');
$check(str_contains($fields['C2Status']['caption'],'Gateway wird von IP-Symcon verwendet'),'idle native ownership wording');
$check(!in_array('saved',array_column($fields['C2TargetSource']['options'],'value'),true),'backup not an extra target source');
$check(str_contains($fields['C2HistoryChoice']['options'][1]['caption'],'2026')&&str_contains($fields['C2HistoryChoice']['options'][2]['caption'],'Datum nicht verfügbar'),'history dates without invented legacy timestamp');
$check(!isset($fields['C2MasterCurrent'],$fields['C2MasterHistory']),'single master workflow');
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
$check(str_contains($fields['C2ReviewCounter']['caption'],'8 → 7')&&$fields['C2Back']['enabled'],'structured preview and back');
$check($fields['C2ConfirmA']['caption']==='Gewünschte Base-ID schreiben','A opens final confirmation');
$m->view['session']['phase']='REVIEW_B';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(!$fields['C2ConfirmA']['visible']&&$fields['C2ConfirmB']['visible'],'only second confirmation visible');
$check(str_contains($fields['C2ConfirmB']['onClick'],'new-ui-token')&&str_contains($fields['C2ConfirmB']['onClick'],'FF900080'),'B retains actual token and target');
$check($fields['C2ConfirmB']['caption']==='Jetzt schreiben'&&$fields['C2Back']['enabled']&&$fields['C2FinalNotice']['visible'],'one explicit final confirmation');
$m->view['session']['phase']='PREWRITE_VERIFYING';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(!$fields['C2Back']['enabled']&&!$fields['C2ConfirmB']['visible'],'no cancel or extra confirm during in-flight proof');
$m->view['session']['phase']='WRITE_BLOCKED';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(!$fields['C2ConfirmB']['visible']&&$fields['C2Return']['enabled'],'blocked boundary leads to return, never a write button');
$m->view['session']['phase']='NATIVE_REFRESH_PENDING';$m->view['handoff']['phase']='RESTORED';$m->view['fresh']=false;$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(!str_contains($fields['C2Status']['caption'],'Gateway wieder an IP-Symcon übergeben'),'RESTORED alone is not displayed as proven UART return');
$m->view['nativeRestored']=true;$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check(str_contains($fields['C2Status']['caption'],'Gateway wieder an IP-Symcon übergeben')&&str_contains($fields['C2ReturnHint']['caption'],'1–2 Minuten')&&!$fields['C2Return']['enabled']&&!$fields['C2Start']['enabled'],'physical return separate from pending proof, no repeated return or takeover');
$m->view['session']['phase']='FAULT_LATCHED';$m->view['handoff']['phase']='ACTIVE';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2Return']['enabled']&&!$fields['C2Review']['enabled'],'fault allows only safe return in main flow');
$m->view['handoff']['phase']='RESTORED';$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2Start']['visible']&&!$fields['C2Return']['visible']&&str_contains($fields['C2Status']['caption'],'bereits wieder bei IP-Symcon'),'restored fault explains new start rather than an impossible second return');
$m->view['session']['phase']='RETURNED';$m->view['lastKnown']=['idbase'=>['baseIdRawHex'=>'FF900000','remainingWriteCycles'=>8]];
$m->dirty();$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2Base']['caption']==='Zuletzt gelesene Base-ID: FF900000'&&$fields['C2Start']['enabled'],'returned historical value clearly not live');
$m->SelectNativeMasterSource('history');$m->ProcessNativeFormUpdates();$fields=json_decode($m->GetBuffer('C2FormFields'),true);
$check($fields['C2MasterHistoryChoice']['visible']&&!$fields['C2MasterEntry']['visible'],'one master input visible');
$check($m->SaveSelectedNativeMaster('history','','FF900080')&&$m->buffers['master']==='history:FF900080','master history delegates original validation');
$check($m->SaveSelectedNativeMaster('manual','FF900100','')&&$m->buffers['master']==='manual:FF900100'&&!$m->SaveSelectedNativeMaster('unknown','',''),'master manual and unknown routing');
foreach($m->updates as[$name,$key,$value])$check($key!=='expanded'&&$key!=='items'&&!(in_array($name,['ManualBaseID','C2MasterEntry','C2HistoryChoice'],true)&&$key==='value'),'no scroll/input/panel reset');
$check(!str_contains(json_encode($initial),'CO_WR_IDBASE'),'no direct write action');
echo "PASS: guided native form {$count} targeted checks; stable incremental updates; unchanged token/target routing; no form reloads\n";
