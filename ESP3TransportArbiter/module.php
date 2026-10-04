<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ESP3Codec.php';
require_once __DIR__ . '/../libs/ESP3StreamParser.php';
require_once __DIR__ . '/../libs/ESP3TransportArbiterCore.php';
require_once __DIR__ . '/../libs/BaseIDPreflight.php';
require_once __DIR__ . '/../libs/WriteJournal.php';
require_once __DIR__ . '/../libs/TransactionalWrite.php';

use EnOceanGatewayManager\Transport\ESP3TransportArbiterCore;

final class ESP3TransportArbiter extends IPSModuleStrict
{
    private const BLOCK_NATIVE_TX = true;
    // B7.3 VERIFIED: technical hardware writes finished; barrier restored for B8 acceptance.
    private const B6_HARDWARE_WRITE_BARRIER = true;
    private const SERIAL_TX_DATA_ID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const SERIAL_RX_DATA_ID = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';
    private const MAINTENANCE_REQUEST_DATA_ID = '{F5B497B9-0A7D-4F1A-A830-86B1B85A55D4}';
    private const MAINTENANCE_RESULT_DATA_ID = '{4AE7CA23-1782-4C98-81E2-2BA7FC918C2C}';

    private ?ESP3TransportArbiterCore $core = null;
    private ?\EnOceanGatewayManager\Safety\TransactionalWrite $transaction = null;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EnableMaintenance', false);
        $this->RegisterPropertyBoolean('IsolatedReadOnly', true);
        $this->RegisterPropertyInteger('MaximumQueueItems', 32);
        $this->RegisterPropertyInteger('MaximumQueueBytes', 65536);
        $this->RegisterPropertyInteger('MaintenanceTimeoutMs', 500);
        $this->RegisterPropertyInteger('LateResponseGuardMs', 250);

        $this->RegisterAttributeString('RestartRecord', '{}');
        $this->RegisterAttributeString('CoreState', '{}');
        $this->RegisterAttributeString('LastDiagnostic', 'Not initialized');
        $this->RegisterAttributeString('TrafficAudit', '[]');
        $this->RegisterAttributeString('WriteTransactionState', '{"state":"IDLE","hardwareWriteEnabled":false}');
        $this->RegisterAttributeString('WriteJournalFault', '');
        $this->RegisterAttributeString('CommunicationFaultEpoch', '0');
        $this->RegisterAttributeString('CommunicationFaultHistory', '[]');

        $this->RegisterVariableString('TransportStatus', 'Transport status');
        $this->RegisterVariableString('QueueStatus', 'Queue status');
        $this->RegisterVariableString('LastDiagnostic', 'Last diagnostic');

        $this->RegisterTimer('MaintenanceTimer', 100, 'EGMA_ProcessTimeouts($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->withInstanceLock(function (): void {
            $core = $this->core();
            $core->setMaintenanceEnabled($this->ReadPropertyBoolean('EnableMaintenance'));
            $this->applyActions($core->setConnected($this->HasActiveParent(), $this->nowMs()));
            $this->persistCoreState();
        });
        $this->SetStatus($this->HasActiveParent() ? 102 : 201);
    }

    public function ForwardData(string $JSONString): string
    {
        return $this->withInstanceLock(function () use ($JSONString): string {
            $packet = json_decode($JSONString, true);
            if (!is_array($packet) || !isset($packet['DataID'])) {
                return $this->result(false, 'invalid_json_packet');
            }

            // Reject statically invalid targets before loading a lease/WAL/core.
            if ($packet['DataID'] === self::MAINTENANCE_REQUEST_DATA_ID
                && in_array($packet['Operation'] ?? '', ['B6_BEGIN', 'B6_CONFIRM'], true)) {
                try { \EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId((string) ($packet['Target'] ?? '')); }
                catch (Throwable $e) { return $this->result(false, $e->getMessage()); }
            }

            $core = $this->core();
            $actions = $core->setConnected($this->HasActiveParent(), $this->nowMs());
            $this->applyActions($actions);

            if ($packet['DataID'] === self::SERIAL_TX_DATA_ID) {
                if (self::BLOCK_NATIVE_TX) { // Native traffic can never inject a write.
                    $this->recordDiagnostic('warning', 'native_transmission_blocked_in_isolated_read_only_mode');
                    return '';
                }
                $bytes = $this->decodeStrictHexBuffer($packet['Buffer'] ?? null);
                if ($bytes === null) {
                    return $this->result(false, 'invalid_native_hex_buffer');
                }

                $nativeActions = $core->submitNativeBytes($bytes, $this->nowMs());
                $rejected = $this->containsAction($nativeActions, 'native_rejected');
                $parentResult = $this->applyActions($nativeActions);
                $this->persistCoreState();
                return $rejected ? '' : ($parentResult ?? '');
            }

            if ($packet['DataID'] === self::MAINTENANCE_REQUEST_DATA_ID) {
                if (str_starts_with((string) ($packet['Operation'] ?? ''), 'B6_')) { return $this->handleWriteControl($packet); }
                if ($this->writeTransaction()->active()) { return $this->result(false, 'exclusive_write_lease_busy'); }
                if (isset($packet['ExpectedSession']) && !hash_equals($this->transportBinding()['session'], (string) $packet['ExpectedSession'])) {
                    return $this->result(false, 'transport_session_changed');
                }
                $requestResult = $core->enqueueMaintenance([
                    'operation' => $packet['Operation'] ?? '',
                    'ownerInstanceId' => $packet['OwnerInstanceID'] ?? 0,
                    'token' => $packet['Token'] ?? '',
                    'frameHex' => $packet['FrameHex'] ?? '',
                    'timeoutMs' => $packet['TimeoutMs'] ?? $this->ReadPropertyInteger('MaintenanceTimeoutMs'),
                ], $this->nowMs());
                $this->applyActions($requestResult['actions']);
                $this->persistCoreState();
                return $this->result($requestResult['accepted'], $requestResult['reason']);
            }

            return $this->result(false, 'unsupported_data_id');
        });
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->withInstanceLock(function () use ($JSONString): void {
            $packet = json_decode($JSONString, true);
            if (!is_array($packet) || ($packet['DataID'] ?? '') !== self::SERIAL_RX_DATA_ID) {
                $this->recordDiagnostic('warning', 'unsupported_parent_packet');
                return;
            }

            $bytes = $this->decodeStrictHexBuffer($packet['Buffer'] ?? null);
            if ($bytes === null) {
                $this->recordDiagnostic('warning', 'invalid_parent_hex_buffer');
                return;
            }

            $core = $this->core();
            $this->applyActions($core->setConnected($this->HasActiveParent(), $this->nowMs()));
            if ($this->writeTransaction()->snapshot()['state'] === 'WAITING_FOR_RESPONSE') {
                $this->writeTransaction()->observe(json_decode($this->readSafetyContextUnlocked(), true), time(), $this->writeJournal());
                if ($this->writeTransaction()->snapshot()['state'] !== 'WAITING_FOR_RESPONSE') { $this->saveWriteTransaction(); return; }
                $this->receiveWriteBytes($bytes); $this->persistCoreState(); return;
            }
            $this->applyActions($core->receiveBytes($bytes, $this->nowMs()));
            $this->persistCoreState();
        });
        return '';
    }

    public function ProcessTimeouts(): void
    {
        $this->withInstanceLock(function (): void {
            $core = $this->core();
            $this->applyActions($core->setConnected($this->HasActiveParent(), $this->nowMs()));
            $this->applyActions($core->tick($this->nowMs()));
            $this->persistCoreState();
            $this->processWriteTransaction();
            $this->SetStatus($this->HasActiveParent() ? 102 : 201);
        });
    }

    public function GetTransportState(): string { return $this->ReadAttributeString('CoreState'); }
    public function GetTrafficAudit(): string { return $this->ReadAttributeString('TrafficAudit'); }
    public function GetWriteTransactionView(): string
    {
        $v = json_decode($this->ReadAttributeString('WriteTransactionState'), true) ?: ['state' => ''];
        $v['stateClassification'] = \EnOceanGatewayManager\Safety\TransactionStateModel::classify($v['state'] ?? '', (bool) ($v['permanentFailure'] ?? false));
        $v['hardwareWriteBarrier'] = self::B6_HARDWARE_WRITE_BARRIER;
        $v['packageBarrier'] = self::B6_HARDWARE_WRITE_BARRIER ? 'B6_HARDWARE_WRITE_BLOCKED' : 'PACKAGE_BARRIER_OPEN';
        $v['hardwareWriteEnabled'] = !self::B6_HARDWARE_WRITE_BARRIER && ($v['state'] ?? '') === 'PRE_WRITE_JOURNALED'
            && ($v['authorized'] ?? false) && !($v['readOnlyRecovery'] ?? false);
        $v['policyStatus'] = match ($v['state'] ?? 'IDLE') {
            'UNKNOWN_OUTCOME' => 'UNKNOWN_OUTCOME / Recovery erforderlich',
            'ADMIN_RECOVERY_RECONNECT', 'ADMIN_RECOVERY_READS' => 'Read-only Recovery läuft',
            'RECOVERED_WITH_DIFFERENT_APPLIED_VALUE', 'READ_ONLY_RESOLVED' => 'Recovery abgeschlossen / Hardwarezustand sicher bekannt; ursprünglicher Intent bleibt FAIL',
            'READY_FOR_CONFIRMATION' => 'Confirmation erforderlich',
            'PRE_WRITE_JOURNALED' => $v['hardwareWriteEnabled'] ? 'Write technisch freigegeben' : 'Hardware Write gesperrt / Vorbereitung journalisiert',
            'VERIFIED' => 'Hardwarezustand verifiziert',
            default => !$v['stateClassification']['known'] ? 'Unbekannter Zustand / fail-closed gesperrt'
                : ($v['stateClassification']['newTransactionStructurallyAllowed']
                    ? 'Vorbereitung möglich; vollständig neue Sicherheitsprüfung erforderlich'
                    : 'Aktiver Vorgang / neue Transaktion gesperrt'),
        };
        return json_encode($v, JSON_THROW_ON_ERROR);
    }

    private function writeJournal(): \EnOceanGatewayManager\Safety\DurableWriteJournal
    {
        return new \EnOceanGatewayManager\Safety\DurableWriteJournal(rtrim(IPS_GetKernelDir(), '/') . '/egm-write-journal-' . $this->InstanceID);
    }

    private function writeTransaction(): \EnOceanGatewayManager\Safety\TransactionalWrite
    {
        if ($this->transaction === null) {
            $saved = $this->GetBuffer('WriteTransactionRuntime');
            $this->transaction = $saved === '' ? \EnOceanGatewayManager\Safety\TransactionalWrite::restart($this->writeJournal())
                : new \EnOceanGatewayManager\Safety\TransactionalWrite(json_decode($saved, true, 512, JSON_THROW_ON_ERROR));
            $this->saveWriteTransaction();
        }
        return $this->transaction;
    }
    private function saveWriteTransaction(): void
    {
        if ($this->transaction === null) { return; }
        $this->SetBuffer('WriteTransactionRuntime', json_encode($this->transaction->snapshot(), JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('WriteTransactionState', json_encode($this->transaction->view() + ['packageBarrier' => 'B6_HARDWARE_WRITE_BLOCKED'], JSON_THROW_ON_ERROR));
    }
    private function handleWriteControl(array $p): string
    {
        if ($this->ReadAttributeString('WriteJournalFault') !== '') { return $this->result(false, 'journal_fault_requires_manual_review'); }
        $owner = (int) ($p['OwnerInstanceID'] ?? 0);
        $child = $owner > 0 && IPS_InstanceExists($owner) ? IPS_GetInstance($owner) : [];
        if (($child['ConnectionID'] ?? 0) !== $this->InstanceID
            || ($child['ModuleInfo']['ModuleID'] ?? '') !== '{ED8F6F0C-D57F-4E0F-B23B-63CF05AE9643}') { return $this->result(false, 'invalid_write_owner'); }
        $t = $this->writeTransaction(); $journal = $this->writeJournal();
        $context = json_decode($this->readSafetyContextUnlocked(), true);
        $context['ownerRevision'] = $this->ownerRevision($owner);
        try {
            $op = $p['Operation']; $id = (string) ($p['TransactionID'] ?? '');
            if ($op === 'B6_BEGIN') {
                if (!function_exists('EGMM_GetDiagnosticSnapshot')) { throw new RuntimeException('Owner backup cannot be verified.'); }
                $actual = json_decode(EGMM_GetDiagnosticSnapshot($owner), true);
                $backup = json_decode($actual['SavedBaseIDMetadata'] ?? '{}', true) ?: [];
                $backup['baseID'] = $actual['SavedBaseID'] ?? '';
                $source = IPS_GetProperty($owner, 'BaseIDSource');
                $target = match ($source) { 'saved' => $backup['baseID'], 'manual' => IPS_GetProperty($owner, 'ManualBaseID'), default => throw new RuntimeException('Unknown target source.') };
                $target = \EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId($target);
                if ($target !== ($p['Target'] ?? '')) { throw new RuntimeException('Target does not match current owner configuration.'); }
                $t->begin($owner, $target, $context, $backup, time(), $journal);
            }
            elseif ($op === 'B6_AUTHORIZE') { $t->authorize($owner, $id, $journal); }
            elseif ($op === 'B6_CONFIRM') { $t->confirm($owner, $id, (string) ($p['ConfirmationToken'] ?? ''), (string) ($p['Target'] ?? ''), $context, time(), $journal); }
            elseif ($op === 'B6_CHALLENGE') { return json_encode(['accepted' => true, 'token' => $t->confirmationToken($owner, $id), 'transaction' => $t->view()], JSON_THROW_ON_ERROR); }
            elseif ($op === 'B6_ADMIN_RECOVER') { $t->administrativeRecover($owner, $id, $context, time(), $journal); }
            elseif ($op === 'B6_CANCEL' || $op === 'B6_RECOVER') {
                if (($t->snapshot()['owner'] ?? 0) !== $owner || ($t->snapshot()['transactionID'] ?? '') !== $id) { throw new RuntimeException('Wrong transaction owner.'); }
                if ($op === 'B6_RECOVER') { $t->recover($owner, $id, $journal); } else { $t->cancel('Explicit local cancellation.', $journal); }
            } else { return $this->result(false, 'unsupported_write_control'); }
            $this->saveWriteTransaction();
            return json_encode(['accepted' => true, 'transaction' => $t->view()], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            if ($e instanceof \EnOceanGatewayManager\Safety\JournalPersistenceException || $e instanceof JsonException) {
                $this->WriteAttributeString('WriteJournalFault', $e->getMessage());
                throw $e;
            }
            $this->saveWriteTransaction(); return $this->result(false, $e->getMessage());
        }
    }
    private function processWriteTransaction(): void
    {
        if ($this->ReadAttributeString('WriteJournalFault') !== '') { return; }
        $t = $this->writeTransaction(); if (!$t->active()) { return; }
        $context = json_decode($this->readSafetyContextUnlocked(), true); $j = $this->writeJournal();
        $t->observe($context, time(), $j);
        $read = $t->nextRead($context, time(), $j);
        if ($read !== null) {
            $result = $this->core()->enqueueMaintenance(['operation' => $read['operation'], 'ownerInstanceId' => $this->InstanceID,
                'token' => $read['token'], 'frameHex' => \EnOceanGatewayManager\Protocol\ESP3Codec::toHex(\EnOceanGatewayManager\Protocol\ESP3Codec::buildReadRequest($read['operation'])),
                'timeoutMs' => $this->ReadPropertyInteger('MaintenanceTimeoutMs')], $this->nowMs());
            $this->saveWriteTransaction(); // Before a synchronous parent callback.
            if (!$result['accepted']) { $t->unknown('Internal lease read rejected: ' . $result['reason'], $j); }
            else { $this->applyActions($result['actions']); }
        }
        if ($t->snapshot()['state'] === 'PRE_WRITE_JOURNALED' && ($t->snapshot()['packageBarrier'] ?? '') === '') { $this->sendPreparedWrite(); }
        $this->saveWriteTransaction();
    }

    /** THE ONLY CO_WR_IDBASE-capable Parent send site. Cannot be called publicly. */
    private function sendPreparedWrite(): void
    {
        $t = $this->writeTransaction();
        // Revalidate the dynamically generated frame immediately before WAL/send.
        $intent = $t->snapshot();
        \EnOceanGatewayManager\Protocol\ESP3Codec::normalizeWritableBaseId($intent['target']);
        $parsed = \EnOceanGatewayManager\Protocol\ESP3Codec::parseFrame(hex2bin($intent['frameHex'] ?? ''));
        if ($parsed['packetType'] !== 5 || $parsed['dataLength'] !== 5 || $parsed['optionalLength'] !== 0
            || $parsed['data'] !== chr(7) . hex2bin($intent['target']) || strlen(hex2bin($intent['frameHex'])) !== 12) {
            throw new RuntimeException('Final write frame validation failed; no send.');
        }
        $frame = $t->prepareSend(json_decode($this->readSafetyContextUnlocked(), true), time(), self::B6_HARDWARE_WRITE_BARRIER, $this->writeJournal());
        $this->saveWriteTransaction();
        if ($frame === null) { return; } // Immutable B6 barrier is inside prepareSend.
        $audit = json_decode($this->ReadAttributeString('TrafficAudit'), true) ?: [];
        $audit[] = ['at' => gmdate('c'), 'transactionID' => $t->snapshot()['transactionID'], 'frameHex' => $frame, 'status' => 'MAY_HAVE_SENT'];
        // Full planned frame and attempt status were already fsynced in the WAL.
        $this->WriteAttributeString('TrafficAudit', json_encode(array_slice($audit, -256), JSON_THROW_ON_ERROR));
        $this->SetBuffer('WriteResponseParser', '');
        try { $this->SendDataToParent(json_encode(['DataID' => self::SERIAL_TX_DATA_ID, 'Buffer' => $frame], JSON_THROW_ON_ERROR)); }
        catch (Throwable) { $t->unknown('Parent send exception: outcome unknown, no retry.', $this->writeJournal()); $this->core()->requireTransportReconnect(); }
        $this->saveWriteTransaction();
    }
    private function receiveWriteBytes(string $bytes): void
    {
        $encoded = $this->GetBuffer('WriteResponseParser');
        $parser = $encoded === '' ? new \EnOceanGatewayManager\Protocol\ESP3StreamParser()
            : unserialize(base64_decode($encoded), ['allowed_classes' => [\EnOceanGatewayManager\Protocol\ESP3StreamParser::class]]);
        $t = $this->writeTransaction();
        foreach ($parser->feed($bytes) as $event) {
            if ($event['type'] === 'error') { $t->unknown('Write response CRC/framing error.', $this->writeJournal()); break; }
            if (($event['packetType'] ?? 0) !== 2) { continue; } // Radio events are not write responses.
            if ($t->snapshot()['state'] !== 'WAITING_FOR_RESPONSE') { $t->unknown('Unexpected duplicate response.', $this->writeJournal()); break; }
            $t->writeResponse(strtoupper(bin2hex($event['frame'])), time(), $this->writeJournal());
        }
        $this->SetBuffer('WriteResponseParser', base64_encode(serialize($parser)));
        $this->saveWriteTransaction();
    }

    /** Read-only OS inspection; never opens a UART or changes any parent. */
    public function GetReadSafetyContext(): string
    {
        return $this->withInstanceLock(function (): string {
            $this->writeTransaction(); // Resolve actual journal/runtime state before classifying it.
            $context = json_decode($this->readSafetyContextUnlocked(), true);
            $context['writeLeaseActive'] = $this->writeTransaction()->active();
            $context['stateClassification'] = $this->writeTransaction()->classification();
            if ($context['stateClassification']['unresolvedUnknownOutcome']) { $context['noUnknownOutcome'] = false; }
            if ($context['writeLeaseActive']) { $context['correlationSafeAndIdle'] = false; }
            return json_encode($context, JSON_THROW_ON_ERROR);
        });
    }

    private function readSafetyContextUnlocked(): string
    {
            $state = json_decode($this->ReadAttributeString('CoreState'), true) ?: [];
            $binding = $this->transportBinding();
            if ($this->GetBuffer('BindingChangedRequiresReconnect') === '1') { $state['correlationUnsafe'] = true; }
            $io = $binding['serialID'] > 0 ? IPS_GetInstance($binding['serialID']) : [];
            $serial = ($io['ModuleInfo']['ModuleID'] ?? '') === '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}';
            $owners = []; $ownershipKnown = false; $descriptorCount = 0;
            if ($serial && function_exists('IPS_GetProperty') && PHP_OS_FAMILY === 'Linux') {
                $port = (string) IPS_GetProperty($binding['serialID'], 'Port');
                clearstatcache(true);
                $device = @stat($port);
                $processStatus = @file_get_contents('/proc/self/status');
                $rootInspector = is_string($processStatus) && preg_match('/^Uid:\s+0\s+0\s+0\s+0\s*$/m', $processStatus) === 1;
                // Only root can reliably inspect every process, otherwise fail closed.
                if ($device !== false && (($device['mode'] & 0170000) === 0020000)
                    && $rootInspector) {
                    $ownershipKnown = true;
                    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $process) {
                        $fds = @scandir($process . '/fd');
                        if ($fds === false) { clearstatcache(true); if (is_dir($process)) { $ownershipKnown = false; } continue; }
                        foreach ($fds as $fd) {
                            if ($fd === '.' || $fd === '..') { continue; }
                            clearstatcache(true);
                            $info = @stat($process . '/fd/' . $fd);
                            if ($info !== false && ($info['mode'] & 0170000) === 0020000 && $info['rdev'] === $device['rdev']) {
                                $owners[] = (int) basename($process);
                                $descriptorCount++;
                            }
                        }
                    }
                    $owners = array_values(array_unique($owners));
                }
            }
            $idle = !($state['correlationUnsafe'] ?? true) && ($state['activeToken'] ?? null) === null
                && ($state['maintenanceQueueItems'] ?? 1) === 0 && ($state['nativeQueueItems'] ?? 1) === 0
                && ($state['nativeResponseDebt'] ?? 1) === 0 && ($state['incomingBufferedBytes'] ?? 1) === 0
                && ($state['outgoingBufferedBytes'] ?? 1) === 0;
            $owner = $this->transaction?->snapshot()['owner'] ?? (json_decode($this->GetBuffer('WriteTransactionRuntime'), true)['owner'] ?? 0);
            return json_encode($binding + ['arbiterID' => $this->InstanceID,
                'ownerRevision' => $owner > 0 ? $this->ownerRevision($owner) : '',
                'realConnectionActive' => $serial && $this->HasActiveParent() && ($state['connected'] ?? false),
                'correlationSafeAndIdle' => $idle, 'transportCorrelationSafe' => !($state['correlationUnsafe'] ?? true),
                'noUnknownOutcome' => !($state['correlationUnsafe'] ?? true)
                    && !\EnOceanGatewayManager\Safety\TransactionStateModel::classify($this->transaction?->snapshot()['state'] ?? '')['unresolvedUnknownOutcome'],
                'exclusiveUARTOwner' => $ownershipKnown && $owners === [getmypid()] && $descriptorCount === 1,
                'uartDescriptorCount' => $descriptorCount,
                'communicationFaultEpoch' => (int)$this->ReadAttributeString('CommunicationFaultEpoch'),
                'uartOwnershipKnown' => $ownershipKnown, 'uartOwnerPIDs' => $owners], JSON_THROW_ON_ERROR);
    }

    private function ownerRevision(int $owner): string
    {
        $snapshot = function_exists('EGMM_GetDiagnosticSnapshot') ? json_decode(EGMM_GetDiagnosticSnapshot($owner), true) : [];
        return hash('sha256', IPS_GetConfiguration($owner) . ':' . ($snapshot['SavedBaseID'] ?? '') . ':' . ($snapshot['SavedBaseIDMetadata'] ?? ''));
    }

    private function transportBinding(): array
    {
        $serialID = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $config = $serialID > 0 && function_exists('IPS_GetConfiguration') ? IPS_GetConfiguration($serialID) : '';
        $binding = hash('sha256', $this->InstanceID . ':' . $serialID . ':' . $config);
        $connected = $this->HasActiveParent();
        $previous = json_decode($this->GetBuffer('ConnectionBinding'), true) ?: [];
        if (($previous['binding'] ?? '') !== '' && $previous['binding'] !== $binding
            && ($previous['connected'] ?? false) && $connected) {
            $this->SetBuffer('BindingChangedRequiresReconnect', '1');
        }
        if (!$connected) { $this->SetBuffer('BindingChangedRequiresReconnect', ''); }
        if (($previous['binding'] ?? '') !== $binding || ($previous['connected'] ?? null) !== $connected || ($previous['session'] ?? '') === '') {
            $previous = ['binding' => $binding, 'serialID' => $serialID, 'connected' => $connected, 'session' => bin2hex(random_bytes(24))];
            $this->SetBuffer('ConnectionBinding', json_encode($previous, JSON_THROW_ON_ERROR));
        }
        return $previous;
    }

    private function createCore(): ESP3TransportArbiterCore
    {
        return new ESP3TransportArbiterCore(
            max(1, $this->ReadPropertyInteger('MaximumQueueItems')),
            max(1, $this->ReadPropertyInteger('MaximumQueueBytes')),
            min(5000, max(100, $this->ReadPropertyInteger('MaintenanceTimeoutMs'))),
            min(5000, max(0, $this->ReadPropertyInteger('LateResponseGuardMs')))
        );
    }

    private function core(): ESP3TransportArbiterCore
    {
        if ($this->core === null) {
            $encoded = $this->GetBuffer('TransportRuntime');
            if ($encoded !== '') {
                $bytes = base64_decode($encoded, true);
                $restored = $bytes === false ? false : unserialize($bytes, ['allowed_classes' => [
                    ESP3TransportArbiterCore::class,
                    \EnOceanGatewayManager\Protocol\ESP3StreamParser::class,
                ]]);
                if ($restored instanceof ESP3TransportArbiterCore) {
                    $this->core = $restored;
                    $this->core->setMaintenanceEnabled($this->ReadPropertyBoolean('EnableMaintenance'));
                    $this->transportBinding();
                    if ($this->GetBuffer('BindingChangedRequiresReconnect') === '1') { $this->core->requireTransportReconnect(); }
                    return $this->core;
                }
            }
            $record = json_decode($this->ReadAttributeString('RestartRecord'), true) ?? [];
            $this->core = $this->createCore();
            $this->core->setMaintenanceEnabled($this->ReadPropertyBoolean('EnableMaintenance'));
            $this->applyActions($this->core->setConnected($this->HasActiveParent(), $this->nowMs()));
            $restartActions = ESP3TransportArbiterCore::restartRecoveryActions($record);
            if ($encoded !== '' || ($record['active'] ?? null) !== null || ($record['nativeResponseDebt'] ?? 0) > 0
                || ($record['incomingBufferedBytes'] ?? 0) > 0 || ($record['outgoingBufferedBytes'] ?? 0) > 0
                || ($record['correlationUnsafe'] ?? false) || ($record['quarantineUntilMs'] ?? 0) > 0
                || ($record['nativeQueuedItems'] ?? 0) > 0 || ($record['queuedMaintenance'] ?? []) !== []) {
                $this->core->requireTransportReconnect();
                $this->recordDiagnostic('error', 'restart_requires_physical_transport_reconnect');
            }
            $this->applyActions($restartActions);
        }
        return $this->core;
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function applyActions(array $actions): ?string
    {
        $lastParentResult = null;
        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            if ($type === 'send_parent') {
                $bytes = (string) $action['bytes'];
                if (true) { // Ordinary transport remains read-only, independent of B6 barrier.
                    $allowed = false;
                    foreach (\EnOceanGatewayManager\Protocol\ESP3Codec::READ_COMMANDS as $operation => $command) {
                        if ($bytes === \EnOceanGatewayManager\Protocol\ESP3Codec::buildReadRequest($operation)) { $allowed = true; break; }
                    }
                    if (!$allowed) { throw new RuntimeException('Isolated transport rejects non-allowlisted hardware frame.'); }
                }
                $audit = json_decode($this->ReadAttributeString('TrafficAudit'), true) ?: [];
                $audit[] = ['at' => gmdate('c'), 'frameHex' => strtoupper(bin2hex($bytes))];
                $this->WriteAttributeString('TrafficAudit', json_encode(array_slice($audit, -256), JSON_THROW_ON_ERROR));
                $this->persistCoreState();
                try {
                    $lastParentResult = $this->SendDataToParent(json_encode([
                        'DataID' => self::SERIAL_TX_DATA_ID,
                        'Buffer' => strtoupper(bin2hex((string) $action['bytes'])),
                    ], JSON_THROW_ON_ERROR));
                } catch (Throwable $error) {
                    $this->recordDiagnostic('error', 'parent_send_failed: ' . $error->getMessage());
                    if ($this->core !== null) {
                        $recovery = $this->core->disconnect('parent_send_failed', $this->nowMs());
                        $this->applyActions($recovery);
                        $this->core->setConnected($this->HasActiveParent(), $this->nowMs());
                        $this->core->requireTransportReconnect();
                    }
                }
                continue;
            }

            if ($type === 'send_native_child') {
                $this->SendDataToChildren(json_encode([
                    'DataID' => self::SERIAL_RX_DATA_ID,
                    'Buffer' => strtoupper(bin2hex((string) $action['bytes'])),
                ], JSON_THROW_ON_ERROR));
                continue;
            }

            if ($type === 'maintenance_result') {
                $this->persistCoreState();
                if ((int) $action['ownerInstanceId'] === $this->InstanceID && str_starts_with((string) $action['token'], 'b6-')) {
                    $this->writeTransaction()->receive($action['token'], $action['operation'], $action['outcome'], $action['frameHex'],
                        json_decode($this->readSafetyContextUnlocked(), true), time(), $this->writeJournal());
                    $this->saveWriteTransaction(); continue;
                }
                $this->SendDataToChildren(json_encode([
                    'DataID' => self::MAINTENANCE_RESULT_DATA_ID,
                    'OwnerInstanceID' => $action['ownerInstanceId'],
                    'Token' => $action['token'],
                    'Operation' => $action['operation'],
                    'Outcome' => $action['outcome'],
                    'Reason' => $action['reason'],
                    'FrameHex' => $action['frameHex'],
                    'Session' => $this->transportBinding()['session'],
                    'Binding' => $this->transportBinding()['binding'],
                ], JSON_THROW_ON_ERROR));
                continue;
            }

            if ($type === 'diagnostic') {
                $this->recordDiagnostic(
                    (string) ($action['level'] ?? 'info'),
                    (string) ($action['code'] ?? 'diagnostic')
                );
                continue;
            }

            if ($type === 'native_rejected') {
                $this->recordDiagnostic('error', 'native_rejected: ' . ($action['reason'] ?? 'unknown'));
            }
        }

        return $lastParentResult;
    }

    private function persistCoreState(): void
    {
        if ($this->core === null) {
            return;
        }

        $restartRecord = $this->core->restartRecord();
        $state = $this->core->state();
        $state += $this->transportBinding();
        $this->SetBuffer('TransportRuntime', base64_encode(serialize($this->core)));
        $recordJson = json_encode($restartRecord, JSON_THROW_ON_ERROR);
        $stateJson = json_encode($state, JSON_THROW_ON_ERROR);
        if ($this->ReadAttributeString('RestartRecord') !== $recordJson) { $this->WriteAttributeString('RestartRecord', $recordJson); }
        if ($this->ReadAttributeString('CoreState') !== $stateJson) { $this->WriteAttributeString('CoreState', $stateJson); }
        $this->setChangedValue('TransportStatus', !$state['connected'] ? 'DISCONNECTED'
            : ($state['correlationUnsafe'] ? 'QUARANTINE / RECONNECT REQUIRED' : 'CONNECTED'));
        $this->setChangedValue(
            'QueueStatus',
            sprintf(
                'maintenance=%d, native=%d/%d bytes, responseDebt=%d',
                $state['maintenanceQueueItems'],
                $state['nativeQueueItems'],
                $state['nativeQueueBytes'],
                $state['nativeResponseDebt']
            )
        );
    }

    private function setChangedValue(string $ident, string $value): void
    {
        if ($this->GetValue($ident) !== $value) { $this->SetValue($ident, $value); }
    }

    private function recordDiagnostic(string $level, string $message): void
    {
        // Never clear warnings when a subsequent read succeeds or reconnects.
        if (in_array($level,['warning','error'],true)) {
            $epoch=(int)$this->ReadAttributeString('CommunicationFaultEpoch')+1;
            $this->WriteAttributeString('CommunicationFaultEpoch',(string)$epoch);
            $history=json_decode($this->ReadAttributeString('CommunicationFaultHistory'),true)?:[];
            $history[]=['epoch'=>$epoch,'at'=>microtime(true),'level'=>$level,'reason'=>$message];
            $this->WriteAttributeString('CommunicationFaultHistory',json_encode($history,JSON_THROW_ON_ERROR));
        }
        $value = sprintf('%s %s: %s', date(DATE_ATOM), strtoupper($level), $message);
        $this->WriteAttributeString('LastDiagnostic', $value);
        $this->SetValue('LastDiagnostic', $value);
        $this->SendDebug('ESP3 Arbiter', $value, 0);
    }

    private function decodeStrictHexBuffer(mixed $value): ?string
    {
        if (!is_string($value) || (strlen($value) % 2) !== 0 || preg_match('/\A[0-9A-Fa-f]*\z/D', $value) !== 1) {
            return null;
        }
        $decoded = hex2bin($value);
        return $decoded === false ? null : $decoded;
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function containsAction(array $actions, string $type): bool
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    private function result(bool $accepted, string $reason): string
    {
        return json_encode([
            'accepted' => $accepted,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR);
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function withInstanceLock(callable $operation): mixed
    {
        $name = 'EGM_ARB_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($name, 1000)) {
            throw new RuntimeException('Unable to acquire the ESP3 arbiter instance lock.');
        }

        try {
            // Reload shared per-instance runtime for every callback, even when
            // PHP reuses this object. No UART state lives only in class fields.
            $this->core = null;
            $this->transaction = null;
            return $operation();
        } catch (Throwable $error) {
            $this->WriteAttributeString('WriteJournalFault', $error->getMessage());
            if ($this->transaction !== null && $this->transaction->active()) {
                $snapshot = $this->transaction->snapshot();
                $snapshot['state'] = 'UNKNOWN_OUTCOME'; $snapshot['token'] = ''; $snapshot['authorized'] = false;
                $snapshot['readNext'] = ''; $snapshot['pending'] = ''; $snapshot['reason'] = 'Adapter failure: ' . $error->getMessage();
                $this->transaction = new \EnOceanGatewayManager\Safety\TransactionalWrite($snapshot); $this->saveWriteTransaction();
            }
            $this->core?->requireTransportReconnect();
            throw $error;
        } finally {
            $this->persistCoreState();
            IPS_SemaphoreLeave($name);
        }
    }
}
