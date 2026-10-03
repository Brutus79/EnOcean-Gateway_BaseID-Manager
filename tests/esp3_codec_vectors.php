<?php

declare(strict_types=1);

use EnOceanGatewayManager\Protocol\ESP3Codec;

require_once __DIR__ . '/../libs/ESP3Codec.php';

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

$assertThrows = static function (callable $operation, string $name) use (&$passed): void {
    try {
        $operation();
    } catch (Throwable) {
        $passed++;
        return;
    }

    throw new RuntimeException($name . ' failed: expected an exception.');
};

$assertSame(0x00, ESP3Codec::crc8(''), 'Empty CRC');
$assertSame(0x70, ESP3Codec::crc8(ESP3Codec::fromHex('00 01 00 05')), 'CO_RD_IDBASE header CRC');
$assertSame(0x38, ESP3Codec::crc8(ESP3Codec::fromHex('08')), 'CO_RD_IDBASE data CRC');
$assertSame(0xCE, ESP3Codec::crc8(ESP3Codec::fromHex('00 05 00 02')), 'Official response header CRC');
$assertSame(0xDA, ESP3Codec::crc8(ESP3Codec::fromHex('00 FF 80 00 00')), 'Official response data CRC');
$assertSame(
    ESP3Codec::READ_IDBASE_REQUEST_HEX,
    ESP3Codec::toHex(ESP3Codec::buildReadIdBaseRequest()),
    'Complete CO_RD_IDBASE request'
);

$assertSame(0xDB, ESP3Codec::crc8(ESP3Codec::fromHex('00 05 00 05')), 'CO_WR_IDBASE header CRC');
$assertSame(0x2C, ESP3Codec::crc8(ESP3Codec::fromHex('07 FF 97 07 00')), 'Recovery Base-ID data CRC');
$assertSame('FF970700', ESP3Codec::normalizeWritableBaseId('0xff970700'), 'Normalize writable Base ID');
$assertSame(
    '5500050005DB07FF800000F3',
    ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest('FF800000')),
    'CO_WR_IDBASE minimum frame'
);
$assertSame(
    '5500050005DB07FFFFFF808D',
    ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest('FFFFFF80')),
    'CO_WR_IDBASE maximum frame'
);
$assertSame(
    '5500050005DB07FF9707002C',
    ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest('FF970700')),
    'CO_WR_IDBASE critical recovery frame'
);
$assertSame(
    '5500050005DB07FF970780A5',
    ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest('FF970780')),
    'CO_WR_IDBASE adjacent offline example'
);

$assertThrows(
    static fn (): string => ESP3Codec::buildWriteIdBaseRequest('FF7FFF80'),
    'Below-range Base ID rejection'
);
$assertThrows(
    static fn (): string => ESP3Codec::buildWriteIdBaseRequest('FFFFFFFF'),
    'Above-range Base ID rejection'
);
$assertThrows(static fn (): string => ESP3Codec::normalizeWritableBaseId('FF970701'), 'Conservative policy rejects unaligned address');
$assertThrows(
    static fn (): string => ESP3Codec::buildWriteIdBaseRequest('FF9707'),
    'Malformed Base ID rejection'
);

$officialResponse = ESP3Codec::parseReadIdBaseResponse(
    ESP3Codec::fromHex('55 00 05 00 02 CE 00 FF 80 00 00 DA')
);
$assertSame('FF800000', $officialResponse['baseIdRawHex'], 'Official response Base ID');
$assertSame('unknown', $officialResponse['remainingWriteCyclesMode'], 'Omitted counter is unknown');

$responseWithCounter = ESP3Codec::parseReadIdBaseResponse(
    ESP3Codec::fromHex('55 00 05 01 02 DB 00 FF 80 00 00 0A 3E')
);
$assertSame('FF800000', $responseWithCounter['baseIdRawHex'], 'Counter response Base ID');
$assertSame('0A', $responseWithCounter['remainingWriteCyclesRawHex'], 'Counter raw byte');
$assertSame('finite', $responseWithCounter['remainingWriteCyclesMode'], 'Finite counter mode');
$assertSame(10, $responseWithCounter['remainingWriteCycles'], 'Finite counter value');

$responseWithUnlimitedCounter = ESP3Codec::parseReadIdBaseResponse(
    ESP3Codec::fromHex('55 00 05 01 02 DB 00 FF 80 00 00 FF FB')
);
$assertSame('unlimited', $responseWithUnlimitedCounter['remainingWriteCyclesMode'], 'Unlimited counter mode');
$assertSame(null, $responseWithUnlimitedCounter['remainingWriteCycles'], 'Unlimited is not numeric 255');

$byteOrderProbe = ESP3Codec::parseReadIdBaseResponse(
    ESP3Codec::fromHex('55 00 05 01 02 DB 00 00 00 A0 00 0A 7E')
);
$assertSame('0000A000', $byteOrderProbe['baseIdRawHex'], 'Base-ID bytes stay in wire order');

$unsupported = ESP3Codec::parseReadIdBaseResponse(
    ESP3Codec::fromHex('55 00 01 00 02 65 02 0E')
);
$assertSame(0x02, $unsupported['returnCode'], 'Unsupported return code');
$assertSame(null, $unsupported['baseIdRawHex'], 'Error response has no Base ID');

$writeOk = ESP3Codec::parseWriteIdBaseResponse(ESP3Codec::fromHex('55 00 01 00 02 65 00 00'));
$assertSame('RET_OK', $writeOk['returnName'], 'Write RET_OK name');
$assertSame(true, $writeOk['accepted'], 'Write RET_OK accepted');

$writeUnsupported = ESP3Codec::parseWriteIdBaseResponse(ESP3Codec::fromHex('55 00 01 00 02 65 02 0E'));
$assertSame('RET_NOT_SUPPORTED', $writeUnsupported['returnName'], 'Write unsupported name');
$assertSame(false, $writeUnsupported['accepted'], 'Write unsupported rejected');

$writeOutOfRange = ESP3Codec::parseWriteIdBaseResponse(ESP3Codec::fromHex('55 00 01 00 02 65 90 F9'));
$assertSame('BASEID_OUT_OF_RANGE', $writeOutOfRange['returnName'], 'Write out-of-range name');
$assertSame(false, $writeOutOfRange['accepted'], 'Write out-of-range rejected');

$writeMaxReached = ESP3Codec::parseWriteIdBaseResponse(ESP3Codec::fromHex('55 00 01 00 02 65 91 FE'));
$assertSame('BASEID_MAX_REACHED', $writeMaxReached['returnName'], 'Write max-reached name');
$assertSame(false, $writeMaxReached['accepted'], 'Write max-reached rejected');

$assertThrows(
    static fn (): array => ESP3Codec::parseReadIdBaseResponse(
        ESP3Codec::fromHex('55 00 05 00 02 CE 00 FF 80 00 00 DB')
    ),
    'Corrupt data CRC rejection'
);

$assertThrows(
    static fn (): array => ESP3Codec::parseReadIdBaseResponse(
        ESP3Codec::fromHex('55 00 05 00 02 CE 00 FF 80 00 DA')
    ),
    'Header length mismatch rejection'
);

echo 'PASS: ' . $passed . ' ESP3 offline assertions' . PHP_EOL;
