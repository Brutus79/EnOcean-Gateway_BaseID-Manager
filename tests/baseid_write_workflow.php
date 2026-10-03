<?php

declare(strict_types=1);

use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\BaseIDWriteWorkflow;

require_once __DIR__ . '/../libs/ESP3Codec.php';
require_once __DIR__ . '/../libs/BaseIDPreflight.php';
require_once __DIR__ . '/../libs/BaseIDWriteWorkflow.php';
$passed = 0;
$check = static function (bool $ok, string $name) use (&$passed): void { if (!$ok) { throw new RuntimeException($name); } $passed++; };
$throws = static function (callable $call, string $name) use ($check): void {
    try { $call(); } catch (Throwable) { $check(true, $name); return; } $check(false, $name);
};
$frame = static function (string $hex, string $optional = ''): string {
    $data = ESP3Codec::fromHex($hex); $opt = ESP3Codec::fromHex($optional);
    $header = pack('nCC', strlen($data), strlen($opt), 2);
    return "\x55" . $header . chr(ESP3Codec::crc8($header)) . $data . $opt . chr(ESP3Codec::crc8($data . $opt));
};
$old = $frame('00FFC2F780', '0A'); $new = $frame('00FF970700', '09');
$workflow = new BaseIDWriteWorkflow('FF970700');
$check($workflow->previewReadEffect()['operation'] === 'CO_RD_IDBASE', 'Preview reads first');
$preview = $workflow->receivePreviewRead($old, 100);
$check($preview['currentBaseID'] === 'FFC2F780' && $preview['requestedBaseID'] === 'FF970700'
    && $preview['remaining'] === 10 && $preview['expectedRemaining'] === 9, 'All required confirmation fields');
$check($workflow->confirm($preview['confirmationToken'], 200)['operation'] === 'CO_RD_IDBASE', 'Mandatory fresh read after confirmation');
$write = $workflow->receivePrewriteRead($old);
$check($write['operation'] === 'CO_WR_IDBASE' && !$write['retryAllowed'] && $write['requiresExclusiveArbiterLease'], 'Single offline write effect requires arbiter');
$throws(fn () => $workflow->receivePrewriteRead($old), 'Duplicate write prevented');
$throws(fn () => $workflow->confirm($preview['confirmationToken'], 201), 'Confirmation consumed once');
$check($workflow->receiveWriteResponse($frame('00'))['operation'] === 'CO_RD_IDBASE', 'Success triggers verification read');
$check($workflow->receiveVerifyRead($new)['verified'] && $workflow->state() === 'VERIFIED', 'Base and hardware counter verified');

$prepare = static function () use ($old): array {
    $w = new BaseIDWriteWorkflow('FF970700'); $w->previewReadEffect(); return [$w, $w->receivePreviewRead($old, 0)];
};
[$w, $p] = $prepare();
$throws(fn () => $w->confirm('incorrect', 1), 'Wrong confirmation blocked');
[$w, $p] = $prepare();
$throws(fn () => $w->confirm($p['confirmationToken'], 60000), 'Expired confirmation blocked');
foreach ([$frame('00FFC2F780', '09'), $frame('00FFC2F700', '0A')] as $changed) {
    [$w, $p] = $prepare(); $w->confirm($p['confirmationToken'], 1);
    $throws(fn () => $w->receivePrewriteRead($changed), 'Changed hardware demands new confirmation');
    $check($w->state() === 'RECONFIRM_REQUIRED', 'Reconfirmation state');
}
foreach (['02' => 'RET_NOT_SUPPORTED', '90' => 'BASEID_OUT_OF_RANGE', '91' => 'BASEID_MAX_REACHED'] as $code => $name) {
    [$w, $p] = $prepare(); $w->confirm($p['confirmationToken'], 1); $w->receivePrewriteRead($old);
    $r = $w->receiveWriteResponse($frame((string) $code));
    $check($r['returnName'] === $name && isset($r['message']) && $w->state() === 'DEVICE_REJECTED', 'Explained device error ' . $name);
    $throws(fn () => $w->receivePrewriteRead($old), 'No retry after ' . $name);
}
[$w, $p] = $prepare(); $w->confirm($p['confirmationToken'], 1); $w->receivePrewriteRead($old); $w->transportLost();
$check($w->state() === 'UNKNOWN_OUTCOME', 'Lost write response never treated as failure safe to retry');
$throws(fn () => $w->receivePrewriteRead($old), 'No retry after unknown outcome');
[$w, $p] = $prepare(); $w->confirm($p['confirmationToken'], 1); $w->receivePrewriteRead($old); $w->receiveWriteResponse($frame('00'));
$check(!$w->receiveVerifyRead($frame('00FF970700', '0A'))['verified'], 'Counter mismatch fails verification');
[$w, $p] = $prepare(); $w->confirm($p['confirmationToken'], 1); $w->receivePrewriteRead($old); $w->receiveWriteResponse($frame('00'));
$check(!$w->receiveVerifyRead($frame('00FFC2F780', '09'))['verified'], 'Base mismatch fails verification');
$noop = new BaseIDWriteWorkflow('FFC2F780'); $noop->previewReadEffect();
$check($noop->receivePreviewRead($old, 0)['noChange'] && $noop->state() === 'NO_CHANGE', 'Unchanged base consumes no write');
$throws(fn () => new BaseIDWriteWorkflow('0000A000'), 'Invalid ID cannot create workflow');
$blocked = new BaseIDWriteWorkflow('FF970700'); $blocked->previewReadEffect();
$throws(fn () => $blocked->receivePreviewRead($frame('00FFC2F780', '05'), 0), 'Minimum five protected');
$unlimited = new BaseIDWriteWorkflow('FF970700'); $unlimited->previewReadEffect();
$p = $unlimited->receivePreviewRead($frame('00FFC2F780', 'FF'), 0);
$unlimited->confirm($p['confirmationToken'], 1); $unlimited->receivePrewriteRead($frame('00FFC2F780', 'FF')); $unlimited->receiveWriteResponse($frame('00'));
$check($unlimited->receiveVerifyRead($frame('00FF970700', 'FF'))['verified'], 'Unlimited counter never locally decremented');
echo 'PASS: ' . $passed . ' offline write-workflow assertions' . PHP_EOL;
