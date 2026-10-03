<?php

declare(strict_types=1);

use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\GatewayWritePreparation;

require_once __DIR__ . '/../libs/ESP3Codec.php';
require_once __DIR__ . '/../libs/BaseIDPreflight.php';
require_once __DIR__ . '/../libs/GatewayWritePreparation.php';

$passed = 0;
$check = static function (bool $ok, string $name) use (&$passed): void { if (!$ok) { throw new RuntimeException($name); } $passed++; };
$now = 1790985600;
$context = ['arbiterID' => 200, 'session' => 'session-one', 'binding' => 'chain-one',
    'realConnectionActive' => true, 'correlationSafeAndIdle' => true, 'noUnknownOutcome' => true, 'exclusiveUARTOwner' => true];
$common = ['readAt' => gmdate('c', $now), 'parentInstanceID' => '200', 'session' => 'session-one', 'binding' => 'chain-one', 'capability' => 'SUPPORTED_READ'];
$reads = ['CO_RD_IDBASE' => $common + ['values' => ESP3Codec::parseReadIdBaseResponse(hex2bin('5500050102DB00FFC2F7800A45'))],
    'CO_RD_VERSION' => $common + ['values' => ['eurid' => '01020304']]];
$backup = ['baseID' => 'FFC2F780', 'parentInstanceID' => '200', 'observedEURID' => '01020304', 'binding' => 'chain-one',
    // A years-old permanent backup is valid; its local identity confirmation must be fresh.
    'savedAt' => '2020-01-01T00:00:00+00:00',
    'identityConfirmation' => ['confirmedAt' => gmdate('c', $now), 'session' => 'session-one', 'backupBaseID' => 'FFC2F780', 'eurid' => '01020304']];
$good = GatewayWritePreparation::evaluate('FFC2F700', $context, $reads, $backup, $now);
$check(count($good['gates']) === 15 && $good['readyForFutureAuthorization'] && !$good['hardwareWriteEnabled'], 'All thirteen diagnostic gates can pass; both real-write permissions remain false');
$check($good['remaining'] === 10 && $good['expectedRemaining'] === 9 && $good['hardwareIdentity'] === 'bestätigt', 'Complete dry run values');
foreach (['realConnectionActive', 'correlationSafeAndIdle', 'noUnknownOutcome', 'exclusiveUARTOwner'] as $key) {
    $bad = $context; $bad[$key] = false;
    $r = GatewayWritePreparation::evaluate('FFC2F700', $bad, $reads, $backup, $now);
    $check(!$r['readyForFutureAuthorization'] && !$r['hardwareWriteEnabled'], 'Connection/correlation/UNKNOWN_OUTCOME/UART gate: ' . $key);
}
foreach (['CO_RD_IDBASE', 'CO_RD_VERSION'] as $operation) {
    foreach (['readAt' => gmdate('c', $now - 61), 'session' => 'old-session', 'binding' => 'other-chain', 'parentInstanceID' => '999', 'capability' => 'NOT_SUPPORTED'] as $key => $value) {
        $bad = $reads; $bad[$operation][$key] = $value;
        $r = GatewayWritePreparation::evaluate('FFC2F700', $context, $bad, $backup, $now);
        $check(!$r['readyForFutureAuthorization'] && !$r['hardwareWriteEnabled'], 'Stale/mismatching/unsupported hardware observation ' . $operation . '/' . $key);
        if ($operation === 'CO_RD_IDBASE' && $key === 'readAt') { $check(!$r['gates']['freshBaseID'] && !$r['gates']['freshWriteCycles'], 'Base ID and write counter freshness independently exposed'); }
    }
}
foreach ([[], array_replace($backup, ['observedEURID' => 'DEADBEEF']), array_replace($backup, ['parentInstanceID' => '999']),
    array_replace($backup, ['binding' => 'different-chain']), array_replace($backup, ['identityConfirmation' => []]),
    array_replace($backup, ['identityConfirmation' => array_replace($backup['identityConfirmation'], ['confirmedAt' => gmdate('c', $now - 61)])]),
    array_replace($backup, ['baseID' => 'FFC2F700'])] as $bad) {
    $r = GatewayWritePreparation::evaluate('FFC2F700', $context, $reads, $bad, $now);
    $check(!$r['readyForFutureAuthorization'] && !$r['hardwareWriteEnabled'], 'Missing/stale/replaced/foreign backup rejected');
}
$r = GatewayWritePreparation::evaluate('0000A000', $context, $reads, $backup, $now);
$check(!$r['gates']['validTarget'] && !$r['hardwareWriteEnabled'], 'Invalid target rejected');
$r = GatewayWritePreparation::evaluate('FFC2F780', $context, $reads, $backup, $now);
$check($r['state'] === 'NO_OP' && !$r['gates']['targetChangesBaseID'] && $r['expectedRemaining'] === 10 && !$r['hardwareWriteEnabled'], 'No-op cannot enable write or consume counter');
foreach (['00', '05', null, 'FF'] as $counter) {
    $bad = $reads; $bad['CO_RD_IDBASE']['values']['remainingWriteCyclesRawHex'] = $counter;
    $r = GatewayWritePreparation::evaluate('FFC2F700', $context, $bad, $backup, $now);
    $check($counter === 'FF' ? ($r['readyForFutureAuthorization'] && $r['expectedRemaining'] === 'UNLIMITED') : !$r['readyForFutureAuthorization'], 'Zero/reserve/missing/unlimited counter ' . ($counter ?? 'null'));
}
$bad = $context; $bad['session'] = 'after-reconnect';
$r = GatewayWritePreparation::evaluate('FFC2F700', $bad, $reads, $backup, $now);
$check(!$r['readyForFutureAuthorization'], 'Actual reconnect invalidates every old measurement and confirmation');
$bad = $context; $bad['explicitHardwareAuthorization'] = true; $bad['immediateConcreteUserConfirmation'] = true;
$r = GatewayWritePreparation::evaluate('FFC2F700', $bad, $reads, $backup, $now);
$check(!$r['gates']['explicitHardwareAuthorization'] && !$r['gates']['immediateConcreteUserConfirmation'] && !$r['hardwareWriteEnabled'], 'Caller cannot inject a hardware write permission in B5');
$check(!GatewayWritePreparation::dispatchBlocked()['sent'], 'Final B5 dispatch performs no send');
$bytes = ESP3Codec::buildWriteIdBaseRequest('FFC2F700');
$check(strtoupper(bin2hex($bytes)) === '5500050005DB07FFC2F700DC', 'Independent complete golden CO_WR_IDBASE frame');
$decoded = ESP3Codec::parseFrame($bytes);
$check(strlen($bytes) === 12 && $decoded['packetType'] === 5 && bin2hex($decoded['data']) === '07ffc2f700', 'Packet type, command, network byte order and length');
// Independent CRC implementation, not the production codec itself.
$crc = static function (string $data): int { $r = 0; foreach (str_split($data) as $b) { $r ^= ord($b); for ($i = 0; $i < 8; $i++) { $r = (($r << 1) ^ (($r & 128) ? 7 : 0)) & 255; } } return $r; };
$check($crc(substr($bytes, 1, 4)) === ord($bytes[5]) && $crc(substr($bytes, 6, 5)) === ord($bytes[11]), 'Header and data CRC independently checked');
$check(GatewayWritePreparation::HARDWARE_WRITE_ENABLED === false, 'Immutable B5 policy');
echo 'PASS: ' . $passed . ' B5 preparation assertions' . PHP_EOL;
