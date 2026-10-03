<?php

declare(strict_types=1);

use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Protocol\ESP3StreamParser;
use EnOceanGatewayManager\Transport\ESP3TransportArbiterCore;

require_once __DIR__ . '/../libs/ESP3Codec.php';
require_once __DIR__ . '/../libs/ESP3StreamParser.php';
require_once __DIR__ . '/../libs/ESP3TransportArbiterCore.php';

$passed = 0;

$assertSame = static function (mixed $expected, mixed $actual, string $name) use (&$passed): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $name . ' failed: expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
    $passed++;
};

$assertTrue = static function (bool $condition, string $name) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($name . ' failed.');
    }
    $passed++;
};

$frame = static function (int $packetType, string $dataHex, string $optionalHex = ''): string {
    $data = ESP3Codec::fromHex($dataHex);
    $optional = ESP3Codec::fromHex($optionalHex);
    $header = pack('nCC', strlen($data), strlen($optional), $packetType);
    $payload = $data . $optional;

    return "\x55"
        . $header
        . chr(ESP3Codec::crc8($header))
        . $payload
        . chr(ESP3Codec::crc8($payload));
};

$actionsOfType = static function (array $actions, string $type): array {
    return array_values(array_filter(
        $actions,
        static fn (array $action): bool => ($action['type'] ?? '') === $type
    ));
};

$request = static function (int $owner, string $token, int $timeoutMs = 500): array {
    return [
        'operation' => ESP3TransportArbiterCore::OPERATION_READ_IDBASE,
        'ownerInstanceId' => $owner,
        'token' => $token,
        'frameHex' => ESP3Codec::READ_IDBASE_REQUEST_HEX,
        'timeoutMs' => $timeoutMs,
    ];
};

$radio = $frame(0x01, 'F6001122334430', '0300000000FF00');
$event = $frame(0x04, '04');
$response = ESP3Codec::fromHex('5500050102DB00FF9707000A2D');

// Streaming parser: fragmentation.
$parser = new ESP3StreamParser();
$fragmentEvents = [];
for ($offset = 0; $offset < strlen($response); $offset++) {
    $fragmentEvents = array_merge($fragmentEvents, $parser->feed($response[$offset]));
}
$assertSame(1, count($actionsOfType($fragmentEvents, 'frame')), 'Fragmented frame reconstructed once');
$assertSame('5500050102DB00FF9707000A2D', $fragmentEvents[0]['rawHex'], 'Fragmented frame bytes preserved');

// Several frames in a single ReceiveData call.
$parser = new ESP3StreamParser();
$concatenated = $parser->feed($radio . $event . $response);
$assertSame(3, count($actionsOfType($concatenated, 'frame')), 'Concatenated frames parsed');
$assertSame(0, $parser->bufferedBytes(), 'No bytes remain after concatenated frames');

// Garbage, CRC error and resynchronisation.
$corrupt = $radio;
$corrupt[strlen($corrupt) - 1] = chr(ord($corrupt[strlen($corrupt) - 1]) ^ 0x01);
$parser = new ESP3StreamParser();
$resync = $parser->feed("\x00\x99garbage" . $corrupt . $event);
$errors = $actionsOfType($resync, 'error');
$frames = $actionsOfType($resync, 'frame');
$assertTrue(count($errors) >= 2, 'Garbage and bad data CRC reported');
$assertSame(1, count($frames), 'Parser resynchronised after corrupt frame');
$assertSame(0x04, $frames[0]['packetType'], 'Event recovered after corrupt frame');

// Header CRC and malformed length.
$badHeader = $response;
$badHeader[5] = chr(ord($badHeader[5]) ^ 0x01);
$parser = new ESP3StreamParser();
$badHeaderEvents = $parser->feed($badHeader);
$assertSame('header_crc', $actionsOfType($badHeaderEvents, 'error')[0]['reason'], 'Header CRC error detected');

$lengthHeader = ESP3Codec::fromHex('00640002');
$tooLarge = "\x55" . $lengthHeader . chr(ESP3Codec::crc8($lengthHeader));
$parser = new ESP3StreamParser(32);
$lengthEvents = $parser->feed($tooLarge);
$assertSame('malformed_length', $actionsOfType($lengthEvents, 'error')[0]['reason'], 'Configured length limit enforced');

// Read-only allowlist blocks CO_WR_IDBASE and arbitrary frames.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$writeRejected = $core->enqueueMaintenance([
    'operation' => 'CO_WR_IDBASE',
    'ownerInstanceId' => 1,
    'token' => 'write-blocked-01',
    'frameHex' => '5500050005DB07FF9707002C',
    'timeoutMs' => 500,
], 1);
$assertSame(false, $writeRejected['accepted'], 'CO_WR_IDBASE operation rejected');
$assertSame('operation_not_allowlisted', $writeRejected['reason'], 'Write rejection reason');

$forgedReadRejected = $core->enqueueMaintenance([
    'operation' => ESP3TransportArbiterCore::OPERATION_READ_IDBASE,
    'ownerInstanceId' => 1,
    'token' => 'forged-read-01',
    'frameHex' => '550001000570093F',
    'timeoutMs' => 500,
], 2);
$assertSame(false, $forgedReadRejected['accepted'], 'Non-allowlisted read frame rejected');
$assertSame('frame_not_allowlisted', $forgedReadRejected['reason'], 'Forged frame rejection reason');

$disabledCore = new ESP3TransportArbiterCore();
$disabledCore->setConnected(true, 0);
$disabled = $disabledCore->enqueueMaintenance($request(2, 'maintenance-disabled'), 1);
$assertSame('maintenance_disabled', $disabled['reason'], 'Maintenance disabled by default');

$disconnectedCore = new ESP3TransportArbiterCore();
$disconnectedCore->setMaintenanceEnabled(true);
$disconnected = $disconnectedCore->enqueueMaintenance($request(3, 'transport-disconnected'), 1);
$assertSame('transport_disconnected', $disconnected['reason'], 'Disconnected transport rejects maintenance');

// Radio and Event packets remain transparent while maintenance waits.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$accepted = $core->enqueueMaintenance($request(10, 'manager-10-token'), 10);
$assertSame(true, $accepted['accepted'], 'Maintenance request accepted');
$assertSame(1, count($actionsOfType($accepted['actions'], 'send_parent')), 'Maintenance request dispatched once');
$radioActions = $core->receiveBytes($radio, 20);
$assertSame('radio', $actionsOfType($radioActions, 'send_native_child')[0]['classification'], 'Radio forwarded during wait');
$assertSame('manager-10-token', $core->state()['activeToken'], 'Radio did not consume maintenance response');
$eventActions = $core->receiveBytes($event, 30);
$assertSame('event', $actionsOfType($eventActions, 'send_native_child')[0]['classification'], 'Event forwarded during wait');
$assertSame('manager-10-token', $core->state()['activeToken'], 'Event did not consume maintenance response');

$responseActions = $core->receiveBytes($response, 40);
$maintenanceResults = $actionsOfType($responseActions, 'maintenance_result');
$assertSame(1, count($maintenanceResults), 'Expected response delivered to owner');
$assertSame(10, $maintenanceResults[0]['ownerInstanceId'], 'Expected response owner preserved');
$assertSame('RESPONSE', $maintenanceResults[0]['outcome'], 'Expected response outcome');
$assertSame(null, $core->state()['activeToken'], 'Lease released after expected response');

// Concatenated radio + expected response is routed in input order.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$core->enqueueMaintenance($request(11, 'manager-11-token'), 10);
$mixedActions = $core->receiveBytes($radio . $response, 20);
$assertSame('send_native_child', $mixedActions[0]['type'], 'Radio routed before following response');
$assertSame('maintenance_result', $mixedActions[1]['type'], 'Following response routed to manager');

// Unexpected response is diagnosed and forwarded to the native child.
$core = new ESP3TransportArbiterCore();
$core->setConnected(true, 0);
$unexpected = $core->receiveBytes($response, 10);
$assertSame(1, count($actionsOfType($unexpected, 'diagnostic')), 'Unexpected response diagnosed');
$assertSame('unexpected_response', $actionsOfType($unexpected, 'send_native_child')[0]['classification'], 'Unexpected response forwarded transparently');

// Native request debt blocks maintenance until the native response is routed.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$nativeRequest = $core->submitNativeBytes(ESP3Codec::buildReadIdBaseRequest(), 10);
$assertSame(1, $core->state()['nativeResponseDebt'], 'Native response debt recorded');
$assertSame(1, count($actionsOfType($nativeRequest, 'send_parent')), 'Native request forwarded');
$waiting = $core->enqueueMaintenance($request(12, 'manager-12-token'), 20);
$assertSame(0, count($actionsOfType($waiting['actions'], 'send_parent')), 'Maintenance waits for native response');
$nativeResponse = $core->receiveBytes($response, 30);
$assertSame('native_response', $actionsOfType($nativeResponse, 'send_native_child')[0]['classification'], 'Native response returned to native gateway');
$assertSame(1, count(array_filter(
    $actionsOfType($nativeResponse, 'send_parent'),
    static fn (array $action): bool => ($action['source'] ?? '') === 'maintenance'
)), 'Maintenance starts after native debt clears');

// Multiple managers retain FIFO ordering and request ownership.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$first = $core->enqueueMaintenance($request(21, 'manager-21-token'), 10);
$second = $core->enqueueMaintenance($request(22, 'manager-22-token'), 11);
$assertSame(true, $first['accepted'], 'First manager accepted');
$assertSame(true, $second['accepted'], 'Second manager queued');
$duplicate = $core->enqueueMaintenance($request(23, 'manager-22-token'), 12);
$assertSame('duplicate_token', $duplicate['reason'], 'Duplicate manager token rejected');
$firstDone = $core->receiveBytes($response, 20);
$assertSame(21, $actionsOfType($firstDone, 'maintenance_result')[0]['ownerInstanceId'], 'First manager completed first');
$nextDispatch = $actionsOfType($firstDone, 'send_parent');
$assertSame('manager-22-token', $nextDispatch[0]['token'], 'Second manager dispatched next');

// Timeout creates UNKNOWN_OUTCOME, releases the lock and quarantines late response.
$core = new ESP3TransportArbiterCore(defaultTimeoutMs: 100, lateResponseGuardMs: 50);
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$core->enqueueMaintenance($request(30, 'timeout-manager', 100), 10);
$core->enqueueMaintenance($request(31, 'timeout-queued', 100), 20);
$timeout = $core->tick(110);
$timeoutResults = $actionsOfType($timeout, 'maintenance_result');
$assertSame('UNKNOWN_OUTCOME', $timeoutResults[0]['outcome'], 'Timeout outcome is unknown');
$assertSame('CANCELLED_NOT_SENT', $timeoutResults[1]['outcome'], 'Queued request cancelled after correlation loss');
$assertSame(null, $core->state()['activeToken'], 'Lease released after timeout');
$assertSame(0, count($actionsOfType($timeout, 'send_parent')), 'Timeout never retries maintenance request');
$assertSame(true, $core->state()['correlationUnsafe'], 'Timeout fails closed until explicit transport recovery');
$late = $core->receiveBytes($response, 120);
$assertSame('late_response_quarantined', $actionsOfType($late, 'diagnostic')[0]['code'], 'Late response quarantined');
$assertSame(0, count($actionsOfType($late, 'send_native_child')), 'Late response not leaked to native gateway');
$blockedAfterTimeout = $core->enqueueMaintenance($request(32, 'blocked-after-timeout'), 130);
$assertSame('response_correlation_unsafe', $blockedAfterTimeout['reason'], 'New maintenance blocked after timeout');
$nativeBlocked = $core->submitNativeBytes(ESP3Codec::buildReadIdBaseRequest(), 130);
$assertSame('response_correlation_unsafe', $actionsOfType($nativeBlocked, 'native_rejected')[0]['reason'], 'Native request rejected while correlation is unsafe');
$core->tick(200);
$veryLate = $core->receiveBytes($response, 210);
$assertSame('response_quarantined_correlation_unsafe', $actionsOfType($veryLate, 'diagnostic')[0]['code'], 'Responses remain quarantined after guard expiry');
$core->setConnected(false, 220);
$core->setConnected(true, 230);
$assertSame(false, $core->state()['correlationUnsafe'], 'Explicit reconnect restores safe correlation state');

// Disconnect releases active and queued owners with different outcomes.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$core->enqueueMaintenance($request(31, 'disconnect-active'), 10);
$core->enqueueMaintenance($request(32, 'disconnect-queued'), 11);
$disconnect = $core->setConnected(false, 20);
$disconnectResults = $actionsOfType($disconnect, 'maintenance_result');
$assertSame('UNKNOWN_OUTCOME', $disconnectResults[0]['outcome'], 'In-flight disconnect outcome unknown');
$assertSame('CANCELLED_NOT_SENT', $disconnectResults[1]['outcome'], 'Queued disconnect request known unsent');
$assertSame(null, $core->state()['activeToken'], 'Disconnect releases active lease');
$core->setConnected(true, 30);
$assertSame(true, $core->state()['connected'], 'Reconnect returns core to connected state');

// Restart record preserves enough ownership data for fail-closed recovery.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$core->enqueueMaintenance($request(41, 'restart-active'), 10);
$core->enqueueMaintenance($request(42, 'restart-queued'), 11);
$restart = ESP3TransportArbiterCore::restartRecoveryActions($core->restartRecord());
$assertSame('UNKNOWN_OUTCOME', $restart[0]['outcome'], 'Restart marks active request unknown');
$assertSame('CANCELLED_NOT_SENT', $restart[1]['outcome'], 'Restart marks queued request unsent');

// Backpressure and queue accounting.
$core = new ESP3TransportArbiterCore(maximumQueueItems: 1, maximumQueueBytes: 64);
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$core->enqueueMaintenance($request(51, 'backpressure-active'), 10);
$queued = $core->enqueueMaintenance($request(52, 'backpressure-queued'), 11);
$rejected = $core->enqueueMaintenance($request(53, 'backpressure-reject'), 12);
$assertSame(true, $queued['accepted'], 'Single queued maintenance request accepted');
$assertSame(false, $rejected['accepted'], 'Queue item limit enforced');
$assertSame('backpressure', $rejected['reason'], 'Backpressure reason reported');

$byteLimitedCore = new ESP3TransportArbiterCore(maximumQueueItems: 4, maximumQueueBytes: 8);
$byteLimitedCore->setMaintenanceEnabled(true);
$byteLimitedCore->setConnected(true, 0);
$byteLimitedCore->enqueueMaintenance($request(54, 'byte-limit-active'), 10);
$byteRejected = $byteLimitedCore->submitNativeBytes(ESP3Codec::buildReadIdBaseRequest() . "\x00", 11);
$assertSame('backpressure', $actionsOfType($byteRejected, 'native_rejected')[0]['reason'], 'Queue byte limit enforced');

// Native bytes are queued during maintenance and flushed without retry afterward.
$core = new ESP3TransportArbiterCore();
$core->setMaintenanceEnabled(true);
$core->setConnected(true, 0);
$core->enqueueMaintenance($request(61, 'native-queue-active'), 10);
$nativeQueued = $core->submitNativeBytes(ESP3Codec::buildReadIdBaseRequest(), 20);
$assertSame(1, count($actionsOfType($nativeQueued, 'native_queued')), 'Native request queued while maintenance active');
$afterMaintenance = $core->receiveBytes($response, 30);
$assertSame(1, count(array_filter(
    $actionsOfType($afterMaintenance, 'send_parent'),
    static fn (array $action): bool => ($action['source'] ?? '') === 'native_queued'
)), 'Queued native request flushed once');
$assertSame(1, $core->state()['nativeResponseDebt'], 'Flushed native request owns next response');

echo 'PASS: ' . $passed . ' ESP3 stream/arbiter assertions' . PHP_EOL;
