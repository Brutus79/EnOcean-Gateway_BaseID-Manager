<?php

declare(strict_types=1);

namespace EnOceanGatewayManager\Transport;

use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Protocol\ESP3StreamParser;
use ValueError;

/**
 * Pure state machine for transparent ESP3 routing and read-only maintenance.
 *
 * The class emits actions for a thin IP-Symcon adapter. It has no serial,
 * filesystem, network, process or IP-Symcon access of its own.
 */
final class ESP3TransportArbiterCore
{
    public const OPERATION_READ_IDBASE = 'CO_RD_IDBASE';

    private const PACKET_TYPE_RESPONSE = 0x02;
    private const MINIMUM_TIMEOUT_MS = 100;
    private const MAXIMUM_TIMEOUT_MS = 5000;

    private ESP3StreamParser $incomingParser;
    private ESP3StreamParser $nativeOutgoingParser;

    /** @var list<array<string, mixed>> */
    private array $maintenanceQueue = [];

    /** @var list<string> */
    private array $nativeQueue = [];

    /** @var array<string, mixed>|null */
    private ?array $activeMaintenance = null;

    private int $nativeQueueBytes = 0;
    private int $nativeResponseDebt = 0;
    private int $quarantineUntilMs = 0;
    private bool $connected = false;
    private bool $maintenanceEnabled = false;
    private bool $correlationUnsafe = false;

    public function __construct(
        private readonly int $maximumQueueItems = 32,
        private readonly int $maximumQueueBytes = 65536,
        private readonly int $defaultTimeoutMs = 500,
        private readonly int $lateResponseGuardMs = 250,
        int $maximumFrameLength = 65797
    ) {
        if ($maximumQueueItems < 1 || $maximumQueueBytes < 1) {
            throw new ValueError('Queue limits must be positive.');
        }
        if ($defaultTimeoutMs < self::MINIMUM_TIMEOUT_MS || $defaultTimeoutMs > self::MAXIMUM_TIMEOUT_MS) {
            throw new ValueError('Default maintenance timeout is outside the supported range.');
        }
        if ($lateResponseGuardMs < 0 || $lateResponseGuardMs > self::MAXIMUM_TIMEOUT_MS) {
            throw new ValueError('Late-response guard is outside the supported range.');
        }

        $this->incomingParser = new ESP3StreamParser($maximumFrameLength);
        $this->nativeOutgoingParser = new ESP3StreamParser($maximumFrameLength);
    }

    public function setMaintenanceEnabled(bool $enabled): void
    {
        $this->maintenanceEnabled = $enabled;
    }

    /** A process restart cannot prove that old UART responses were drained. */
    public function requireTransportReconnect(): void
    {
        $this->correlationUnsafe = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function setConnected(bool $connected, int $nowMs): array
    {
        if ($connected === $this->connected) {
            return [];
        }

        if (!$connected) {
            return $this->disconnect('transport_disconnected', $nowMs);
        }

        $this->connected = true;
        $this->correlationUnsafe = false;
        $this->quarantineUntilMs = 0;
        $this->incomingParser->reset();
        $this->nativeOutgoingParser->reset();

        $actions = [[
            'type' => 'diagnostic',
            'level' => 'info',
            'code' => 'transport_connected',
        ]];

        return array_merge($actions, $this->pump($nowMs));
    }

    /**
     * @param array<string, mixed> $request
     * @return array{accepted: bool, reason: string, actions: list<array<string, mixed>>}
     */
    public function enqueueMaintenance(array $request, int $nowMs): array
    {
        if (!$this->maintenanceEnabled) {
            return $this->rejection('maintenance_disabled');
        }
        if (!$this->connected) {
            return $this->rejection('transport_disconnected');
        }
        if ($this->correlationUnsafe) {
            return $this->rejection('response_correlation_unsafe');
        }

        $operation = (string) ($request['operation'] ?? '');
        $ownerInstanceId = (int) ($request['ownerInstanceId'] ?? 0);
        $token = (string) ($request['token'] ?? '');
        $frameHex = strtoupper((string) preg_replace('/\s+/', '', (string) ($request['frameHex'] ?? '')));
        $timeoutMs = (int) ($request['timeoutMs'] ?? $this->defaultTimeoutMs);

        if (!isset(ESP3Codec::READ_COMMANDS[$operation])) {
            return $this->rejection('operation_not_allowlisted');
        }
        if ($frameHex !== ESP3Codec::toHex(ESP3Codec::buildReadRequest($operation))) {
            return $this->rejection('frame_not_allowlisted');
        }
        if ($ownerInstanceId <= 0) {
            return $this->rejection('invalid_owner');
        }
        if (preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/D', $token) !== 1) {
            return $this->rejection('invalid_token');
        }
        if ($timeoutMs < self::MINIMUM_TIMEOUT_MS || $timeoutMs > self::MAXIMUM_TIMEOUT_MS) {
            return $this->rejection('invalid_timeout');
        }
        if ($this->tokenExists($token)) {
            return $this->rejection('duplicate_token');
        }

        $frame = ESP3Codec::fromHex($frameHex);
        if (!$this->hasQueueCapacity(1, strlen($frame))) {
            return $this->rejection('backpressure');
        }

        $this->maintenanceQueue[] = [
            'operation' => $operation,
            'ownerInstanceId' => $ownerInstanceId,
            'token' => $token,
            'frame' => $frame,
            'frameHex' => $frameHex,
            'timeoutMs' => $timeoutMs,
            'enqueuedAtMs' => $nowMs,
        ];

        return [
            'accepted' => true,
            'reason' => 'queued',
            'actions' => $this->pump($nowMs),
        ];
    }

    /**
     * Accept bytes produced by the native EnOcean Gateway child.
     *
     * @return list<array<string, mixed>>
     */
    public function submitNativeBytes(string $bytes, int $nowMs): array
    {
        if ($bytes === '') {
            return [];
        }
        if (!$this->connected) {
            return [[
                'type' => 'native_rejected',
                'reason' => 'transport_disconnected',
                'bytes' => strlen($bytes),
            ]];
        }
        if ($this->correlationUnsafe) {
            return [[
                'type' => 'native_rejected',
                'reason' => 'response_correlation_unsafe',
                'bytes' => strlen($bytes),
            ]];
        }

        if (
            $this->activeMaintenance !== null
            || $this->maintenanceQueue !== []
            || $this->quarantineUntilMs > $nowMs
        ) {
            return $this->queueNativeBytes($bytes);
        }

        $actions = [[
            'type' => 'send_parent',
            'source' => 'native',
            'bytes' => $bytes,
        ]];
        return array_merge($actions, $this->accountNativeRequestBytes($bytes));
    }

    /**
     * Accept bytes received from the Serial Port parent.
     *
     * @return list<array<string, mixed>>
     */
    public function receiveBytes(string $bytes, int $nowMs): array
    {
        $actions = [];

        foreach ($this->incomingParser->feed($bytes) as $event) {
            if ($event['type'] === 'error') {
                $actions[] = [
                    'type' => 'diagnostic',
                    'level' => 'warning',
                    'code' => 'incoming_' . $event['reason'],
                    'detail' => $event,
                ];
                continue;
            }

            $frame = $event['frame'];
            $packetType = $event['packetType'];
            if ($packetType !== self::PACKET_TYPE_RESPONSE) {
                $actions[] = [
                    'type' => 'send_native_child',
                    'classification' => $this->classifyPacketType($packetType),
                    'bytes' => $frame,
                ];
                continue;
            }

            if ($this->quarantineUntilMs > 0) {
                $actions[] = [
                    'type' => 'diagnostic',
                    'level' => 'warning',
                    'code' => 'late_response_quarantined',
                    'frameHex' => ESP3Codec::toHex($frame),
                ];
                $this->quarantineUntilMs = 0;
                continue;
            }

            if ($this->correlationUnsafe) {
                $actions[] = [
                    'type' => 'diagnostic',
                    'level' => 'warning',
                    'code' => 'response_quarantined_correlation_unsafe',
                    'frameHex' => ESP3Codec::toHex($frame),
                ];
                continue;
            }

            if ($this->activeMaintenance !== null) {
                $actions[] = $this->maintenanceResult(
                    $this->activeMaintenance,
                    'RESPONSE',
                    'response_received',
                    ESP3Codec::toHex($frame)
                );
                $this->activeMaintenance = null;
                continue;
            }

            if ($this->nativeResponseDebt > 0) {
                $this->nativeResponseDebt--;
                $actions[] = [
                    'type' => 'send_native_child',
                    'classification' => 'native_response',
                    'bytes' => $frame,
                ];
                continue;
            }

            $actions[] = [
                'type' => 'diagnostic',
                'level' => 'warning',
                'code' => 'unexpected_response',
                'frameHex' => ESP3Codec::toHex($frame),
            ];
            $actions[] = [
                'type' => 'send_native_child',
                'classification' => 'unexpected_response',
                'bytes' => $frame,
            ];
        }

        return array_merge($actions, $this->pump($nowMs));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tick(int $nowMs): array
    {
        $actions = [];

        if (
            $this->activeMaintenance !== null
            && $nowMs >= (int) $this->activeMaintenance['deadlineMs']
        ) {
            $actions[] = $this->maintenanceResult(
                $this->activeMaintenance,
                'UNKNOWN_OUTCOME',
                'response_timeout'
            );
            $this->activeMaintenance = null;
            $this->quarantineUntilMs = $nowMs + $this->lateResponseGuardMs;
            $this->correlationUnsafe = true;

            foreach ($this->maintenanceQueue as $queued) {
                $actions[] = $this->maintenanceResult(
                    $queued,
                    'CANCELLED_NOT_SENT',
                    'response_correlation_unsafe'
                );
            }
            $this->maintenanceQueue = [];
            $actions[] = [
                'type' => 'diagnostic',
                'level' => 'error',
                'code' => 'response_correlation_unsafe_after_timeout',
            ];
        }

        if ($this->quarantineUntilMs > 0 && $nowMs >= $this->quarantineUntilMs) {
            $this->quarantineUntilMs = 0;
            $actions[] = [
                'type' => 'diagnostic',
                'level' => 'info',
                'code' => 'late_response_guard_expired',
            ];
        }

        return array_merge($actions, $this->pump($nowMs));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function disconnect(string $reason, int $nowMs): array
    {
        $actions = [];

        if ($this->activeMaintenance !== null) {
            $actions[] = $this->maintenanceResult(
                $this->activeMaintenance,
                'UNKNOWN_OUTCOME',
                $reason
            );
        }
        foreach ($this->maintenanceQueue as $queued) {
            $actions[] = $this->maintenanceResult(
                $queued,
                'CANCELLED_NOT_SENT',
                $reason
            );
        }
        if ($this->nativeQueue !== []) {
            $actions[] = [
                'type' => 'diagnostic',
                'level' => 'error',
                'code' => 'native_queue_dropped_on_disconnect',
                'queuedItems' => count($this->nativeQueue),
                'queuedBytes' => $this->nativeQueueBytes,
            ];
        }

        $this->connected = false;
        $this->activeMaintenance = null;
        $this->maintenanceQueue = [];
        $this->nativeQueue = [];
        $this->nativeQueueBytes = 0;
        $this->nativeResponseDebt = 0;
        $this->quarantineUntilMs = 0;
        $this->correlationUnsafe = false;
        $this->incomingParser->reset();
        $this->nativeOutgoingParser->reset();

        $actions[] = [
            'type' => 'diagnostic',
            'level' => 'warning',
            'code' => $reason,
            'atMs' => $nowMs,
        ];

        return $actions;
    }

    /**
     * Minimal persistent audit record used to fail closed after module/kernel restart.
     *
     * @return array<string, mixed>
     */
    public function restartRecord(): array
    {
        return [
            'active' => $this->requestMetadata($this->activeMaintenance),
            'queuedMaintenance' => array_map($this->requestMetadata(...), $this->maintenanceQueue),
            'nativeQueuedItems' => count($this->nativeQueue),
            'nativeQueuedBytes' => $this->nativeQueueBytes,
            'nativeResponseDebt' => $this->nativeResponseDebt,
            'incomingBufferedBytes' => $this->incomingParser->bufferedBytes(),
            'outgoingBufferedBytes' => $this->nativeOutgoingParser->bufferedBytes(),
            'correlationUnsafe' => $this->correlationUnsafe,
            'quarantineUntilMs' => $this->quarantineUntilMs,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return list<array<string, mixed>>
     */
    public static function restartRecoveryActions(array $record): array
    {
        $actions = [];
        $active = $record['active'] ?? null;
        if (is_array($active) && isset($active['ownerInstanceId'], $active['token'], $active['operation'])) {
            $actions[] = [
                'type' => 'maintenance_result',
                'ownerInstanceId' => (int) $active['ownerInstanceId'],
                'token' => (string) $active['token'],
                'operation' => (string) $active['operation'],
                'outcome' => 'UNKNOWN_OUTCOME',
                'reason' => 'arbiter_restarted',
                'frameHex' => '',
            ];
        }

        $queued = $record['queuedMaintenance'] ?? [];
        if (is_array($queued)) {
            foreach ($queued as $request) {
                if (!is_array($request) || !isset($request['ownerInstanceId'], $request['token'], $request['operation'])) {
                    continue;
                }
                $actions[] = [
                    'type' => 'maintenance_result',
                    'ownerInstanceId' => (int) $request['ownerInstanceId'],
                    'token' => (string) $request['token'],
                    'operation' => (string) $request['operation'],
                    'outcome' => 'CANCELLED_NOT_SENT',
                    'reason' => 'arbiter_restarted',
                    'frameHex' => '',
                ];
            }
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return [
            'connected' => $this->connected,
            'maintenanceEnabled' => $this->maintenanceEnabled,
            'correlationUnsafe' => $this->correlationUnsafe,
            'activeToken' => $this->activeMaintenance['token'] ?? null,
            'maintenanceQueueItems' => count($this->maintenanceQueue),
            'nativeQueueItems' => count($this->nativeQueue),
            'nativeQueueBytes' => $this->nativeQueueBytes,
            'nativeResponseDebt' => $this->nativeResponseDebt,
            'quarantineUntilMs' => $this->quarantineUntilMs,
            'incomingBufferedBytes' => $this->incomingParser->bufferedBytes(),
            'outgoingBufferedBytes' => $this->nativeOutgoingParser->bufferedBytes(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pump(int $nowMs): array
    {
        if (
            !$this->connected
            || $this->activeMaintenance !== null
            || $this->correlationUnsafe
            || $this->quarantineUntilMs > $nowMs
        ) {
            return [];
        }

        $actions = [];

        if ($this->nativeQueue !== []) {
            $queued = $this->nativeQueue;
            $this->nativeQueue = [];
            $this->nativeQueueBytes = 0;

            foreach ($queued as $bytes) {
                $actions[] = [
                    'type' => 'send_parent',
                    'source' => 'native_queued',
                    'bytes' => $bytes,
                ];
                $actions = array_merge($actions, $this->accountNativeRequestBytes($bytes));
            }
        }

        if (
            $this->nativeResponseDebt !== 0
            || $this->nativeOutgoingParser->bufferedBytes() !== 0
            || $this->maintenanceQueue === []
        ) {
            return $actions;
        }

        $request = array_shift($this->maintenanceQueue);
        if (!is_array($request)) {
            return $actions;
        }

        $request['startedAtMs'] = $nowMs;
        $request['deadlineMs'] = $nowMs + (int) $request['timeoutMs'];
        $this->activeMaintenance = $request;
        $actions[] = [
            'type' => 'send_parent',
            'source' => 'maintenance',
            'bytes' => $request['frame'],
            'ownerInstanceId' => $request['ownerInstanceId'],
            'token' => $request['token'],
            'operation' => $request['operation'],
        ];

        return $actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountNativeRequestBytes(string $bytes): array
    {
        $actions = [];
        foreach ($this->nativeOutgoingParser->feed($bytes) as $event) {
            if ($event['type'] === 'frame') {
                $this->nativeResponseDebt++;
                continue;
            }

            $this->correlationUnsafe = true;
            $actions[] = [
                'type' => 'diagnostic',
                'level' => 'error',
                'code' => 'native_outgoing_' . $event['reason'],
                'detail' => $event,
            ];
        }

        return $actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queueNativeBytes(string $bytes): array
    {
        if (!$this->hasQueueCapacity(1, strlen($bytes))) {
            return [[
                'type' => 'native_rejected',
                'reason' => 'backpressure',
                'bytes' => strlen($bytes),
            ]];
        }

        $this->nativeQueue[] = $bytes;
        $this->nativeQueueBytes += strlen($bytes);

        return [[
            'type' => 'native_queued',
            'bytes' => strlen($bytes),
            'position' => count($this->nativeQueue),
        ]];
    }

    private function hasQueueCapacity(int $additionalItems, int $additionalBytes): bool
    {
        $items = count($this->maintenanceQueue) + count($this->nativeQueue);
        $bytes = $this->nativeQueueBytes;
        foreach ($this->maintenanceQueue as $request) {
            $bytes += strlen((string) $request['frame']);
        }

        return ($items + $additionalItems) <= $this->maximumQueueItems
            && ($bytes + $additionalBytes) <= $this->maximumQueueBytes;
    }

    private function tokenExists(string $token): bool
    {
        if (($this->activeMaintenance['token'] ?? null) === $token) {
            return true;
        }
        foreach ($this->maintenanceQueue as $request) {
            if ($request['token'] === $token) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{accepted: false, reason: string, actions: array{}}
     */
    private function rejection(string $reason): array
    {
        return [
            'accepted' => false,
            'reason' => $reason,
            'actions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function maintenanceResult(
        array $request,
        string $outcome,
        string $reason,
        string $frameHex = ''
    ): array {
        return [
            'type' => 'maintenance_result',
            'ownerInstanceId' => (int) $request['ownerInstanceId'],
            'token' => (string) $request['token'],
            'operation' => (string) $request['operation'],
            'outcome' => $outcome,
            'reason' => $reason,
            'frameHex' => $frameHex,
        ];
    }

    private function classifyPacketType(int $packetType): string
    {
        return match ($packetType) {
            0x01, 0x03, 0x09, 0x0A, 0x10 => 'radio',
            0x04 => 'event',
            0x0C => 'command_accepted',
            default => 'native_packet',
        };
    }

    /**
     * @param array<string, mixed>|null $request
     * @return array<string, mixed>|null
     */
    private function requestMetadata(?array $request): ?array
    {
        if ($request === null) {
            return null;
        }

        return [
            'operation' => (string) $request['operation'],
            'ownerInstanceId' => (int) $request['ownerInstanceId'],
            'token' => (string) $request['token'],
            'frameHex' => (string) $request['frameHex'],
            'timeoutMs' => (int) $request['timeoutMs'],
            'enqueuedAtMs' => (int) $request['enqueuedAtMs'],
            'startedAtMs' => isset($request['startedAtMs']) ? (int) $request['startedAtMs'] : null,
            'deadlineMs' => isset($request['deadlineMs']) ? (int) $request['deadlineMs'] : null,
        ];
    }
}
