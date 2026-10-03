<?php

declare(strict_types=1);

namespace EnOceanGatewayManager\Safety;

use EnOceanGatewayManager\Protocol\ESP3Codec;

/** Pure B5 policy. No I/O, no write effect, no configuration switch can unlock it. */
final class GatewayWritePreparation
{
    public const HARDWARE_WRITE_ENABLED = false;

    public static function evaluate(string $target, array $context, array $observations, array $backup, int $now): array
    {
        $fresh = static function (array $read) use ($context, $now): bool {
            $at = strtotime($read['readAt'] ?? '') ?: 0;
            return ($read['capability'] ?? '') === 'SUPPORTED_READ' && $at <= $now && $now - $at <= 60
                && ($context['session'] ?? '') !== '' && ($read['session'] ?? '') === $context['session']
                && ($read['binding'] ?? '') === ($context['binding'] ?? null)
                && ($read['parentInstanceID'] ?? '') === (string) ($context['arbiterID'] ?? 0);
        };
        $id = $observations['CO_RD_IDBASE'] ?? [];
        $version = $observations['CO_RD_VERSION'] ?? [];
        $preview = [];
        try { $preview = BaseIDPreflight::preview($target, $id['values'] ?? [], 5); } catch (\Throwable) {}
        try { $normalized = ESP3Codec::normalizeWritableBaseId($target); } catch (\Throwable) { $normalized = null; }
        $eurid = $version['values']['eurid'] ?? null;
        $raw = $id['values']['remainingWriteCyclesRawHex'] ?? null;
        $counterKnown = is_string($raw) && preg_match('/\A[0-9A-F]{2}\z/D', $raw) === 1;
        $confirmation = $backup['identityConfirmation'] ?? [];
        $confirmedAt = strtotime($confirmation['confirmedAt'] ?? '') ?: 0;
        $backupConfirmed = ($backup['baseID'] ?? '') !== '' && $confirmedAt <= $now && $now - $confirmedAt <= 60
            && ($confirmation['session'] ?? '') === ($context['session'] ?? null)
            && ($confirmation['backupBaseID'] ?? '') === ($backup['baseID'] ?? null)
            && ($confirmation['eurid'] ?? '') === ($backup['observedEURID'] ?? null);
        $gates = [
            'realConnectionActive' => ($context['realConnectionActive'] ?? false) === true,
            'correlationSafeAndIdle' => ($context['correlationSafeAndIdle'] ?? false) === true,
            'noUnknownOutcome' => ($context['noUnknownOutcome'] ?? false) === true,
            'freshBaseID' => $fresh($id),
            'freshWriteCycles' => $fresh($id) && $counterKnown,
            'freshEURID' => $fresh($version) && is_string($eurid) && preg_match('/\A[0-9A-F]{8}\z/D', $eurid) === 1,
            'confirmedBackupEURIDMatches' => $backupConfirmed && $eurid !== null && $eurid === ($backup['observedEURID'] ?? null),
            'backupBelongsToParent' => ($backup['parentInstanceID'] ?? '') === (string) ($context['arbiterID'] ?? 0)
                && ($backup['binding'] ?? '') !== '' && ($backup['binding'] ?? '') === ($context['binding'] ?? null),
            'validTarget' => $normalized !== null,
            'targetChangesBaseID' => $normalized !== null && $normalized !== ($id['values']['baseIdRawHex'] ?? null),
            'cyclesAvailable' => $counterKnown && hexdec($raw) > 0,
            'expectedCounterKnownAndReserveSafe' => isset($preview['expectedRemaining']),
            'exclusiveUARTOwner' => ($context['exclusiveUARTOwner'] ?? false) === true,
            // B5: real permissions are intentionally not accepted, even if supplied by a caller.
            'explicitHardwareAuthorization' => false,
            'immediateConcreteUserConfirmation' => false,
        ];
        $ready = !in_array(false, array_slice($gates, 0, 13, true), true);
        return $preview + ['state' => ($preview['noChange'] ?? false) ? 'NO_OP' : 'GESPERRT – Dry-Run',
            'eurid' => $eurid ?? 'UNKNOWN', 'backupStatus' => $backupConfirmed ? 'gültig / bestätigt' : 'nicht frisch bestätigt',
            'hardwareIdentity' => $gates['confirmedBackupEURIDMatches'] && $gates['backupBelongsToParent'] ? 'bestätigt' : 'nicht bestätigt',
            'gates' => $gates, 'readyForFutureAuthorization' => $ready,
            'hardwareWriteEnabled' => self::HARDWARE_WRITE_ENABLED, 'evaluatedAt' => gmdate('c', $now),
            'session' => $context['session'] ?? '', 'binding' => $context['binding'] ?? ''];
    }

    public static function dispatchBlocked(): array
    {
        return ['hardwareWriteEnabled' => false, 'sent' => false, 'reason' => 'B5_IMMUTABLE_HARDWARE_WRITE_BARRIER'];
    }
}
