<?php
declare(strict_types=1);

// Only READY presentation/persistence callbacks. No transport, journal or I/O.
// The SDK probe confirmed that even identical attribute writes emit events.
class IPSModuleStrict
{
    public function __construct(public int $InstanceID) {}
    public function ReadAttributeString(string $name): string { return $GLOBALS['ui']['attributes'][$name]??''; }
    public function WriteAttributeString(string $name,string $value): void
    { $GLOBALS['ui']['attributes'][$name]=$value; $GLOBALS['ui']['attributeEvents']++; }
    public function GetValue(string $name): string { return $GLOBALS['ui']['variables'][$name]??''; }
    public function SetValue(string $name,string $value): void
    { $GLOBALS['ui']['variables'][$name]=$value; $GLOBALS['ui']['variableEvents']++; }
    public function GetBuffer(string $name): string { return $GLOBALS['ui']['buffers'][$name]??''; }
    public function SetBuffer(string $name,string $value): void { $GLOBALS['ui']['buffers'][$name]=$value; }
    public function ReloadForm(): void { $GLOBALS['ui']['reloads']++; }
}
require_once (getenv('EGM_UI_SOURCE_ROOT')?:dirname(__DIR__)).'/EnOceanGatewayManager/module.php';
use EnOceanGatewayManager\Maintenance\C2Handoff;
use EnOceanGatewayManager\Maintenance\C2Session;

$GLOBALS['ui']=['attributes'=>['C2ResultInbox'=>'[]'], 'variables'=>[],
    'buffers'=>['C2InventorySession'=>'synthetic-session'],
    'attributeEvents'=>0,'variableEvents'=>0,'reloads'=>0];
$version=['returnName'=>'RET_OK','eurid'=>'01020304','applicationVersion'=>'1.0.0.0',
    'apiVersion'=>'1.0.0.0','deviceVersionHex'=>'01000000','applicationDescription'=>'SYNTHETIC'];
$base=['returnName'=>'RET_OK','baseIdRawHex'=>'FF800000','remainingWriteCyclesMode'=>'limited','remainingWriteCycles'=>10];
$state=['id'=>'synthetic-session','phase'=>'MAINTENANCE_READY',
    'context'=>['session'=>'synthetic-session','transportBinding'=>'synthetic-binding'],
    'snapshot'=>['version'=>$version,'idbase'=>$base], 'initial'=>[]];
for($i=0;$i<10;$i++)$state['initial'][]=['operation'=>$i%2===0?'CO_RD_VERSION':'CO_RD_IDBASE',
    'readAt'=>1700000000+$i,'value'=>$i%2===0?$version:$base];
// Handoff metadata only: never construct an environment or access a descriptor.
$handoff=(new ReflectionClass(C2Handoff::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(C2Handoff::class,'s'))->setValue($handoff,['phase'=>'ACTIVE','ownArbiter'=>400]);
$call=static function(EnOceanGatewayManager $m,string $method,mixed ...$args): mixed {
    return (new ReflectionMethod($m,$method))->invoke($m,...$args);
};
$ready='Maintenance bereit. Aktuelle Hardware frisch und konsistent erkannt. Reale Hardware-Writes bleiben gesperrt.';
$tick=static function(array $state)use($call,$handoff,$ready):void {
    // A fresh PHP object per callback, with SDK-persisted buffers/attributes.
    $m=new EnOceanGatewayManager(100);
    $call($m,'c2SaveHandoff',$handoff);
    $call($m,'c2SaveSession',new C2Session($state));
    // Also permits the previous revision as a negative control.
    if(method_exists($m,'c2WriteChanged'))$call($m,'c2WriteChanged','C2ResultInbox','[]');
    else $m->WriteAttributeString('C2ResultInbox','[]');
    $call($m,'c2PublishSnapshot',$state,$handoff);
    $call($m,'productMessage',$ready);
};
$tick($state);
if($GLOBALS['ui']['reloads']!==1)throw new RuntimeException('READY transition must reload once');
$counts=static fn():array=>array_intersect_key($GLOBALS['ui'],array_flip(['attributeEvents','variableEvents','reloads']));
$first=$counts();
for($i=0;$i<150;$i++)$tick($state); // 15 seconds at the unchanged 100-ms timer cadence.
if($counts()!==$first)throw new RuntimeException('Unchanged READY callbacks must not emit UI updates');
if(json_decode($GLOBALS['ui']['attributes']['C2Session'],true)!==$state)throw new RuntimeException('Session persistence changed');
$m=new EnOceanGatewayManager(100);
$state['phase']='FAULT_LATCHED';
$call($m,'c2SaveSession',new C2Session($state));
$fault='Kommunikations-/Kontextfehler gelatcht. Wartung zurückgeben.';
$call($m,'productMessage',$fault);
if($GLOBALS['ui']['reloads']!==2||$GLOBALS['ui']['attributeEvents']!==$first['attributeEvents']+2)
    throw new RuntimeException('Real phase/message transition must persist and reload once');
$faultCounts=$counts();
for($i=0;$i<150;$i++){
    $m=new EnOceanGatewayManager(100);
    $call($m,'c2SaveSession',new C2Session($state));
    $call($m,'productMessage',$fault);
}
if($counts()!==$faultCounts)throw new RuntimeException('Unchanged fault must remain stable');
$call($m,'productMessage',$fault,true);
if($GLOBALS['ui']['reloads']!==3)throw new RuntimeException('Explicit action refresh must remain available');
echo "PASS: 150 unchanged READY callbacks emit no attribute/value/reload events; phase transition refreshes once; unchanged fault stable; explicit action refresh retained\n";
