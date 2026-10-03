<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Safety;

/** Authoritative classification only, not write authorization or a transition graph. */
final class TransactionStateModel
{
    // [terminal, recoveryRequired, completedRecovery]. Unknown states always block.
    private const STATES = [
        'IDLE' => [true, false, false],
        'CANCELLED' => [true, false, false],
        'NO_OP' => [true, false, false],
        'VERIFIED' => [true, false, false],
        'RECOVERY_NOT_APPLIED' => [true, false, true],
        'READ_ONLY_RESOLVED' => [true, false, true],
        'RECOVERED_WITH_DIFFERENT_APPLIED_VALUE' => [true, false, true],
        'ACQUIRE_LEASE' => [false, false, false],
        'PREFLIGHT' => [false, false, false],
        'READY_FOR_CONFIRMATION' => [false, false, false],
        'CONFIRMED' => [false, false, false],
        'PRE_WRITE_JOURNALED' => [false, false, false],
        'WRITE_SENT' => [false, false, false],
        'WAITING_FOR_RESPONSE' => [false, false, false],
        'WRITE_RESPONSE_RECEIVED' => [false, false, false],
        'FORCE_RECONNECT' => [false, false, false],
        'POST_VERIFY' => [false, false, false],
        'UNKNOWN_OUTCOME' => [false, true, false],
        'ADMIN_RECOVERY_RECONNECT' => [false, true, false],
        'ADMIN_RECOVERY_READS' => [false, true, false],
    ];

    public static function classify(string $state, bool $permanentFailure = false): array
    {
        $known = isset(self::STATES[$state]);
        [$terminal, $recoveryRequired, $completedRecovery] = self::STATES[$state] ?? [false, true, false];
        return [
            'state' => $state, 'known' => $known, 'terminal' => $terminal,
            'active' => !$terminal, 'leaseActive' => !$terminal,
            'recoveryRequired' => $recoveryRequired,
            'unresolvedUnknownOutcome' => !$known || $state === 'UNKNOWN_OUTCOME',
            // Permanent failure is operation metadata, not inferred from historical nested intents.
            'permanentlyBlocked' => !$known || (!$terminal && $permanentFailure),
            'newTransactionStructurallyAllowed' => $terminal,
            'completedRecovery' => $completedRecovery,
        ];
    }

    public static function matrix(): array
    {
        $matrix = [];
        foreach (array_keys(self::STATES) as $state) { $matrix[$state] = self::classify($state); }
        return $matrix;
    }
}
