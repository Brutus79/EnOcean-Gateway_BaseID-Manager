<?php

declare(strict_types=1);

use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\BaseIDPreflight;
use EnOceanGatewayManager\Transport\ESP3TransportArbiterCore;

require_once __DIR__ . '/../libs/ESP3Codec.php';
require_once __DIR__ . '/../libs/BaseIDPreflight.php';
require_once __DIR__ . '/../libs/ESP3StreamParser.php';
require_once __DIR__ . '/../libs/ESP3TransportArbiterCore.php';

$passed = 0;
$check = static function (bool $ok, string $name) use (&$passed): void {
    if (!$ok) { throw new RuntimeException($name); }
    $passed++;
};
$throws = static function (callable $call, string $name) use ($check): void {
    try { $call(); } catch (Throwable) { $check(true, $name); return; }
    $check(false, $name);
};
$frame = static function (string $data, string $optional = '', int $type = 2): string {
    $header = pack('nCC', strlen($data), strlen($optional), $type);
    return "\x55" . $header . chr(ESP3Codec::crc8($header)) . $data . $optional . chr(ESP3Codec::crc8($data . $optional));
};

$version = $frame(ESP3Codec::fromHex('0001020304050607081234567801020304') . str_pad('Gateway', 16, "\0"));
$decoded = ESP3Codec::parseReadResponse('CO_RD_VERSION', $version);
$check($decoded['eurid'] === '12345678', 'EURID offsets');
$check($decoded['applicationVersion'] === '1.2.3.4' && $decoded['apiVersion'] === '5.6.7.8', 'Version offsets');
$check($decoded['deviceVersionHex'] === '01020304' && $decoded['applicationDescription'] === 'Gateway', 'Device/description offsets');
$check($decoded['generation'] === null && $decoded['regulatoryRegion'] === null, 'No inferred generation/region');
$throws(fn () => ESP3Codec::parseReadResponse('CO_RD_VERSION', $frame("\0")), 'Short version rejected');
$throws(fn () => ESP3Codec::parseReadResponse('CO_RD_VERSION', $frame(str_repeat("\0", 17) . "\xFF" . str_repeat("\0", 15))), 'Non-ASCII description rejected');

$filters = ESP3Codec::parseReadResponse('CO_RD_FILTER', $frame(ESP3Codec::fromHex('00001234567801000000F6020000005503FFFFFFFF')));
$check(count($filters['filters']) === 4, 'All filter entries decoded');
$check(array_column($filters['filters'], 'criterion') === ['Sender ID', 'R-ORG', 'RSSI', 'Destination ID'], 'Filter criteria');
$check($filters['filters'][2]['valueHex'] === '00000055', 'RSSI raw preserved');
$check($filters['enabled'] === null && $filters['filters'][0]['action'] === null, 'Missing filter controls not guessed');
$check(ESP3Codec::parseReadResponse('CO_RD_FILTER', $frame("\0"))['filters'] === [], 'Empty filters');
$throws(fn () => ESP3Codec::parseReadResponse('CO_RD_FILTER', $frame("\0\x01")), 'Malformed filter length');
$check(ESP3Codec::parseReadResponse('CO_RD_FILTER', $frame(ESP3Codec::fromHex('00FF12345678')))['filters'][0]['criterion'] === 'UNKNOWN', 'Future criterion preserved');

foreach ([0 => 'OFF', 1 => 'ON', 2 => 'SELECTIVE', 255 => 'UNKNOWN'] as $mode => $name) {
    $check(ESP3Codec::parseReadResponse('CO_RD_REPEATER', $frame("\0" . chr($mode) . "\x02"))['mode'] === $name, 'Repeater mode ' . $mode);
}
$frequency = ESP3Codec::parseReadResponse('CO_GET_FREQUENCY_INFO', $frame("\0\x04\x01"));
$check($frequency['frequency'] === '928.350 MHz' && $frequency['protocol'] === 'ERP2', 'Non-European frequency');
$check(ESP3Codec::parseReadResponse('CO_GET_FREQUENCY_INFO', $frame("\0\xFF\xFF"))['frequency'] === null, 'Unknown frequency');
$check(ESP3Codec::parseReadResponse('CO_GET_STEPCODE', $frame("\0\xCA\x01"))['stepCodeHex'] === 'CA', 'Stepcode');

foreach (ESP3Codec::READ_COMMANDS as $operation => $code) {
    $request = ESP3Codec::buildReadRequest($operation);
    $check(ESP3Codec::parseFrame($request)['data'] === chr($code), 'Read frame ' . $operation);
    $check(ESP3Codec::parseReadResponse($operation, $frame("\x02"))['returnName'] === 'RET_NOT_SUPPORTED', 'Unsupported ' . $operation);
    $core = new ESP3TransportArbiterCore();
    $core->setMaintenanceEnabled(true); $core->setConnected(true, 0);
    $result = $core->enqueueMaintenance(['operation' => $operation, 'ownerInstanceId' => 1,
        'token' => 'test-' . $operation, 'frameHex' => ESP3Codec::toHex($request), 'timeoutMs' => 500], 1);
    $check($result['accepted'] === true, 'Read allowlisted ' . $operation);
}
$throws(fn () => ESP3Codec::buildReadRequest('CO_WR_REPEATER'), 'No write as read');
$core = new ESP3TransportArbiterCore(); $core->setMaintenanceEnabled(true); $core->setConnected(true, 0);
$check(!$core->enqueueMaintenance(['operation' => 'CO_RD_VERSION', 'ownerInstanceId' => 1, 'token' => 'forged-123', 'frameHex' => ESP3Codec::READ_IDBASE_REQUEST_HEX], 1)['accepted'], 'Operation/frame must match');

$read = ESP3Codec::parseReadIdBaseResponse($frame(ESP3Codec::fromHex('00FF800000'), "\x06"));
$preview = BaseIDPreflight::preview('FF970700', $read);
$check($preview['remaining'] === 6 && $preview['expectedRemaining'] === 5, 'Hardware counter preview reserve');
$check(!$preview['hardwareWriteEnabled'] && $preview['confirmationRequired'], 'Preview cannot authorize write');
foreach ([0, 1, 5] as $count) {
    $r = ESP3Codec::parseReadIdBaseResponse($frame(ESP3Codec::fromHex('00FF800000'), chr($count)));
    $throws(fn () => BaseIDPreflight::preview('FF970700', $r), 'Reserve rejected ' . $count);
}
$unlimited = ESP3Codec::parseReadIdBaseResponse($frame(ESP3Codec::fromHex('00FF800000'), "\xFF"));
$check(BaseIDPreflight::preview('FF970700', $unlimited)['expectedRemaining'] === 'UNLIMITED', 'FF unlimited');
$missing = ESP3Codec::parseReadIdBaseResponse($frame(ESP3Codec::fromHex('00FF800000')));
$throws(fn () => BaseIDPreflight::preview('FF970700', $missing), 'Unknown counter blocks');
$check(BaseIDPreflight::preview('FF800000', $read)['noChange'], 'No-op has no consumed cycle');
$throws(fn () => BaseIDPreflight::preview('0000A000', $read), 'Configured fake base rejected');
$throws(fn () => BaseIDPreflight::preview('FF970700', ['returnName' => 'RET_NOT_SUPPORTED']), 'Unsupported base blocked');

// Synthetic protocol responses; local hardware evidence is not a test dependency.
$evidence = json_decode(file_get_contents(__DIR__ . '/fixtures/esp3-read-responses.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($evidence['reads'] as $entry) {
    $check(ESP3Codec::toHex(ESP3Codec::buildReadRequest($entry['operation'])) === $entry['requestHex'], 'Offline request vector ' . $entry['operation']);
    $actual = ESP3Codec::parseReadResponse($entry['operation'], ESP3Codec::fromHex($entry['responseHex']));
    $check($actual['returnName'] === $entry['result'], 'Offline response vector ' . $entry['operation']);
}
$actualBase = ESP3Codec::parseReadIdBaseResponse(ESP3Codec::fromHex($evidence['reads'][1]['responseHex']));
$check($actualBase['baseIdRawHex'] === 'FFC2F780' && $actualBase['remainingWriteCycles'] === 10, 'Synthetic Base-ID/counter are decoded, not taken from configuration');
echo 'PASS: ' . $passed . ' gateway-feature assertions' . PHP_EOL;
