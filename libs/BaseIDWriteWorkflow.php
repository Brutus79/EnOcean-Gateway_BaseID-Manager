<?php

declare(strict_types=1);

namespace EnOceanGatewayManager\Safety;

use EnOceanGatewayManager\Protocol\ESP3Codec;
use RuntimeException;

/**
 * Offline transaction specification. Effects are byte strings, never I/O.
 * A future arbiter adapter MUST hold one exclusive lease through fresh read,
 * write and verification. This class is not enabled in the product transport.
 */
final class BaseIDWriteWorkflow
{
    private string $state = 'AWAITING_PREVIEW_READ';
    private string $target;
    private ?array $preview = null;
    private ?string $confirmation = null;
    private int $expiresAt = 0;

    public function __construct(string $requested, private readonly int $reserve = 5)
    {
        // Constructor cannot produce an effect before full input validation.
        $this->target = ESP3Codec::normalizeWritableBaseId($requested);
    }

    public function state(): string { return $this->state; }

    public function previewReadEffect(): array
    {
        $this->expect('AWAITING_PREVIEW_READ');
        $this->state = 'PREVIEW_READ_REQUESTED';
        return $this->readEffect();
    }

    public function receivePreviewRead(string $frame, int $nowMs): array
    {
        $this->expect('PREVIEW_READ_REQUESTED');
        try {
            $this->preview = BaseIDPreflight::preview($this->target, ESP3Codec::parseReadIdBaseResponse($frame), $this->reserve);
        } catch (\Throwable $error) {
            $this->state = 'REJECTED'; throw $error;
        }
        if ($this->preview['noChange']) {
            $this->state = 'NO_CHANGE';
            return $this->preview;
        }
        $this->confirmation = bin2hex(random_bytes(16));
        $this->expiresAt = $nowMs + 60000;
        $this->state = 'AWAITING_CONFIRMATION';
        return $this->preview + ['confirmationToken' => $this->confirmation, 'expiresAtMs' => $this->expiresAt];
    }

    public function confirm(string $token, int $nowMs): array
    {
        $this->expect('AWAITING_CONFIRMATION');
        if ($nowMs >= $this->expiresAt || $this->confirmation === null || !hash_equals($this->confirmation, $token)) {
            $this->state = 'REJECTED'; throw new RuntimeException('Confirmation is invalid or expired. Prepare a new preview.');
        }
        $this->confirmation = null;
        $this->state = 'PREWRITE_READ_REQUESTED';
        return $this->readEffect();
    }

    public function receivePrewriteRead(string $frame): array
    {
        $this->expect('PREWRITE_READ_REQUESTED');
        try {
            $fresh = BaseIDPreflight::preview($this->target, ESP3Codec::parseReadIdBaseResponse($frame), $this->reserve);
        } catch (\Throwable $error) {
            $this->state = 'REJECTED'; throw $error;
        }
        if ($fresh['currentBaseID'] !== $this->preview['currentBaseID'] || $fresh['remaining'] !== $this->preview['remaining']) {
            $this->state = 'RECONFIRM_REQUIRED';
            throw new RuntimeException('Hardware Base ID or counter changed. Prepare and confirm a new preview.');
        }
        // Consume the only write opportunity before returning its effect.
        $this->state = 'WRITE_REQUESTED';
        return ['operation' => 'CO_WR_IDBASE', 'frameHex' => ESP3Codec::toHex(ESP3Codec::buildWriteIdBaseRequest($this->target)),
            'requiresExclusiveArbiterLease' => true, 'requiresPersistentAuditBeforeSend' => true, 'retryAllowed' => false];
    }

    public function receiveWriteResponse(string $frame): array
    {
        $this->expect('WRITE_REQUESTED');
        try { $result = ESP3Codec::parseWriteIdBaseResponse($frame); }
        catch (\Throwable $error) { $this->state = 'UNKNOWN_OUTCOME'; throw $error; }
        if (!$result['accepted']) {
            $this->state = 'DEVICE_REJECTED';
            return $result + ['message' => match ($result['returnName']) {
                'RET_NOT_SUPPORTED' => 'Gateway unterstützt Base-ID-Schreiben nicht.',
                'BASEID_OUT_OF_RANGE' => 'Gateway lehnt die gewünschte Base-ID als außerhalb seines Wertebereichs ab.',
                'BASEID_MAX_REACHED' => 'Keine Base-ID-Schreibmöglichkeiten mehr; Grenze nicht zurücksetzbar.',
                default => 'Gateway meldet einen unbekannten Schreibfehler; keine Wiederholung.',
            }];
        }
        $this->state = 'VERIFY_READ_REQUESTED';
        return $this->readEffect();
    }

    public function receiveVerifyRead(string $frame): array
    {
        $this->expect('VERIFY_READ_REQUESTED');
        try { $actual = ESP3Codec::parseReadIdBaseResponse($frame); }
        catch (\Throwable $error) { $this->state = 'UNKNOWN_OUTCOME'; throw $error; }
        $expected = $this->preview['expectedRemaining'];
        $actualCounter = $actual['remainingWriteCyclesRawHex'] ?? null;
        $verified = $actual['returnName'] === 'RET_OK' && $actual['baseIdRawHex'] === $this->target
            && $actualCounter !== null
            && ($expected === 'UNLIMITED' ? $actualCounter === 'FF' : hexdec($actualCounter) === $expected);
        $this->state = $verified ? 'VERIFIED' : 'VERIFICATION_FAILED';
        return ['verified' => $verified, 'actual' => $actual, 'retryAllowed' => false];
    }

    public function transportLost(): void
    {
        $this->state = in_array($this->state, ['WRITE_REQUESTED', 'VERIFY_READ_REQUESTED'], true)
            ? 'UNKNOWN_OUTCOME' : 'CANCELLED';
        $this->confirmation = null;
    }

    private function readEffect(): array
    {
        return ['operation' => 'CO_RD_IDBASE', 'frameHex' => ESP3Codec::READ_IDBASE_REQUEST_HEX,
            'requiresExclusiveArbiterLease' => true];
    }

    private function expect(string $state): void
    {
        if ($this->state !== $state) { throw new RuntimeException('Invalid transaction phase: ' . $this->state); }
    }
}
