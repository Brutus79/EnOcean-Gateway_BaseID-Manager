<?php

declare(strict_types=1);

namespace EnOceanGatewayManager\Protocol;

use LengthException;
use RuntimeException;
use ValueError;

/**
 * Pure offline ESP3 helpers for Packages B2 and B3.
 *
 * This class has no IP-Symcon, serial-port, file, network, or process access.
 * It can only calculate CRCs, build read/write frames, validate Base IDs, and
 * parse byte strings supplied by the caller. It cannot transmit any frame.
 */
final class ESP3Codec
{
    public const READ_IDBASE_REQUEST_HEX = '5500010005700838';
    public const WRITE_IDBASE_MIN_HEX = 'FF800000';
    public const WRITE_IDBASE_MAX_HEX = 'FFFFFF80';
    public const READ_COMMANDS = [
        'CO_RD_VERSION' => 0x03,
        'CO_RD_IDBASE' => 0x08,
        'CO_RD_REPEATER' => 0x0A,
        'CO_RD_FILTER' => 0x0F,
        'CO_GET_FREQUENCY_INFO' => 0x25,
        'CO_GET_STEPCODE' => 0x27,
    ];

    public static function buildReadRequest(string $operation): string
    {
        if (!isset(self::READ_COMMANDS[$operation])) {
            throw new ValueError('Read command is not allowlisted.');
        }
        $header = self::fromHex('00010005');
        $data = chr(self::READ_COMMANDS[$operation]);
        return chr(0x55) . $header . chr(self::crc8($header)) . $data . chr(self::crc8($data));
    }

    public static function parseReadResponse(string $operation, string $frame): array
    {
        self::buildReadRequest($operation);
        if ($operation === 'CO_RD_IDBASE') {
            return self::parseReadIdBaseResponse($frame);
        }
        $parsed = self::parseFrame($frame);
        $data = $parsed['data'];
        if ($parsed['packetType'] !== 0x02 || $data === '') {
            throw new RuntimeException('Expected RESPONSE with return code.');
        }
        $code = ord($data[0]);
        $result = [
            'returnCode' => $code,
            'returnName' => match ($code) {
                0 => 'RET_OK', 1 => 'RET_ERROR', 2 => 'RET_NOT_SUPPORTED',
                3 => 'RET_WRONG_PARAM', 4 => 'RET_OPERATION_DENIED',
                5 => 'RET_LOCK_SET', 6 => 'RET_BUFFER_TO_SMALL', 7 => 'RET_NO_FREE_BUFFER',
                default => sprintf('UNKNOWN_0x%02X', $code),
            },
            'optionalDataHex' => self::toHex($parsed['optionalData']),
        ];
        if ($code !== 0) {
            if (strlen($data) !== 1) {
                throw new LengthException('Unexpected error response payload.');
            }
            return $result;
        }
        $expected = match ($operation) {
            'CO_RD_VERSION' => 33,
            'CO_RD_REPEATER', 'CO_GET_FREQUENCY_INFO', 'CO_GET_STEPCODE' => 3,
            default => null,
        };
        if ($expected !== null && strlen($data) !== $expected) {
            throw new LengthException('Unexpected successful response length.');
        }
        if ($operation === 'CO_RD_VERSION') {
            $version = static fn (string $bytes): string => implode('.', array_values(unpack('C*', $bytes)));
            $description = explode("\0", substr($data, 17, 16), 2)[0];
            if (preg_match('/[^\x20-\x7E]/', $description)) {
                throw new ValueError('Application description is not printable ASCII.');
            }
            $result += [
                'applicationVersion' => $version(substr($data, 1, 4)),
                'apiVersion' => $version(substr($data, 5, 4)),
                'eurid' => self::toHex(substr($data, 9, 4)),
                'deviceVersionHex' => self::toHex(substr($data, 13, 4)),
                'applicationDescription' => $description,
                'generation' => null, 'regulatoryRegion' => null,
            ];
        } elseif ($operation === 'CO_RD_REPEATER') {
            $mode = ord($data[1]); $level = ord($data[2]);
            $result += ['modeRaw' => $mode, 'levelRaw' => $level,
                'mode' => [0 => 'OFF', 1 => 'ON', 2 => 'SELECTIVE'][$mode] ?? 'UNKNOWN'];
        } elseif ($operation === 'CO_RD_FILTER') {
            if ((strlen($data) - 1) % 5 !== 0) {
                throw new LengthException('Filter response must contain five bytes per entry.');
            }
            $result['filters'] = [];
            for ($i = 1; $i < strlen($data); $i += 5) {
                $criterion = ord($data[$i]);
                $result['filters'][] = ['criterionRaw' => $criterion,
                    'criterion' => [0 => 'Sender ID', 1 => 'R-ORG', 2 => 'RSSI', 3 => 'Destination ID'][$criterion] ?? 'UNKNOWN',
                    'valueHex' => self::toHex(substr($data, $i + 1, 4)),
                    'action' => null];
            }
            $result += ['enabled' => null, 'operator' => null];
        } elseif ($operation === 'CO_GET_FREQUENCY_INFO') {
            $frequency = ord($data[1]); $protocol = ord($data[2]);
            $result += ['frequencyRaw' => $frequency, 'protocolRaw' => $protocol,
                'frequency' => [0 => '315.000 MHz', 1 => '868.300 MHz', 2 => '902.875 MHz', 3 => '921.400 MHz', 4 => '928.350 MHz', 0x20 => '2.4 GHz'][$frequency] ?? null,
                'protocol' => [0 => 'ERP1', 1 => 'ERP2', 0x10 => 'IEEE 802.15.4', 0x30 => 'Long Range'][$protocol] ?? null,
                'regulatoryRegion' => null];
        } else {
            $result += ['stepCodeHex' => sprintf('%02X', ord($data[1])), 'revisionHex' => sprintf('%02X', ord($data[2]))];
        }
        return $result;
    }

    private const SYNC = 0x55;
    private const PACKET_TYPE_RESPONSE = 0x02;

    public static function crc8(string $bytes): int
    {
        $crc = 0;
        $length = strlen($bytes);

        for ($offset = 0; $offset < $length; $offset++) {
            $crc ^= ord($bytes[$offset]);

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x80) !== 0
                    ? (($crc << 1) ^ 0x07) & 0xFF
                    : ($crc << 1) & 0xFF;
            }
        }

        return $crc;
    }

    public static function fromHex(string $hex): string
    {
        $normalized = preg_replace('/\s+/', '', $hex);

        if ($normalized === null) {
            throw new ValueError('Unable to normalize hexadecimal input.');
        }

        if ($normalized === '') {
            return '';
        }

        if ((strlen($normalized) % 2) !== 0 || preg_match('/\A[0-9A-Fa-f]+\z/D', $normalized) !== 1) {
            throw new ValueError('Hexadecimal input must contain complete bytes only.');
        }

        $bytes = hex2bin($normalized);
        if ($bytes === false) {
            throw new ValueError('Unable to decode hexadecimal input.');
        }

        return $bytes;
    }

    public static function toHex(string $bytes): string
    {
        return strtoupper(bin2hex($bytes));
    }

    public static function buildReadIdBaseRequest(): string
    {
        $header = self::fromHex('00 01 00 05');
        $data = self::fromHex('08');

        return chr(self::SYNC)
            . $header
            . chr(self::crc8($header))
            . $data
            . chr(self::crc8($data));
    }

    /**
     * Normalize and fail-closed validate an ID-range base address.
     *
     * Conservative 128-address-block policy; never silently round a target.
     */
    public static function normalizeWritableBaseId(string $baseId): string
    {
        $normalized = strtoupper(trim($baseId));
        if (str_starts_with($normalized, '0X')) {
            $normalized = substr($normalized, 2);
        }

        if (preg_match('/\A[0-9A-F]{8}\z/D', $normalized) !== 1) {
            throw new ValueError('Base ID must contain exactly eight hexadecimal digits.');
        }

        if (
            strcmp($normalized, self::WRITE_IDBASE_MIN_HEX) < 0
            || strcmp($normalized, self::WRITE_IDBASE_MAX_HEX) > 0
        ) {
            throw new ValueError('Base ID is outside the ESP3 writable range.');
        }

        if ((hexdec(substr($normalized, -2)) & 0x7F) !== 0) {
            $suggested = substr($normalized, 0, 6) . sprintf('%02X', hexdec(substr($normalized, -2)) & 0x80);
            throw new ValueError('INVALID_BASE_ID_ALIGNMENT: ' . $normalized
                . ' ist keine gültige Base-ID für einen 128-Adressen-Block. Mögliche Blockadresse: '
                . $suggested . '. Keine automatische Übernahme; neuen Zielwert bewusst auswählen.');
        }
        return $normalized;
    }

    public static function buildWriteIdBaseRequest(string $baseId): string
    {
        $normalizedBaseId = self::normalizeWritableBaseId($baseId);
        $header = self::fromHex('00 05 00 05');
        $data = self::fromHex('07 ' . $normalizedBaseId);

        return chr(self::SYNC)
            . $header
            . chr(self::crc8($header))
            . $data
            . chr(self::crc8($data));
    }

    /**
     * @return array{
     *   rawHex: string,
     *   dataLength: int,
     *   optionalLength: int,
     *   packetType: int,
     *   headerCrc: int,
     *   dataCrc: int,
     *   data: string,
     *   optionalData: string
     * }
     */
    public static function parseFrame(string $frame): array
    {
        $actualLength = strlen($frame);
        if ($actualLength < 7) {
            throw new LengthException('ESP3 frame is shorter than the minimum frame length.');
        }

        if (ord($frame[0]) !== self::SYNC) {
            throw new RuntimeException('ESP3 sync byte is invalid.');
        }

        $dataLength = (ord($frame[1]) << 8) | ord($frame[2]);
        $optionalLength = ord($frame[3]);
        $packetType = ord($frame[4]);
        $payloadLength = $dataLength + $optionalLength;
        $expectedLength = 7 + $payloadLength;

        if ($actualLength !== $expectedLength) {
            throw new LengthException('ESP3 frame length does not match its header.');
        }

        $header = substr($frame, 1, 4);
        $headerCrc = ord($frame[5]);
        if (self::crc8($header) !== $headerCrc) {
            throw new RuntimeException('ESP3 header CRC is invalid.');
        }

        $payload = substr($frame, 6, $payloadLength);
        $dataCrc = ord($frame[$expectedLength - 1]);
        if (self::crc8($payload) !== $dataCrc) {
            throw new RuntimeException('ESP3 data CRC is invalid.');
        }

        return [
            'rawHex' => self::toHex($frame),
            'dataLength' => $dataLength,
            'optionalLength' => $optionalLength,
            'packetType' => $packetType,
            'headerCrc' => $headerCrc,
            'dataCrc' => $dataCrc,
            'data' => substr($frame, 6, $dataLength),
            'optionalData' => substr($frame, 6 + $dataLength, $optionalLength),
        ];
    }

    /**
     * @return array{
     *   rawHex: string,
     *   returnCode: int,
     *   returnName: string,
     *   baseIdRawHex: ?string,
     *   remainingWriteCyclesRawHex: ?string,
     *   remainingWriteCyclesMode: string,
     *   remainingWriteCycles: ?int,
     *   extraOptionalDataHex: string
     * }
     */
    public static function parseReadIdBaseResponse(string $frame): array
    {
        $parsed = self::parseFrame($frame);

        if ($parsed['packetType'] !== self::PACKET_TYPE_RESPONSE) {
            throw new RuntimeException('CO_RD_IDBASE result is not an ESP3 RESPONSE packet.');
        }

        $data = $parsed['data'];
        if (strlen($data) < 1) {
            throw new LengthException('ESP3 RESPONSE does not contain a return code.');
        }

        $returnCode = ord($data[0]);
        $returnName = match ($returnCode) {
            0x00 => 'RET_OK',
            0x01 => 'RET_ERROR',
            0x02 => 'RET_NOT_SUPPORTED',
            0x03 => 'RET_WRONG_PARAM',
            0x04 => 'RET_OPERATION_DENIED',
            0x05 => 'RET_LOCK_SET',
            0x06 => 'RET_BUFFER_TO_SMALL',
            0x07 => 'RET_NO_FREE_BUFFER',
            default => 'UNKNOWN_OR_RESERVED',
        };

        if ($returnCode !== 0x00) {
            return [
                'rawHex' => $parsed['rawHex'],
                'returnCode' => $returnCode,
                'returnName' => $returnName,
                'baseIdRawHex' => null,
                'remainingWriteCyclesRawHex' => null,
                'remainingWriteCyclesMode' => 'unknown',
                'remainingWriteCycles' => null,
                'extraOptionalDataHex' => self::toHex($parsed['optionalData']),
            ];
        }

        if (strlen($data) !== 5) {
            throw new LengthException('Successful CO_RD_IDBASE RESPONSE must contain return code plus four Base-ID bytes.');
        }

        $baseIdRawHex = self::toHex(substr($data, 1, 4));
        $optionalData = $parsed['optionalData'];
        $counter = strlen($optionalData) >= 1 ? ord($optionalData[0]) : null;

        return [
            'rawHex' => $parsed['rawHex'],
            'returnCode' => $returnCode,
            'returnName' => $returnName,
            'baseIdRawHex' => $baseIdRawHex,
            'remainingWriteCyclesRawHex' => $counter === null ? null : sprintf('%02X', $counter),
            'remainingWriteCyclesMode' => $counter === null
                ? 'unknown'
                : ($counter === 0xFF ? 'unlimited' : 'finite'),
            'remainingWriteCycles' => $counter === null || $counter === 0xFF ? null : $counter,
            'extraOptionalDataHex' => strlen($optionalData) <= 1
                ? ''
                : self::toHex(substr($optionalData, 1)),
        ];
    }

    /**
     * @return array{
     *   rawHex: string,
     *   returnCode: int,
     *   returnName: string,
     *   accepted: bool
     * }
     */
    public static function parseWriteIdBaseResponse(string $frame): array
    {
        $parsed = self::parseFrame($frame);

        if ($parsed['packetType'] !== self::PACKET_TYPE_RESPONSE) {
            throw new RuntimeException('CO_WR_IDBASE result is not an ESP3 RESPONSE packet.');
        }

        if (strlen($parsed['data']) !== 1 || $parsed['optionalData'] !== '') {
            throw new LengthException('CO_WR_IDBASE RESPONSE must contain exactly one response byte and no Optional Data.');
        }

        $returnCode = ord($parsed['data'][0]);
        $returnName = match ($returnCode) {
            0x00 => 'RET_OK',
            0x02 => 'RET_NOT_SUPPORTED',
            0x90 => 'BASEID_OUT_OF_RANGE',
            0x91 => 'BASEID_MAX_REACHED',
            default => 'UNKNOWN_OR_RESERVED',
        };

        return [
            'rawHex' => $parsed['rawHex'],
            'returnCode' => $returnCode,
            'returnName' => $returnName,
            'accepted' => $returnCode === 0x00,
        ];
    }
}
