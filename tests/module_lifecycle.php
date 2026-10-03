<?php

declare(strict_types=1);

// Deliberately small test double: validates our callback/state contract, not
// native IP-Symcon behaviour. Every callback uses a newly constructed object.
$GLOBALS['egmTest'] = ['instances' => [], 'sent' => [], 'locks' => []];
foreach(['FM_CONNECT'=>11101,'FM_DISCONNECT'=>11102,'IM_CHANGESTATUS'=>10505,'IPS_KERNELSTARTED'=>10001]as$constant=>$value)if(!defined($constant))define($constant,$value);
class IPSModuleStrict
{
    public function __construct(public int $InstanceID) {}
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function ReceiveData(string $JSONString): string { return ''; }
    public function RegisterPropertyInteger(string $k, int $v): void { $this->register('properties', $k, $v); }
    public function RegisterPropertyBoolean(string $k, bool $v): void { $this->register('properties', $k, $v); }
    public function RegisterPropertyString(string $k, string $v): void { $this->register('properties', $k, $v); }
    public function RegisterAttributeString(string $k, string $v): void { $this->register('attributes', $k, $v); }
    public function RegisterVariableString(string $k, string $v): void { $this->register('variables', $k, ''); }
    private function register(string $group, string $k, mixed $v): void { $GLOBALS['egmTest']['instances'][$this->InstanceID][$group][$k] ??= $v; }
    public function ReadPropertyInteger(string $k): int { return $GLOBALS['egmTest']['instances'][$this->InstanceID]['properties'][$k]; }
    public function ReadPropertyBoolean(string $k): bool { return $GLOBALS['egmTest']['instances'][$this->InstanceID]['properties'][$k]; }
    public function ReadPropertyString(string $k): string { return $GLOBALS['egmTest']['instances'][$this->InstanceID]['properties'][$k]; }
    public function ReadAttributeString(string $k): string { return $GLOBALS['egmTest']['instances'][$this->InstanceID]['attributes'][$k]; }
    public function WriteAttributeString(string $k, string $v): void { $GLOBALS['egmTest']['instances'][$this->InstanceID]['attributes'][$k] = $v; }
    public function SetValue(string $k, string $v): void { $GLOBALS['egmTest']['instances'][$this->InstanceID]['variables'][$k] = $v; }
    public function GetValue(string $k): string { return $GLOBALS['egmTest']['instances'][$this->InstanceID]['variables'][$k] ?? ''; }
    public function GetBuffer(string $k): string { return $GLOBALS['egmTest']['instances'][$this->InstanceID]['buffers'][$k] ?? ''; }
    public function SetBuffer(string $k, string $v): void { $GLOBALS['egmTest']['instances'][$this->InstanceID]['buffers'][$k] = $v; }
    public function HasActiveParent(): bool { return $GLOBALS['egmTest']['instances'][$this->InstanceID]['active'] ?? true; }
    public function RegisterTimer(string $k, int $v, string $s): void {}
    public function SetStatus(int $status): void { $GLOBALS['egmTest']['instances'][$this->InstanceID]['status']=$status; }
    public function RegisterMessage(int $id,int $message): void { $GLOBALS['egmTest']['instances'][$this->InstanceID]['messages'][$id][$message]=true; }
    public function UnregisterMessage(int $id,int $message): void { unset($GLOBALS['egmTest']['instances'][$this->InstanceID]['messages'][$id][$message]); }
    public function MessageSink(int $at,int $id,int $message,array $data): void {}
    public function ReloadForm(): void {}
    public function SendDebug(string $k, string $v, int $format): void {}
    public function SendDataToParent(string $json): string
    {
        $packet = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $GLOBALS['egmTest']['sent'][] = [$this->InstanceID, $packet];
        if ($this->InstanceID === 101) { return (new ESP3TransportArbiter(200))->ForwardData($json); }
        $audit = json_decode($this->ReadAttributeString('TrafficAudit'), true);
        if (($audit[array_key_last($audit)]['frameHex'] ?? '') !== ($packet['Buffer'] ?? null)) { throw new RuntimeException('Send attempted before persistent audit'); }
        if ($GLOBALS['egmTest']['throwParentSend'] ?? false) { throw new RuntimeException('Simulated parent send failure'); }
        return '';
    }
    public function SendDataToChildren(string $json): void
    {
        if ((json_decode($json, true)['DataID'] ?? '') === '{4AE7CA23-1782-4C98-81E2-2BA7FC918C2C}') {
            (new EnOceanGatewayManager(101))->ReceiveData($json);
        }
    }
}
function IPS_GetInstance(int $id): array { return ['ConnectionID' => $id === 101 ? 200 : ($id === 200 ? 300 : 0),
    'ModuleInfo' => ['ModuleID' => $id === 300 ? '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}' : ($id === 101 ? '{ED8F6F0C-D57F-4E0F-B23B-63CF05AE9643}' : '{C5D65AB1-045B-40ED-B854-3D74D41C81EC}')]]; }
function IPS_GetKernelDir(): string { return $GLOBALS['egmTest']['kernelDir'] ??= sys_get_temp_dir() . '/egm-test-' . bin2hex(random_bytes(8)); }
function IPS_GetProperty(int $id, string $key): mixed { return $GLOBALS['egmTest']['instances'][$id]['properties'][$key] ?? ''; }
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['egmTest']['instances'][$id]['properties'] ?? []); }
function EGMA_GetReadSafetyContext(int $id): string {
    $context = json_decode((new ESP3TransportArbiter($id))->GetReadSafetyContext(), true);
    if ($GLOBALS['egmTest']['simulateExclusiveUART'] ?? false) { $context['exclusiveUARTOwner'] = true; }
    return json_encode($context);
}
function EGMA_GetTransportState(int $id): string { return (new ESP3TransportArbiter($id))->GetTransportState(); }
function EGMA_GetWriteTransactionView(int $id): string { return (new ESP3TransportArbiter($id))->GetWriteTransactionView(); }
function IPS_InstanceExists(int $id): bool { return true; }
function IPS_GetInstanceList(): array { return [101,200,300]; }
function IPS_SemaphoreEnter(string $name, int $ms): bool
{
    if ($GLOBALS['egmTest']['locks'][$name] ?? false) { return false; }
    $GLOBALS['egmTest']['locks'][$name] = true; return true;
}
function IPS_SemaphoreLeave(string $name): void { $GLOBALS['egmTest']['locks'][$name] = false; }

require_once __DIR__ . '/../ESP3TransportArbiter/module.php';
require_once __DIR__ . '/../EnOceanGatewayManager/module.php';

$passed = 0;
$check = static function (bool $ok, string $name) use (&$passed): void {
    if (!$ok) { throw new RuntimeException($name); } $passed++;
};
$state = static fn (): array => json_decode($GLOBALS['egmTest']['instances'][200]['attributes']['CoreState'], true);
$deliver = static function (string $hex): void {
    (new ESP3TransportArbiter(200))->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => $hex]));
};
(new ESP3TransportArbiter(200))->Create();
(new EnOceanGatewayManager(101))->Create();
$GLOBALS['egmTest']['instances'][200]['properties']['EnableMaintenance'] = true;
$GLOBALS['egmTest']['instances'][101]['properties']['EnableReadActions'] = true;
(new ESP3TransportArbiter(200))->ApplyChanges();
$check((new EnOceanGatewayManager(101))->ReadHardwareBaseID(), 'Initial fresh Base ID read');
$deliver('5500050102DB00FF9707000A2D');
$check(!(new EnOceanGatewayManager(101))->SaveCurrentBaseID(), 'Backup requires associated fresh EURID');
$check((new EnOceanGatewayManager(101))->ReadGatewayInformation(), 'Version read before backup');
$deliver('55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F');
$check((new EnOceanGatewayManager(101))->ReadHardwareBaseID(), 'Read accepted through real adapter');
$token = $state()['activeToken'];
$check(is_string($token), 'Active request stored');
$check(!(new EnOceanGatewayManager(101))->ReadFilters(), 'Second read blocked while pending');
$deliver('55000501');
$check($state()['incomingBufferedBytes'] === 4 && $state()['activeToken'] === $token, 'Fragment survives new PHP instance');
$deliver('02DB00FF9707000A2D');
$check($GLOBALS['egmTest']['instances'][101]['attributes']['HardwareBaseID'] === 'FF970700', 'Base ID delivered after fragmented callbacks');
$check($state()['activeToken'] === null, 'Lease released in shared state');
$check((new EnOceanGatewayManager(101))->SaveCurrentBaseID(), 'Explicit instance backup');
$saved = $GLOBALS['egmTest']['instances'][101]['attributes']['SavedBaseID'];
$check($saved === 'FF970700', 'Backup stored');
(new EnOceanGatewayManager(101))->ApplyChanges();
$check($GLOBALS['egmTest']['instances'][101]['attributes']['SavedBaseID'] === $saved, 'Backup survives ApplyChanges');
$check(!(new EnOceanGatewayManager(101))->SaveCurrentBaseID(), 'Old measurement invalidated on lifecycle change');
$before = count($GLOBALS['egmTest']['sent']);
(new EnOceanGatewayManager(101))->DeleteSavedBaseID();
$check(count($GLOBALS['egmTest']['sent']) === $before && $GLOBALS['egmTest']['instances'][101]['attributes']['SavedBaseID'] === '', 'Delete backup sends no hardware command');
$GLOBALS['egmTest']['instances'][101]['properties']['ManualBaseID'] = '0000A000';
$check(!(new EnOceanGatewayManager(101))->PrepareBaseIDPreview('manual') && count($GLOBALS['egmTest']['sent']) === $before, 'Invalid manual ID rejected before communication');
$GLOBALS['egmTest']['instances'][101]['properties']['ManualBaseID'] = 'FF970780';
$check((new EnOceanGatewayManager(101))->PrepareBaseIDPreview('manual'), 'Valid preview starts fresh read');
$deliver('5500050102DB00FF9707000A2D');
$preview = json_decode($GLOBALS['egmTest']['instances'][101]['attributes']['BaseIDPreview'], true);
$check($preview['currentBaseID'] === 'FF970700' && $preview['expectedRemaining'] === 9 && !$preview['hardwareWriteEnabled'], 'Preview uses fresh hardware counter');
$check((new EnOceanGatewayManager(101))->ReadFilters(), 'Filter read accepted');
$deliver('550001000265020E');
$observations = json_decode($GLOBALS['egmTest']['instances'][101]['attributes']['ReadObservations'], true);
$check($observations['CO_RD_FILTER']['capability'] === 'NOT_SUPPORTED', 'Unsupported capability normal');
$check((new EnOceanGatewayManager(101))->ReadHardwareBaseID(), 'Read before restart accepted');
unset($GLOBALS['egmTest']['instances'][200]['buffers']);
(new ESP3TransportArbiter(200))->ApplyChanges();
$check($state()['correlationUnsafe'] && $GLOBALS['egmTest']['instances'][101]['attributes']['PendingToken'] === '', 'Lost runtime fails closed and reports unknown outcome');
$check(!(new EnOceanGatewayManager(101))->ReadHardwareBaseID(), 'Restart cannot silently reuse UART correlation');
$GLOBALS['egmTest']['instances'][200]['active'] = false;
(new ESP3TransportArbiter(200))->ProcessTimeouts();
$GLOBALS['egmTest']['instances'][200]['active'] = true;
(new ESP3TransportArbiter(200))->ProcessTimeouts();
$check(!$state()['correlationUnsafe'], 'Explicit observed disconnect/reconnect clears gate');
$before = count($GLOBALS['egmTest']['sent']);
(new ESP3TransportArbiter(200))->ForwardData(json_encode([
    'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
    'Buffer' => strtoupper(bin2hex(\EnOceanGatewayManager\Protocol\ESP3Codec::buildWriteIdBaseRequest('FF970780'))),
]));
$check(count($GLOBALS['egmTest']['sent']) === $before, 'Isolated mode blocks native writing bypass');
$GLOBALS['egmTest']['throwParentSend'] = true;
$check((new EnOceanGatewayManager(101))->ReadHardwareBaseID(), 'Send failure admitted only once');
$check($state()['correlationUnsafe'], 'Parent send failure requires actual reconnect, not inferred reconnect');
$check(!(new EnOceanGatewayManager(101))->ReadHardwareBaseID(), 'No retry after uncertain parent send');
$GLOBALS['egmTest']['throwParentSend'] = false;
$form = json_decode((new EnOceanGatewayManager(101))->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$check(is_array($form['actions']), 'Form JSON generated');
// B5 adapter tests: ownership is deliberately simulated here; actual /proc
// validation is covered separately on the authorized Raspberry Pi.
$GLOBALS['egmTest']['instances'][200]['active'] = false;
(new ESP3TransportArbiter(200))->ProcessTimeouts();
$GLOBALS['egmTest']['instances'][200]['active'] = true;
(new ESP3TransportArbiter(200))->ProcessTimeouts();
$GLOBALS['egmTest']['simulateExclusiveUART'] = true;
$GLOBALS['egmTest']['instances'][101]['properties']['BaseIDSource'] = 'manual';
$GLOBALS['egmTest']['instances'][101]['properties']['ManualBaseID'] = 'FFC2F700';
$check((new EnOceanGatewayManager(101))->BeginWritePreparation(), 'B5 starts fresh version read');
$check(!(new EnOceanGatewayManager(101))->ReadHardwareBaseID(), 'Other manager requests blocked during preparation');
$pending = $GLOBALS['egmTest']['instances'][101]['attributes']['PendingToken'];
(new EnOceanGatewayManager(101))->ReceiveData(json_encode(['DataID' => '{4AE7CA23-1782-4C98-81E2-2BA7FC918C2C}', 'OwnerInstanceID' => 101,
    'Token' => $pending, 'Operation' => 'CO_RD_IDBASE', 'Outcome' => 'RESPONSE', 'FrameHex' => '5500050102DB00FFC2F7800A45']));
$check($GLOBALS['egmTest']['instances'][101]['attributes']['PendingToken'] === $pending, 'Wrong correlated operation ignored, no command echo invented');
$deliver('55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F');
(new EnOceanGatewayManager(101))->ProcessWritePreparation();
$check($GLOBALS['egmTest']['instances'][101]['attributes']['PendingOperation'] === 'CO_RD_IDBASE', 'Deferred timer starts mandatory base/counter read without lock reentry');
$deliver('5500050102DB00FFC2F7800A45');
(new EnOceanGatewayManager(101))->ProcessWritePreparation();
$p = json_decode($GLOBALS['egmTest']['instances'][101]['attributes']['WritePreparation'], true);
$check($p['currentBaseID'] === 'FFC2F780' && $p['expectedRemaining'] === 9 && !$p['readyForFutureAuthorization'], 'Fresh live preview blocks missing backup');
$check((new EnOceanGatewayManager(101))->SaveCurrentBaseID(), 'Backup binds fresh parent chain');
$before = count($GLOBALS['egmTest']['sent']);
$check((new EnOceanGatewayManager(101))->ConfirmSavedBackupIdentity(), 'Explicit fresh local identity confirmation');
$check(count($GLOBALS['egmTest']['sent']) === $before, 'Identity confirmation sends no UART command');
$check((new EnOceanGatewayManager(101))->BeginWritePreparation(), 'Repeat deliberate dry run reads again');
$deliver('55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F');
(new EnOceanGatewayManager(101))->ProcessWritePreparation();
$deliver('5500050102DB00FFC2F7800A45');
(new EnOceanGatewayManager(101))->ProcessWritePreparation();
$p = json_decode($GLOBALS['egmTest']['instances'][101]['attributes']['WritePreparation'], true);
$check($p['readyForFutureAuthorization'] && !$p['hardwareWriteEnabled'] && $p['hardwareIdentity'] === 'bestätigt', 'All thirteen live preparation gates pass but B5 cannot write');
$expired = $p; $expired['evaluatedAt'] = gmdate('c', time() - 61);
$GLOBALS['egmTest']['instances'][101]['attributes']['WritePreparation'] = json_encode($expired);
$diagnostic = json_decode((new EnOceanGatewayManager(101))->GetDiagnosticSnapshot(), true);
$check(!json_decode($diagnostic['WritePreparation'], true)['readyForFutureAuthorization'], 'Displayed and diagnostic readiness automatically expires');
$GLOBALS['egmTest']['instances'][101]['attributes']['WritePreparation'] = json_encode($p);
$before = count($GLOBALS['egmTest']['sent']);
$blocked = json_decode((new EnOceanGatewayManager(101))->RequestHardwareWrite(), true);
$check(!$blocked['sent'] && count($GLOBALS['egmTest']['sent']) === $before, 'Public API reaches immutable barrier, no parent effect');
$GLOBALS['egmTest']['instances'][200]['properties']['IsolatedReadOnly'] = false;
(new ESP3TransportArbiter(200))->ForwardData(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => '5500050005DB07FFC2F700DC']));
$check(count($GLOBALS['egmTest']['sent']) === $before, 'Property manipulation cannot bypass native TX barrier');
$result = json_decode((new ESP3TransportArbiter(200))->ForwardData(json_encode(['DataID' => '{F5B497B9-0A7D-4F1A-A830-86B1B85A55D4}',
    'OwnerInstanceID' => 101, 'Token' => 'write-forgery', 'Operation' => 'CO_WR_IDBASE', 'FrameHex' => '5500050005DB07FFC2F700DC'])), true);
$check(!$result['accepted'] && count($GLOBALS['egmTest']['sent']) === $before, 'Maintenance interface rejects write without a send');
$oldSession = $state()['session'];
$GLOBALS['egmTest']['instances'][200]['active'] = false;
(new ESP3TransportArbiter(200))->ProcessTimeouts();
$GLOBALS['egmTest']['instances'][200]['active'] = true;
(new ESP3TransportArbiter(200))->ProcessTimeouts();
$check($state()['session'] !== $oldSession && !(new EnOceanGatewayManager(101))->ConfirmSavedBackupIdentity(), 'Reconnect invalidates cached identity and confirmation');
$before = count($GLOBALS['egmTest']['sent']);
$r = json_decode((new ESP3TransportArbiter(200))->ForwardData(json_encode(['DataID' => '{F5B497B9-0A7D-4F1A-A830-86B1B85A55D4}',
    'OwnerInstanceID' => 101, 'Token' => 'old-session-request', 'Operation' => 'CO_RD_IDBASE', 'FrameHex' => '5500010005700838', 'ExpectedSession' => $oldSession])), true);
$check(!$r['accepted'] && count($GLOBALS['egmTest']['sent']) === $before, 'Old-session requests rejected before UART communication');
$check((new EnOceanGatewayManager(101))->BeginWritePreparation(), 'Fresh read after observed reconnect permitted');
$GLOBALS['egmTest']['instances'][200]['active'] = false;
(new ESP3TransportArbiter(200))->ProcessTimeouts();
(new EnOceanGatewayManager(101))->ProcessWritePreparation();
$p = json_decode($GLOBALS['egmTest']['instances'][101]['attributes']['WritePreparation'], true);
$check(str_starts_with($p['state'], 'REJECTED') && !$p['hardwareWriteEnabled'], 'Connection loss cancels preparation without retry');
foreach ($GLOBALS['egmTest']['sent'] as [$id, $packet]) {
    if ($id === 200) {
        $data = \EnOceanGatewayManager\Protocol\ESP3Codec::parseFrame(hex2bin($packet['Buffer']))['data'];
        $check(in_array(ord($data[0]), [3, 8, 10, 15, 37, 39], true), 'All hardware-facing frames remain read-only');
    }
}
// B7.2: invalid input must be rejected before any parent envelope/lease/WAL.
$GLOBALS['egmTest']['instances'][101]['properties']['BaseIDSource']='manual';
$GLOBALS['egmTest']['instances'][101]['properties']['ManualBaseID']='FFC2F740';
$before=count($GLOBALS['egmTest']['sent']);
$check(!(new EnOceanGatewayManager(101))->BeginWriteTransaction(),'Manager rejects unaligned B7.1 target');
$check(!(new EnOceanGatewayManager(101))->ConfirmWriteTransaction('old','old','FFC2F740'),'Manager rejects unaligned confirmation');
$check(count($GLOBALS['egmTest']['sent'])===$before,'Invalid target/confirmation produce zero parent I/O');
$check(str_contains($GLOBALS['egmTest']['instances'][101]['attributes']['LastReadStatus'],'FFC2F700'),'Suggestion displayed without setting target');
$check($GLOBALS['egmTest']['instances'][101]['properties']['ManualBaseID']==='FFC2F740','Suggestion never automatically applied');
$journalBefore=@file_get_contents(IPS_GetKernelDir().'/egm-write-journal-200/transactions.ndjson');
foreach(['B6_BEGIN','B6_CONFIRM']as$operation){
    $r=json_decode((new ESP3TransportArbiter(200))->ForwardData(json_encode(['DataID'=>'{F5B497B9-0A7D-4F1A-A830-86B1B85A55D4}',
        'Operation'=>$operation,'OwnerInstanceID'=>101,'Target'=>'FFC2F740'])),true);
    $check(!$r['accepted']&&str_contains($r['reason'],'INVALID_BASE_ID_ALIGNMENT'),'Arbiter early target validation '.$operation);
}
$check(count($GLOBALS['egmTest']['sent'])===$before&&@file_get_contents(IPS_GetKernelDir().'/egm-write-journal-200/transactions.ndjson')===$journalBefore,'Arbiter invalid target causes zero I/O or WAL changes');
$backupBefore=$GLOBALS['egmTest']['instances'][101]['attributes']['SavedBaseID'];
$core=$state();$core['connected']=true;$core['correlationUnsafe']=false;
$GLOBALS['egmTest']['instances'][200]['attributes']['CoreState']=json_encode($core);
$proof=['at'=>time(),'session'=>$core['session'],'binding'=>$core['binding']];
$version=\EnOceanGatewayManager\Protocol\ESP3Codec::parseReadResponse('CO_RD_VERSION',hex2bin('55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F'));
$base=\EnOceanGatewayManager\Protocol\ESP3Codec::parseReadResponse('CO_RD_IDBASE',hex2bin('5500050102DB00FFC2F70009FA'));
$v=['state'=>'VERIFIED','owner'=>101,'parentID'=>200,'transactionID'=>'new-cache-proof','hardwareWriteBarrier'=>false,
    'reads'=>['CO_RD_VERSION'=>$proof+['values'=>$version],'CO_RD_IDBASE'=>$proof+['values'=>$base]]];
$GLOBALS['egmTest']['instances'][200]['attributes']['WriteTransactionState']=json_encode($v);
(new EnOceanGatewayManager(101))->ProcessTransactionView();
$check($GLOBALS['egmTest']['instances'][101]['attributes']['HardwareBaseID']==='FFC2F700'
    &&$GLOBALS['egmTest']['instances'][101]['attributes']['RemainingWriteCycles']==='9','Postverification cache uses actual read proofs');
$v['state']='RECOVERED_WITH_DIFFERENT_APPLIED_VALUE';$v['transactionID']='recovery-cache-proof';
$GLOBALS['egmTest']['instances'][200]['attributes']['WriteTransactionState']=json_encode($v);
(new EnOceanGatewayManager(101))->ProcessTransactionView();
$observations=json_decode($GLOBALS['egmTest']['instances'][101]['attributes']['ReadObservations'],true);
$check($observations['CO_RD_IDBASE']['proofSource']==='ARBITER_TRANSACTION_READ'
    &&$observations['CO_RD_IDBASE']['transactionID']==='recovery-cache-proof','Recovery cache stores proof provenance');
$check($GLOBALS['egmTest']['instances'][101]['attributes']['SavedBaseID']===$backupBefore,'Cache synchronization never changes backup');
$check(count($GLOBALS['egmTest']['sent'])===$before,'Cache synchronization requires no parent/hardware I/O');
$policy=json_decode(EGMA_GetWriteTransactionView(200),true);
$check($policy['hardwareWriteBarrier']&&!$policy['hardwareWriteEnabled'],'Actual compiled policy overrides forged attribute barrier');
$form=json_encode(json_decode((new EnOceanGatewayManager(101))->GetConfigurationForm(),true),JSON_UNESCAPED_UNICODE);
$check(str_contains($form,'Hardware Write gesperrt')&&str_contains($form,'ursprünglicher Intent bleibt FAIL'),'UI shows actual barrier and preserves historical FAIL');
$v['reads']['CO_RD_IDBASE']['session']='stale';$v['transactionID']='stale-proof';
$GLOBALS['egmTest']['instances'][200]['attributes']['WriteTransactionState']=json_encode($v);
$GLOBALS['egmTest']['instances'][101]['attributes']['HardwareBaseID']='UNCHANGED';
(new EnOceanGatewayManager(101))->ProcessTransactionView();
$check($GLOBALS['egmTest']['instances'][101]['attributes']['HardwareBaseID']==='UNCHANGED','Stale-session proof cannot refresh cache');
// B7.2.1: each callback/new manager and arbiter view uses the same authoritative model.
foreach (array_merge(array_keys(\EnOceanGatewayManager\Safety\TransactionStateModel::matrix()), ['', 'FUTURE_STATE']) as $txState) {
    $fixture=['state'=>$txState,'transactionID'=>'state-matrix','owner'=>101];
    $GLOBALS['egmTest']['instances'][200]['attributes']['WriteTransactionState']=json_encode($fixture);
    $GLOBALS['egmTest']['instances'][200]['buffers']['WriteTransactionRuntime']=json_encode($fixture);
    $manager=new EnOceanGatewayManager(101);
    $engine=new \EnOceanGatewayManager\Safety\TransactionalWrite($fixture);
    $classification=json_decode($manager->GetTransactionStateClassification(),true);
    $check($classification===$engine->classification(),'Manager reinitialization matches engine '.$txState);
    $check(json_decode(EGMA_GetWriteTransactionView(200),true)['stateClassification']===$classification,'Arbiter view matches manager '.$txState);
    $leaseCheck=new ReflectionMethod($manager,'writeLeaseActive');
    $check($leaseCheck->invoke($manager)===$engine->active(),'Actual manager lease check matches engine '.$txState);
    $form=json_decode($manager->GetConfigurationForm(),true);$buttons=[];$captions=[];
    $visit=static function(array $items)use(&$visit,&$buttons,&$captions):void{
        foreach($items as $item){if(!is_array($item))continue;if(isset($item['caption']))$captions[]=$item['caption'];
            if(($item['type']??'')==='Button')$buttons[$item['caption']]=$item;foreach($item as $value){if(is_array($value))$visit($value);}}
    };$visit([$form]);
    $check(in_array($classification['leaseActive']?'Write-Lease aktiv / neue Transaktion gesperrt'
        :($classification['completedRecovery']?'Hardwarezustand sicher bekannt; letzte Recovery abgeschlossen. Keine aktive Write-Lease.':'Keine aktive Write-Lease.'),$captions,true),'UI lease label matches '.$txState);
    if(!$classification['newTransactionStructurallyAllowed']){
        $check(!$buttons['Exklusive Transaktion vorbereiten (zunächst nur Reads)']['enabled'],'UI blocks new transaction '.$txState);
    }
}
foreach(['RECOVERED_WITH_DIFFERENT_APPLIED_VALUE','READ_ONLY_RESOLVED']as$txState){
    $fixture=['state'=>$txState,'transactionID'=>'completed-recovery','owner'=>101,
        'originalIntent'=>['state'=>'UNKNOWN_OUTCOME','target'=>'FFC2F740','result'=>'FAIL']];
    $GLOBALS['egmTest']['instances'][200]['attributes']['WriteTransactionState']=json_encode($fixture);
    $GLOBALS['egmTest']['instances'][200]['buffers']['WriteTransactionRuntime']=json_encode($fixture);
    $GLOBALS['egmTest']['instances'][101]['properties']['BaseIDSource']='manual';
    $GLOBALS['egmTest']['instances'][101]['properties']['ManualBaseID']='FFC2F780';
    $GLOBALS['egmTest']['instances'][101]['properties']['EnableReadActions']=true;
    $GLOBALS['egmTest']['instances'][101]['attributes']['PendingToken']='';
    $beforeSent=count($GLOBALS['egmTest']['sent']);
    $journalPath=IPS_GetKernelDir().'/egm-write-journal-200/transactions.ndjson';
    $hash=file_exists($journalPath)?hash_file('sha256',$journalPath):null;
    $accepted=(new EnOceanGatewayManager(101))->BeginWriteTransaction();
    $check(!$accepted,'Mock normal UART gate blocks; not false terminal lease '.$txState);
    $newPackets=array_slice($GLOBALS['egmTest']['sent'],$beforeSent);
    $check(count($newPackets)===1&&$newPackets[0][0]===101&&$newPackets[0][1]['Operation']==='B6_BEGIN','BEGIN passes old local lease gate into arbiter '.$txState);
    $check(!isset($newPackets[0][1]['Buffer']),'Control request is not a hardware frame '.$txState);
    $check((file_exists($journalPath)?hash_file('sha256',$journalPath):null)===$hash,'No mock WAL mutation on normal gate rejection '.$txState);
    $check(json_decode(EGMA_GetWriteTransactionView(200),true)['state']===$txState,'No artificial IDLE or history change '.$txState);
    $check(json_decode(EGMA_GetWriteTransactionView(200),true)['hardwareWriteBarrier'],'Actual compiled barrier remains closed '.$txState);
}
// Product actions operate only on local inventory, never on the hardware path.
$GLOBALS['egmTest']['instances'][200]['attributes']['WriteTransactionState']=json_encode(['state'=>'CANCELLED','owner'=>101]);
$GLOBALS['egmTest']['instances'][200]['buffers']['WriteTransactionRuntime']=json_encode(['state'=>'CANCELLED','owner'=>101]);
$manager=new EnOceanGatewayManager(101);$sentBefore=count($GLOBALS['egmTest']['sent']);
$check(!$manager->SetMasterBaseID('FFC2F700','manual',false),'Master requires explicit confirmation');
$check($manager->SetMasterBaseID('FFC2F700','manual',true),'Module local Master creation');
$check($manager->SetMasterBaseID('FFC2F780','manual',true),'Module local Master switch');
$check(!$manager->SetMasterBaseID('FFC2F740','manual',true),'Module central alignment blocks manual740');
$export=json_decode($manager->ExportProductInventory(),true);
$logical=$GLOBALS['egmTest']['instances'][101]['attributes']['LogicalGatewayID'];
$check($export['gateways'][$logical]['master']==='FFC2F780','Master retained on rejected input');
$check(\EnOceanGatewayManager\Product\GatewayInventory::history($export,$logical)['FFC2F700']['formerMaster'],'Old Master survives module API');
$check(count($GLOBALS['egmTest']['sent'])===$sentBefore,'All Master APIs cause zero parent communication');
$check($manager->StartMasterTransfer(),'Module opens local draft before hardware check');
$manager->ProcessProductWorkflow();
$check(json_decode($GLOBALS['egmTest']['instances'][101]['buffers']['ProductFlow'],true)['phase']==='DRAFT','Draft stable until conscious check');
$check(count($GLOBALS['egmTest']['sent'])===$sentBefore,'Draft never starts reads or writes automatically');
$GLOBALS['egmTest']['instances'][101]['attributes']['LogicalGatewayID']='';(new EnOceanGatewayManager(101))->Create();
$restored=json_decode((new EnOceanGatewayManager(101))->GetProductSnapshot(),true);
$check($restored['gateway']['logicalID']===$logical&&$restored['gateway']['master']==='FFC2F780','Reload restores exact identity and Master from durable registry');
$check($GLOBALS['egmTest']['instances'][101]['attributes']['LogicalGatewayID']===$logical,'Registry repairs empty volatile attribute, not the inventory');
$GLOBALS['egmTest']['instances'][101]['active']=false;$manager->RefreshConnectionStatus();
$check($GLOBALS['egmTest']['instances'][101]['status']===201,'Serial closed manager inactive');
$GLOBALS['egmTest']['instances'][101]['active']=true;$manager->MessageSink(1,200,IM_CHANGESTATUS,[102]);
$check($GLOBALS['egmTest']['instances'][101]['status']===102,'Late parent activation refreshes manager without ApplyChanges');
$GLOBALS['egmTest']['instances'][101]['active']=false;$manager->MessageSink(2,300,IM_CHANGESTATUS,[104]);
$check($GLOBALS['egmTest']['instances'][101]['status']===201,'Serial closes manager inactive without ApplyChanges');
$check(count($GLOBALS['egmTest']['sent'])===$sentBefore,'Lifecycle observer sends no hardware/control packets');
echo 'PASS: ' . $passed . ' module-lifecycle assertions' . PHP_EOL;
