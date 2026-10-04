<?php
declare(strict_types=1);

namespace EnOceanGatewayManager\Maintenance;

/** Observation only. Neither result authorizes a write or acquires a lease. */
final class C2BlockedGate
{
    public static function observe(array $actual, array $expected, string $handoffBinding, mixed $pending): string
    {
        if (!($actual['transportCorrelationSafe'] ?? false) || ($actual['writeLeaseActive'] ?? true)
            || !($actual['realConnectionActive'] ?? false) || !($actual['exclusiveUARTOwner'] ?? false)
            || !($actual['noUnknownOutcome'] ?? false) || ($actual['uartDescriptorCount'] ?? 0) !== 1
            || ($actual['session'] ?? '') !== $expected['session'] || ($actual['binding'] ?? '') !== $expected['transportBinding']
            || ($actual['communicationFaultEpoch'] ?? -1) !== $expected['faultEpoch']
            || $handoffBinding !== $expected['handoffBinding'] || $pending !== null) {
            throw new \RuntimeException('Prewrite arbiter context is not fresh, exclusive and idle.');
        }
        if (($actual['correlationSafeAndIdle'] ?? false) === true) { return 'IDLE'; }
        // The locked arbiter snapshot proves that ONLY its incoming parser is
        // occupied. Never treat queues, active requests or unsafe correlation
        // as this transient state. Missing capability remains fail-closed.
        if (($actual['incomingOnlyBusy'] ?? false) === true) { return 'INCOMING_BUSY'; }
        throw new \RuntimeException('Prewrite arbiter context is not fresh, exclusive and idle.');
    }
}
