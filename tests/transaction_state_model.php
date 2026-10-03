<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/TransactionalWrite.php';
require_once __DIR__ . '/../libs/WriteJournal.php';
require_once __DIR__ . '/../libs/ESP3Codec.php';
use EnOceanGatewayManager\Safety\TransactionStateModel;
use EnOceanGatewayManager\Safety\TransactionalWrite;
use EnOceanGatewayManager\Safety\WriteJournal;
use EnOceanGatewayManager\Safety\DurableWriteJournal;
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) { throw new RuntimeException($label); } $passed++;
};
$memory = static fn () => new class implements WriteJournal {
    public array $rows = [];
    public function append(array $r): void { $this->rows[] = $r; }
    public function records(): array { return $this->rows; }
};
// Independent test oracle, never a production terminal-state definition.
$terminal = ['IDLE','CANCELLED','NO_OP','VERIFIED','RECOVERY_NOT_APPLIED','READ_ONLY_RESOLVED','RECOVERED_WITH_DIFFERENT_APPLIED_VALUE'];
$recovery = ['UNKNOWN_OUTCOME','ADMIN_RECOVERY_RECONNECT','ADMIN_RECOVERY_READS'];
$matrix = TransactionStateModel::matrix();
$check(count($matrix) === 20, 'All 20 current transaction states covered');
foreach ($matrix as $state => $c) {
    $expected = in_array($state, $terminal, true);
    $t = new TransactionalWrite(['state' => $state]);
    $check($c['known'] && $c['terminal'] === $expected, 'Terminal oracle ' . $state);
    $check($c['active'] === !$expected && $c['leaseActive'] === !$expected, 'Lease oracle ' . $state);
    $check($c['recoveryRequired'] === in_array($state, $recovery, true), 'Recovery oracle ' . $state);
    $check($c['newTransactionStructurallyAllowed'] === $expected, 'Structural begin oracle ' . $state);
    $check($c['unresolvedUnknownOutcome'] === ($state === 'UNKNOWN_OUTCOME'), 'Unresolved outcome ' . $state);
    $check($t->classification() === $c && $t->active() === $c['leaseActive'], 'Engine consistency ' . $state);
    $check($t->view()['stateClassification'] === $c, 'Read-only engine view ' . $state);
    $j = $memory();
    try { $t->begin(101, 'FFC2F780', [], [], time(), $j); $check(false, 'Missing gates denied'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), $expected ? 'Lease denied' : 'Exclusive write lease busy'), 'Normal gates still required ' . $state); }
    $check($j->records() === [], 'No intent without gates ' . $state);
    if ($expected) {
        $snapshot = ['state'=>$state,'transactionID'=>'historical-operation','sendAttempts'=>1,
            'originalIntent'=>['state'=>'UNKNOWN_OUTCOME','permanentFailure'=>true,'target'=>'FFC2F740'],
            'recoveryResult'=>['originalResult'=>'FAIL / UNKNOWN_OUTCOME','actuallyObserved'=>'FFC2F700']];
        $j->append($snapshot); $before = $j->records();
        $rebuilt = TransactionalWrite::restart($j);
        $check(!$rebuilt->active() && $rebuilt->classification()['newTransactionStructurallyAllowed'], 'Terminal reconstruction ' . $state);
        $check($j->records() === $before && $rebuilt->snapshot()['originalIntent'] === $snapshot['originalIntent'], 'History unchanged ' . $state);
        $check((new TransactionalWrite($rebuilt->snapshot()))->classification() === $c, 'New engine object ' . $state);
    }
}
foreach (['','FUTURE_STATE','idle',' IDLE','UNKNOWN'] as $state) {
    $c = TransactionStateModel::classify($state);
    $check(!$c['known'] && !$c['terminal'] && $c['active'] && $c['leaseActive'] && $c['recoveryRequired']
        && $c['unresolvedUnknownOutcome'] && $c['permanentlyBlocked'] && !$c['newTransactionStructurallyAllowed'], 'Unknown fails closed ' . $state);
    $j=$memory();$j->append(['state'=>$state]);$before=$j->records();
    $t=TransactionalWrite::restart($j);
    $check($t->active() && $t->snapshot()['state']===$state && $j->records()===$before, 'Unknown restart never creates IDLE/CANCELLED');
    $t->cancel('simulation', $j);
    $check($t->active() && $t->classification()['unresolvedUnknownOutcome'] && $t->classification()['permanentlyBlocked'], 'Unknown cancellation remains blocking');
}
$check(TransactionStateModel::classify('UNKNOWN_OUTCOME',true)['permanentlyBlocked'], 'Permanent failure metadata retained');
$check(!TransactionStateModel::classify('UNKNOWN_OUTCOME')['newTransactionStructurallyAllowed'], 'Unknown never permits new write');
foreach ([[], ['state'=>null], ['state'=>42]] as $missing) {
    $j=$memory();$j->append($missing);$before=$j->records();
    $t=TransactionalWrite::restart($j);
    $check($t->active() && !$t->classification()['known'] && !$t->classification()['newTransactionStructurallyAllowed'], 'Missing/corrupt reconstructed state cannot become fresh IDLE');
    $check($j->records()===$before, 'Malformed-state reconstruction preserves journal');
}
foreach (['RECOVERED_WITH_DIFFERENT_APPLIED_VALUE','READ_ONLY_RESOLVED'] as $state) {
    $j=$memory();$historical=['state'=>$state,'transactionID'=>'old-recovery','authorized'=>false,
        'originalIntent'=>['state'=>'UNKNOWN_OUTCOME','target'=>'FFC2F740','result'=>'FAIL']];$j->append($historical);
    $t=TransactionalWrite::restart($j);$now=time();
    $context=['arbiterID'=>200,'binding'=>'mock-binding','session'=>'new-mock-session','ownerRevision'=>'new-revision',
        'realConnectionActive'=>true,'correlationSafeAndIdle'=>true,'noUnknownOutcome'=>true,'exclusiveUARTOwner'=>true];
    $backup=['baseID'=>'FFC2F780','observedEURID'=>'01020304','parentInstanceID'=>'200','binding'=>'mock-binding',
        'identityConfirmation'=>['confirmedAt'=>gmdate('c',$now),'session'=>'new-mock-session','backupBaseID'=>'FFC2F780','eurid'=>'01020304']];
    $stale=$backup;$stale['identityConfirmation']['confirmedAt']=gmdate('c',$now-61);
    try{$t->begin(101,'FFC2F780',$context,$stale,$now,$j);$check(false,'Stale identity denied');}
    catch(RuntimeException $e){$check(str_contains($e->getMessage(),'expired'),'60-second identity gate retained '.$state);}
    $check($j->records()===[$historical],'Rejected stale proof has no intent '.$state);
    $t->begin(101,'FFC2F780',$context,$backup,$now,$j);
    $s=$t->snapshot();
    $check($s['state']==='PREFLIGHT'&&$s['transactionID']!=='old-recovery'&&$s['session']==='new-mock-session','Independent mock transaction after recovery '.$state);
    $check(!$s['authorized']&&$s['token']===''&&$s['sendAttempts']===0&&$s['readNext']==='CO_RD_VERSION','No confirmation or hardware effect reused '.$state);
    $check($j->records()[0]===$historical&&count($j->records())===3,'Append-only mock intent preserves historical FAIL '.$state);
}
// Real durable file reconstruction, but strictly in a disposable mock/test directory.
$dir=sys_get_temp_dir().'/egm-state-model-'.bin2hex(random_bytes(8));
$disk=new DurableWriteJournal($dir);
$disk->append(['state'=>'RECOVERED_WITH_DIFFERENT_APPLIED_VALUE','transactionID'=>'recovered','originalIntent'=>['state'=>'UNKNOWN_OUTCOME','result'=>'FAIL']]);
$hash=hash_file('sha256',$dir.'/transactions.ndjson');
$t=TransactionalWrite::restart(new DurableWriteJournal($dir));
$check(!$t->active() && $t->snapshot()['state']==='RECOVERED_WITH_DIFFERENT_APPLIED_VALUE', 'Disk reconstruction frees completed recovery lease');
$check(hash_file('sha256',$dir.'/transactions.ndjson')===$hash, 'Disk reconstruction does not rewrite/append history');
$disk->append(['state'=>'UNKNOWN_OUTCOME','transactionID'=>'unresolved','sendAttempts'=>1,'permanentFailure'=>true]);
$t=TransactionalWrite::restart(new DurableWriteJournal($dir));
$check($t->active() && $t->classification()['unresolvedUnknownOutcome'] && !$t->classification()['newTransactionStructurallyAllowed'], 'Unresolved disk restart remains blocked');
echo 'PASS: ' . $passed . ' B7.2.1 state-model assertions' . PHP_EOL;
