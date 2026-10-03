<?php

declare(strict_types=1);

namespace EnOceanGatewayManager\Protocol;

use Throwable;

/**
 * Stateful, hardware-free ESP3 byte-stream parser.
 *
 * It accepts arbitrary fragments, returns every complete frame in order and
 * reports discarded/corrupt input as explicit error events. It never performs
 * I/O and never retries or transmits anything.
 */
final class ESP3StreamParser
{
    private const SYNC_BYTE = "\x55";
    private const ABSOLUTE_MAX_FRAME_LENGTH = 65797; // 7 + 65535 + 255
    private const ERROR_SAMPLE_BYTES = 64;

    private string $buffer = '';

    public function __construct(
        private readonly int $maximumFrameLength = self::ABSOLUTE_MAX_FRAME_LENGTH
    ) {
        if ($maximumFrameLength < 7 || $maximumFrameLength > self::ABSOLUTE_MAX_FRAME_LENGTH) {
            throw new \ValueError('Maximum ESP3 frame length is outside the supported range.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $events = [];

        while ($this->buffer !== '') {
            $syncOffset = strpos($this->buffer, self::SYNC_BYTE);
            if ($syncOffset === false) {
                $events[] = $this->errorEvent('garbage_before_sync', $this->buffer);
                $this->buffer = '';
                break;
            }

            if ($syncOffset > 0) {
                $garbage = substr($this->buffer, 0, $syncOffset);
                $events[] = $this->errorEvent('garbage_before_sync', $garbage);
                $this->buffer = substr($this->buffer, $syncOffset);
            }

            if (strlen($this->buffer) < 6) {
                break;
            }

            $header = substr($this->buffer, 1, 4);
            $actualHeaderCrc = ord($this->buffer[5]);
            if (ESP3Codec::crc8($header) !== $actualHeaderCrc) {
                $events[] = $this->errorEvent('header_crc', substr($this->buffer, 0, 6));
                $this->buffer = substr($this->buffer, 1);
                continue;
            }

            $dataLength = unpack('nlength', substr($this->buffer, 1, 2));
            if (!is_array($dataLength)) {
                $events[] = $this->errorEvent('malformed_length', substr($this->buffer, 0, 6));
                $this->buffer = substr($this->buffer, 1);
                continue;
            }

            $payloadLength = $dataLength['length'] + ord($this->buffer[3]);
            $frameLength = 7 + $payloadLength;
            if ($frameLength > $this->maximumFrameLength) {
                $events[] = $this->errorEvent('malformed_length', substr($this->buffer, 0, 6));
                $this->buffer = substr($this->buffer, 1);
                continue;
            }

            if (strlen($this->buffer) < $frameLength) {
                break;
            }

            $frame = substr($this->buffer, 0, $frameLength);
            $this->buffer = substr($this->buffer, $frameLength);

            try {
                $parsed = ESP3Codec::parseFrame($frame);
                $events[] = [
                    'type' => 'frame',
                    'frame' => $frame,
                    'rawHex' => $parsed['rawHex'],
                    'packetType' => $parsed['packetType'],
                    'parsed' => $parsed,
                ];
            } catch (Throwable $error) {
                $events[] = $this->errorEvent('data_crc_or_frame', $frame, $error->getMessage());
            }
        }

        return $events;
    }

    public function reset(): void
    {
        $this->buffer = '';
    }

    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    public function bufferedHex(): string
    {
        return ESP3Codec::toHex($this->buffer);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorEvent(string $reason, string $discarded, string $detail = ''): array
    {
        return [
            'type' => 'error',
            'reason' => $reason,
            'discardedLength' => strlen($discarded),
            'discardedHexSample' => ESP3Codec::toHex(substr($discarded, 0, self::ERROR_SAMPLE_BYTES)),
            'detail' => $detail,
        ];
    }
}
