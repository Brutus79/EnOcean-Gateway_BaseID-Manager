<?php

declare(strict_types=1);

namespace EnOceanGatewayManager\Safety;

use EnOceanGatewayManager\Protocol\ESP3Codec;
use ValueError;

/** Hardware-free preview. This does not authorize or transmit any write. */
final class BaseIDPreflight
{
    public static function preview(string $requested, array $freshRead, int $minimumRemaining = 5): array
    {
        $target = ESP3Codec::normalizeWritableBaseId($requested);
        if ($minimumRemaining < 0 || $minimumRemaining > 254) {
            throw new ValueError('Invalid write reserve.');
        }
        if (($freshRead['returnName'] ?? '') !== 'RET_OK' || !is_string($freshRead['baseIdRawHex'] ?? null)) {
            throw new ValueError('Successful fresh CO_RD_IDBASE required.');
        }
        $current = ESP3Codec::normalizeWritableBaseId($freshRead['baseIdRawHex']);
        $rawCounter = $freshRead['remainingWriteCyclesRawHex'] ?? null;
        if (!is_string($rawCounter) || preg_match('/\A[0-9A-F]{2}\z/D', $rawCounter) !== 1) {
            throw new ValueError('Hardware write counter is unknown.');
        }
        $counter = hexdec($rawCounter);
        $noChange = $target === $current;
        if (!$noChange && $counter !== 255 && $counter <= $minimumRemaining) {
            throw new ValueError('Write would violate the required remaining-cycle reserve.');
        }
        return ['currentBaseID' => $current, 'requestedBaseID' => $target,
            'remaining' => $counter === 255 ? 'UNLIMITED' : $counter,
            'expectedRemaining' => $counter === 255 ? 'UNLIMITED' : $counter - ($noChange ? 0 : 1),
            'noChange' => $noChange, 'minimumRemaining' => $minimumRemaining,
            'hardwareWriteEnabled' => false, 'confirmationRequired' => true];
    }
}
