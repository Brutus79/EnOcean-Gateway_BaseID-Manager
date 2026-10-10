<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ESP3Codec.php';
require_once __DIR__ . '/../libs/BaseIDPreflight.php';
require_once __DIR__ . '/../libs/GatewayWritePreparation.php';
require_once __DIR__ . '/../libs/TransactionStateModel.php';
require_once __DIR__ . '/../libs/ProductModule.php';
require_once __DIR__ . '/../libs/ManagerLifecycle.php';
require_once __DIR__ . '/../libs/C2Module.php';

use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Safety\BaseIDPreflight;
use EnOceanGatewayManager\Safety\GatewayWritePreparation;
use EnOceanGatewayManager\Safety\TransactionStateModel;

final class EnOceanGatewayManager extends IPSModuleStrict
{
    use GatewayProductModule;
    use GatewayManagerLifecycle;
    use GatewayC2Module;
    private const NATIVE_GATEWAY_MODULE_ID = '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}';
    private const MAINTENANCE_REQUEST_DATA_ID = '{F5B497B9-0A7D-4F1A-A830-86B1B85A55D4}';
    private const MAINTENANCE_RESULT_DATA_ID = '{4AE7CA23-1782-4C98-81E2-2BA7FC918C2C}';

    public function Create(): void
    {
        parent::Create();
        foreach(['C2Handoff','C2Session','C2ResultInbox','C2NativeRefresh','C2LastKnown','C2PreviousKnown','C2Review']as$attribute)$this->RegisterAttributeString($attribute,'[]');
        $this->RegisterTimer('C2Timer',1000,'EGMM_ProcessC2Maintenance($_IPS["TARGET"]);');
        $this->RegisterTimer('C2FormTimer',250,'EGMM_ProcessNativeFormUpdates($_IPS["TARGET"]);');
        $this->RegisterMessage($this->InstanceID,FM_CONNECT);
        $this->RegisterMessage($this->InstanceID,FM_DISCONNECT);
        $this->RegisterMessage(0,IPS_KERNELSTARTED);
        $this->RegisterTimer('ConnectionStatusTimer',1000,'EGMM_RefreshConnectionStatus($_IPS["TARGET"]);');

        $this->RegisterPropertyString('GatewayName', 'EnOcean Gateway');
        $this->RegisterPropertyString('ConnectionType', 'serial');
        $this->RegisterPropertyString('ConnectionDevice', '');
        $this->RegisterPropertyString('DeviceChoice', '');
        $this->RegisterPropertyInteger('ConnectionBaud', 57600);
        $this->RegisterPropertyString('NetworkHost', '');
        $this->RegisterPropertyInteger('NetworkPort', 5000);
        $this->RegisterPropertyString('MasterEntry', '');
        $this->RegisterPropertyString('HistoryMasterChoice', '');
        $this->RegisterAttributeString('LogicalGatewayID', '');
        $this->RegisterAttributeString('ProductMessage', 'Gateway prüfen / aktualisieren. Master ändern ist ausschließlich lokal.');
        $this->RegisterAttributeString('DiscoveryCandidates', '[]');
        $this->RegisterAttributeString('LastKnownGatewayDisplay', '{}');
        $this->RegisterTimer('ProductWorkflowTimer', 100, 'EGMM_ProcessProductWorkflow($_IPS["TARGET"]);');

        $this->RegisterPropertyInteger('NativeGatewayInstanceID', 0);
        $this->RegisterPropertyBoolean('EnableReadActions', false);
        $this->RegisterPropertyInteger('ReadTimeoutMs', 500);
        $this->RegisterPropertyString('ManualBaseID', '');
        $this->RegisterPropertyString('BaseIDSource', 'saved');

        $this->RegisterAttributeString('ConfiguredBaseID', 'UNKNOWN');
        $this->RegisterAttributeString('HardwareBaseID', 'NOT_READ');
        $this->RegisterAttributeString('RemainingWriteCycles', 'NOT_READ');
        $this->RegisterAttributeString('GatewayInformation', 'NOT_CONFIGURED');
        $this->RegisterAttributeString('TransportStatus', 'NOT_CONNECTED');
        $this->RegisterAttributeString('LastReadStatus', 'NOT_READ');
        $this->RegisterAttributeString('PendingToken', '');
        $this->RegisterAttributeString('PendingOperation', '');
        $this->RegisterAttributeString('ReadObservations', '{}');
        $this->RegisterAttributeString('SavedBaseID', '');
        $this->RegisterAttributeString('SavedBaseIDMetadata', '{}');
        $this->RegisterAttributeString('BaseIDReadAt', '');
        $this->RegisterAttributeString('BaseIDReadParent', '');
        $this->RegisterAttributeString('BaseIDPreview', 'NOT_PREPARED');
        $this->RegisterAttributeString('LastSuccessfulReadAt', 'Noch keine erfolgreiche Abfrage');
        $this->RegisterAttributeString('WritePreparation', '{}');
        $this->RegisterTimer('WritePreparationTimer', 100, 'EGMM_ProcessWritePreparation($_IPS["TARGET"]);');
        $this->RegisterTimer('TransactionViewTimer', 500, 'EGMM_ProcessTransactionView($_IPS["TARGET"]);');

        $this->RegisterVariableString('ConfiguredBaseID', 'IP-Symcon configured BaseID');
        $this->RegisterVariableString('HardwareBaseID', 'Hardware Base ID');
        $this->RegisterVariableString('RemainingWriteCycles', 'Remaining write cycles');
        $this->RegisterVariableString('GatewayInformation', 'Gateway and version information');
        $this->RegisterVariableString('TransportStatus', 'Transport status');
        $this->RegisterVariableString('LastReadStatus', 'Last read status');
        foreach ($this->informationFields() as $ident => $caption) {
            $this->RegisterAttributeString($ident, 'Nicht ermittelt');
            $this->RegisterVariableString($ident, $caption);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if($this->ReadAttributeString('C2Handoff')!=='[]'){
            $handoff=json_decode($this->ReadAttributeString('C2Handoff'),true)?:[];
            if(!in_array($handoff['phase']??'',['RESTORED','IDLE'],true)){
                $this->c2Fail('Manager ApplyChanges invalidated evidence');
                $this->SetBuffer('C2RuntimeStarted','');$this->SetTimerInterval('C2Timer',100);
            }
        }
        $this->SetBuffer('WritePreparationRuntime', '');
        $this->SetBuffer('ProductFlow', '');
        $this->WriteAttributeString('WritePreparation', '{}');
        $this->SetBuffer('B6ConfirmationChallenge', '');
        $this->WriteAttributeString('BaseIDReadAt', '');
        $this->WriteAttributeString('BaseIDReadParent', '');
        $this->WriteAttributeString('PendingToken', '');
        $this->WriteAttributeString('ReadObservations', '{}');
        $this->setDisplayValue('HardwareBaseID', 'NOT_READ');
        $this->setDisplayValue('RemainingWriteCycles', 'NOT_READ');
        foreach ($this->informationFields() as $ident => $caption) {
            $this->setDisplayValue($ident, 'Nicht ermittelt');
        }
        $this->WriteAttributeString('BaseIDPreview', 'NOT_PREPARED');
        $this->refreshConfiguredGatewayInformation();
        $this->RefreshConnectionStatus();
        // One deferred read-only attempt, through the existing arbiter. Never a retry.
        $this->SetBuffer('ProductInitialRead', $this->ReadPropertyBoolean('EnableReadActions') ? 'requested' : '');
    }

    public function Destroy(): void
    {
        // Destroy may run during library reload. Do not rely on instance
        // attributes/timers being available, and never sleep inside Destroy.
        try{$this->c2Lock(function():void{
            $h=$this->c2Handoff();$h->retire($this->InstanceID,microtime(true));
            if(($h->state()['phase']??'')==='RETURN_CLOSING')$h->finishRestore(microtime(true));
            // Instance numbers can be reused after deletion. Retain history /
            // Master but remove the live binding, so a new instance cannot
            // silently inherit the deleted gateway's logical identity.
            $owner=$this->InstanceID;
            $this->productStore()->update(static function(array&$db)use($owner):void{
                foreach($db['gateways']as&$gateway)if(($gateway['managerInstanceID']??null)===$owner)unset($gateway['managerInstanceID']);
                unset($gateway);
            });
        });}catch(Throwable$e){IPS_LogMessage('EnOcean C2','Destroy: safe return requires review: '.$e->getMessage());}
        finally{parent::Destroy();}
    }

    public function GetCompatibleParents(): string
    {
        // A native gateway reference is configuration, not a transport parent.
        // Normal C2 selection never needs the console's parent-creation assistant.
        if ($this->ReadPropertyInteger('NativeGatewayInstanceID') > 0
            || (int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0)===0) return '{}';
        return '{"type":"connect","moduleIDs":["{C5D65AB1-045B-40ED-B854-3D74D41C81EC}"]}';
    }

    public function GetConfigurationForParent(): string
    {
        $parent=(int)(IPS_GetInstance($this->InstanceID)['ConnectionID']??0);
        if($parent>0&&(IPS_GetInstance($parent)['ModuleInfo']['ModuleID']??'')!=='{C5D65AB1-045B-40ED-B854-3D74D41C81EC}')return '{}';
        if($parent>0)foreach(IPS_GetInstanceList()as$id)if($id!==$this->InstanceID&&(int)(IPS_GetInstance($id)['ConnectionID']??0)===$parent)return '{}';
        // Documented native parent configuration; no imperative foreign-instance mutation.
        return json_encode(['EnableMaintenance'=>true,'IsolatedReadOnly'=>true],JSON_THROW_ON_ERROR);
    }

    public function GetTechnicalConfigurationForm(): string
    {
        $canRead = $this->ReadPropertyBoolean('EnableReadActions') && $this->activeGatewayTransport();
        $informationLabels = [];
        foreach ($this->informationFields() as $ident => $caption) {
            $informationLabels[] = ['type' => 'Label', 'caption' => $caption . ': ' . $this->ReadAttributeString($ident)];
        }
        $parentID = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $serialID = $parentID > 0 ? (int) (IPS_GetInstance($parentID)['ConnectionID'] ?? 0) : 0;
        $io = $serialID > 0 ? IPS_GetInstance($serialID) : [];
        $isSerial = ($io['ModuleInfo']['ModuleID'] ?? '') === '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}';
        $port = $isSerial && function_exists('IPS_GetProperty') ? (string) IPS_GetProperty($serialID, 'Port')
            : 'Kein nachgewiesener serieller Parent / Anschluss unbekannt';
        $state = $parentID > 0 && function_exists('EGMA_GetTransportState') ? json_decode(EGMA_GetTransportState($parentID), true) : [];
        $connection = !$this->activeGatewayTransport() ? 'Keine exklusive aktive Gatewayverbindung'
            : (($state['correlationUnsafe'] ?? false) ? 'Quarantäne – seriellen Port bewusst schließen und wieder öffnen'
                : (($state['connected'] ?? true) ? 'Verbunden' : 'Serieller Port getrennt'));
        $canRead = $canRead && !($state['correlationUnsafe'] ?? false) && ($state['connected'] ?? true)
            && $this->ReadAttributeString('PendingToken') === '' && !$this->writeLeaseActive();
        $metadata = json_decode($this->ReadAttributeString('SavedBaseIDMetadata'), true) ?: [];
        $previewValue = $this->ReadAttributeString('BaseIDPreview');
        $preview = json_decode($previewValue, true);
        $previewLabels = [['type' => 'Label', 'caption' => 'Status: ' . (is_array($preview) ? ($preview['state'] ?? 'Unbekannt') : $previewValue)]];
        $writePreparation = $this->currentWritePreparation();
        $writeLabels = [];
        foreach (['state' => 'Write', 'currentBaseID' => 'Aktuelle Base-ID', 'requestedBaseID' => 'Neue Base-ID',
            'remaining' => 'Aktuelle Write Cycles', 'expectedRemaining' => 'Erwarteter Zähler', 'eurid' => 'EURID',
            'backupStatus' => 'Sicherung', 'hardwareIdentity' => 'Hardwareidentität', 'evaluatedAt' => 'Prüfung (UTC)'] as $key => $label) {
            $writeLabels[] = ['type' => 'Label', 'caption' => $label . ': ' . ($writePreparation[$key] ?? 'Nicht vorbereitet')];
        }
        foreach ($writePreparation['gates'] ?? [] as $gate => $passed) {
            $writeLabels[] = ['type' => 'Label', 'caption' => ($passed ? 'PASS: ' : 'GESPERRT: ') . $gate];
        }
        $writeLabels[] = ['type' => 'Label', 'caption' => 'Momentaufnahme, maximal 60 Sekunden gültig; kein Hardware-Write möglich. Nach Reconnect neu prüfen und Sicherungsidentität erneut bestätigen.'];
        $transaction = $parentID > 0 && function_exists('EGMA_GetWriteTransactionView') ? json_decode(EGMA_GetWriteTransactionView($parentID), true) : [];
        $transaction = is_array($transaction) ? $transaction : [];
        $classification = TransactionStateModel::classify($transaction['state'] ?? '', (bool) ($transaction['permanentFailure'] ?? false));
        $challenge = json_decode($this->GetBuffer('B6ConfirmationChallenge'), true) ?: [];
        $txID = $transaction['transactionID'] ?? '';
        $isOwner = ($transaction['owner'] ?? 0) === $this->InstanceID;
        $ready = $isOwner && ($transaction['state'] ?? '') === 'READY_FOR_CONFIRMATION' && time() <= ($transaction['expiresAt'] ?? 0);
        $txItems = [['type' => 'Label', 'caption' => ($transaction['hardwareWriteBarrier'] ?? true)
                ? 'Hardware Write gesperrt – aktive Arbiter-Policy.' : 'Produktiver Schreibpfad – alle Sicherheitsgates weiterhin erforderlich.', 'bold' => true],
            ['type' => 'Label', 'caption' => 'Policy: ' . ($transaction['policyStatus'] ?? 'Unbekannt / gesperrt')],
            ['type' => 'Label', 'caption' => 'Zustand: ' . ($transaction['state'] ?? 'Unbekannt')],
            ['type' => 'Label', 'caption' => $classification['leaseActive'] ? 'Write-Lease aktiv / neue Transaktion gesperrt'
                : ($classification['completedRecovery'] ? 'Hardwarezustand sicher bekannt; letzte Recovery abgeschlossen. Keine aktive Write-Lease.' : 'Keine aktive Write-Lease.')],
            ['type' => 'Label', 'caption' => $classification['newTransactionStructurallyAllowed']
                ? 'Eine neue unabhängige Änderung kann vorbereitet werden; vollständig neue Sicherheitsprüfung und beide Bestätigungen erforderlich.'
                : 'Keine neue Write-Transaktion zulässig; aktiven Vorgang oder Recovery sicher abschließen.'],
            ['type' => 'Label', 'caption' => 'Transaktion: ' . $txID],
            ['type' => 'Label', 'caption' => 'Diagnose: ' . ($transaction['reason'] ?? 'Keine Fehlermeldung')],
            ['type' => 'Label', 'caption' => 'Bestätigung gültig bis (UTC): ' . (isset($transaction['expiresAt']) ? gmdate('c', $transaction['expiresAt']) : '–')],
            ['type' => 'Label', 'caption' => 'EURID: ' . ($transaction['eurid'] ?? 'Noch nicht frisch gelesen')],
            ['type' => 'Label', 'caption' => 'Base-ID: ' . ($transaction['preview']['currentBaseID'] ?? '–') . ' → ' . ($transaction['target'] ?? '–')],
            ['type' => 'Label', 'caption' => 'Zähler: ' . ($transaction['preview']['remaining'] ?? '–') . ' → ' . ($transaction['preview']['expectedRemaining'] ?? '–')],
            ['type' => 'Label', 'caption' => 'Sicherung: ' . ($transaction['backupStatus'] ?? 'Ungeprüft') . '; Parent #' . ($transaction['parentID'] ?? 0) . '; Fingerprint: ' . ($transaction['binding'] ?? '–')],
            ['type' => 'Label', 'caption' => 'Bei Write kann ein Schreibzyklus verbraucht werden. Bei Timeout: UNKNOWN_OUTCOME, niemals automatische Wiederholung. Aktive Policy siehe oben.'],
            ['type' => 'Label', 'caption' => 'FORCE_RECONNECT: seriellen Port bewusst schließen und wieder öffnen; Lease behalten. Danach ausschließlich Read-only-Postverification.'],
            ['type' => 'Button', 'caption' => 'Exklusive Transaktion vorbereiten (zunächst nur Reads)', 'enabled' => $canRead && $classification['newTransactionStructurallyAllowed'], 'onClick' => 'EGMM_BeginWriteTransaction($id);'],
            ['type' => 'Button', 'caption' => 'Stufe A: lokale Hardwarefreigabe für diese Transaktion', 'enabled' => $ready && !($transaction['authorized'] ?? false), 'onClick' => 'EGMM_AuthorizeWriteTransaction($id, ' . json_encode($txID) . ');'],
            ['type' => 'Button', 'caption' => 'Stufe B: genau diese Änderung bestätigen – aktive Policy beachten',
                'enabled' => $ready && ($transaction['authorized'] ?? false) && ($challenge['transaction']['transactionID'] ?? '') === $txID,
                'onClick' => 'EGMM_ConfirmWriteTransaction($id, ' . json_encode($txID) . ', ' . json_encode($challenge['token'] ?? '') . ', ' . json_encode($transaction['target'] ?? '') . ');'],
            ['type' => 'Button', 'caption' => 'Nicht gesendete Vorbereitung abbrechen', 'enabled' => $isOwner && in_array($transaction['state'] ?? '', ['PREFLIGHT', 'READY_FOR_CONFIRMATION', 'CONFIRMED', 'PRE_WRITE_JOURNALED'], true), 'onClick' => 'EGMM_CancelWriteTransaction($id, ' . json_encode($txID) . ');'],
            ['type' => 'Button', 'caption' => 'UNKNOWN: reguläre Read-only-Recovery, kein Retry', 'enabled' => $isOwner && ($transaction['state'] ?? '') === 'UNKNOWN_OUTCOME' && !($transaction['permanentFailure'] ?? false), 'onClick' => 'EGMM_RecoverWriteTransaction($id, ' . json_encode($txID) . ');'],
            ['type' => 'Button', 'caption' => 'Administrative Read-only-Recovery bewusst starten; ursprünglichen FAIL erhalten', 'enabled' => $isOwner && ($transaction['state'] ?? '') === 'UNKNOWN_OUTCOME', 'onClick' => 'EGMM_AdministrativeReadOnlyRecovery($id, ' . json_encode($txID) . ');'],
            ['type' => 'Label', 'caption' => 'Recovery-Ergebnis: ' . json_encode($transaction['recoveryResult'] ?? [], JSON_UNESCAPED_SLASHES)],
        ];
        if (is_array($preview)) {
            foreach (['currentBaseID' => 'Aktuelle Hardware-Base-ID', 'requestedBaseID' => 'Gewünschte Base-ID',
                'remaining' => 'Aktuell verbleibende Zyklen', 'expectedRemaining' => 'Erwartete Zyklen nach Änderung',
                'minimumRemaining' => 'Mindestreserve', 'readAt' => 'Frischer Hardware-Read (UTC)'] as $key => $caption) {
                if (isset($preview[$key])) { $previewLabels[] = ['type' => 'Label', 'caption' => $caption . ': ' . $preview[$key]]; }
            }
            if ($preview['noChange'] ?? false) { $previewLabels[] = ['type' => 'Label', 'caption' => 'Keine Änderung nötig; kein Schreibzyklus würde verbraucht.']; }
        }

        return json_encode([
            '$schema' => 'https://www.symcon.de/assets/files/validation/formSchema.json',
            'elements' => [
                [
                    'type' => 'SelectInstance',
                    'name' => 'NativeGatewayInstanceID',
                    'caption' => 'Optional: kopiertes natives Gateway (nur Konfigurationsanzeige, keine Verbindung)',
                    'validModules' => [self::NATIVE_GATEWAY_MODULE_ID],
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'EnableReadActions',
                    'caption' => 'Manuelle Read-only-Abfragen erlauben',
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'ReadTimeoutMs',
                    'caption' => 'Antwort-Timeout',
                    'minimum' => 100,
                    'maximum' => 5000,
                    'suffix' => ' ms',
                ],
                ['type' => 'ValidationTextBox', 'name' => 'ManualBaseID', 'caption' => 'Manuelle Base-ID (8 Hex-Zeichen, FF800000…FFFFFF80)'],
                ['type' => 'Select', 'name' => 'BaseIDSource', 'caption' => 'Quelle für die Schreibvorschau', 'options' => [
                    ['caption' => 'Gesicherte Base-ID verwenden', 'value' => 'saved'],
                    ['caption' => 'Manuelle Base-ID verwenden', 'value' => 'manual'],
                ]],
            ],
            'actions' => [
                ['type' => 'ExpansionPanel', 'caption' => 'B6 Transaktion / Zwei-Stufen-Bestätigung', 'expanded' => true, 'items' => $txItems],
                ['type' => 'Label', 'caption' => 'Isolierter Read-only-Betrieb – keine Hardware-Schreibfunktion', 'bold' => true],
                ['type' => 'ExpansionPanel', 'caption' => 'Gateway / Verbindung', 'expanded' => true, 'items' => [
                    ['type' => 'Label', 'caption' => 'Kommunikationsstatus: ' . $connection],
                    ['type' => 'Label', 'caption' => 'Arbiter #' . $parentID . ' → Serial #' . $serialID . ': ' . $port],
                    ['type' => 'Label', 'caption' => 'Letzte erfolgreiche Hardwareabfrage (UTC): ' . $this->ReadAttributeString('LastSuccessfulReadAt')],
                    ['type' => 'Label', 'caption' => 'Letztes Ergebnis: ' . $this->ReadAttributeString('LastReadStatus')],
                ]],
                ['type' => 'Label', 'caption' => 'IP-Symcon configured BaseID: ' . $this->ReadAttributeString('ConfiguredBaseID')],
                ['type' => 'Label', 'caption' => 'Hardware Base ID: ' . $this->ReadAttributeString('HardwareBaseID')],
                ['type' => 'Label', 'caption' => 'Remaining Write Cycles: ' . $this->ReadAttributeString('RemainingWriteCycles')],
                ['type' => 'ExpansionPanel', 'caption' => 'Lokale Base-ID-Sicherung', 'expanded' => true, 'items' => [
                    ['type' => 'Label', 'caption' => 'Gesicherte Base-ID: ' . ($this->ReadAttributeString('SavedBaseID') ?: 'Nicht gesichert')],
                    ['type' => 'Label', 'caption' => 'Sicherungszeitpunkt (UTC): ' . ($metadata['savedAt'] ?? '–')],
                    ['type' => 'Label', 'caption' => 'Zugehörige EURID: ' . ($metadata['observedEURID'] ?? 'Nicht ermittelt')],
                    ['type' => 'Label', 'caption' => 'Vor dem Sichern Version/EURID und Base-ID innerhalb von 60 Sekunden frisch lesen. Löschen betrifft nur diese Modulsicherung.'],
                    ['type' => 'Button', 'caption' => 'Aktuelle Base-ID sichern', 'enabled' => $canRead, 'onClick' => 'EGMM_SaveCurrentBaseID($id);'],
                    ['type' => 'Button', 'caption' => 'Gesicherte Base-ID löschen', 'onClick' => 'EGMM_DeleteSavedBaseID($id);'],
                    ['type' => 'Button', 'caption' => 'Identität der Sicherung frisch bestätigen (nur lokal)', 'enabled' => $canRead, 'onClick' => 'EGMM_ConfirmSavedBackupIdentity($id);'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Gateway- und Chipinformationen', 'items' => $informationLabels],
                ['type' => 'ExpansionPanel', 'caption' => 'Lesekommandos / Capability-Nachweise', 'items' => [
                    ['type' => 'Label', 'caption' => $this->capabilitySummary()],
                ]],
                ['type' => 'Label', 'caption' => 'Generation / regulatorische Region: unbekannt, sofern nicht separat zuverlässig belegt.'],
                ['type' => 'Label', 'caption' => 'TCM3xx/TCM4xx: maximal zehn Base-ID-Änderungen; Grenze nicht zurücksetzbar. Andere Geräte: Hardwarezähler maßgeblich, FF = unbegrenzt.'],
                ['type' => 'Button', 'caption' => 'Version / EURID lesen', 'enabled' => $canRead, 'onClick' => 'EGMM_ReadGatewayInformation($id);'],
                ['type' => 'Button', 'caption' => 'Repeater-Zustand lesen', 'enabled' => $canRead, 'onClick' => 'EGMM_ReadRepeater($id);'],
                ['type' => 'Button', 'caption' => 'Filter lesen', 'enabled' => $canRead, 'onClick' => 'EGMM_ReadFilters($id);'],
                ['type' => 'Button', 'caption' => 'Frequenz / Protokoll lesen', 'enabled' => $canRead, 'onClick' => 'EGMM_ReadFrequency($id);'],
                ['type' => 'Button', 'caption' => 'Hardware-Stepcode lesen', 'enabled' => $canRead, 'onClick' => 'EGMM_ReadStepCode($id);'],
                ['type' => 'ExpansionPanel', 'caption' => 'Base-ID-Schreibbereich – nur Vorbereitung', 'items' => [
                    ['type' => 'Label', 'caption' => 'Validierung vor Hardwarekommunikation; frischer CO_RD_IDBASE; mindestens 5 Zyklen Reserve.'],
                    ['type' => 'Button', 'caption' => 'Gewählte Quelle prüfen / frischen Hardwarezähler lesen', 'enabled' => $canRead, 'onClick' => 'EGMM_PrepareSelectedBaseIDPreview($id);'],
                    ['type' => 'Button', 'caption' => 'B5 Dry-Run: EURID, Base-ID und Zähler neu prüfen', 'enabled' => $canRead, 'onClick' => 'EGMM_BeginWritePreparation($id);'],
                    ['type' => 'ExpansionPanel', 'caption' => 'B5 Identitäts- und Sicherheitsprüfung', 'expanded' => true, 'items' => $writeLabels],
                    ['type' => 'ExpansionPanel', 'caption' => 'Geprüfte Vorschau', 'expanded' => true, 'items' => $previewLabels],
                    ['type' => 'Button', 'caption' => 'Base-ID auf Gateway schreiben – GESPERRT', 'enabled' => false],
                ]],
                ['type' => 'Label', 'caption' => 'Repeater: Beim TCM310 laut EnOcean RAM-basiert, Verlust bei Reset/Spannungsverlust möglich. Nicht pauschal auf andere Gateways übertragen.'],
                ['type' => 'Button', 'caption' => 'Repeater ändern – GESPERRT', 'enabled' => false],
                [
                    'type' => 'Button',
                    'caption' => 'Aktuelle Base-ID / Hardwarezähler lesen (CO_RD_IDBASE)',
                    'enabled' => $canRead,
                    'onClick' => 'EGMM_ReadHardwareBaseID($id);',
                ],
                [
                    'type' => 'Label',
                    'caption' => 'CO_WR_IDBASE is not exposed by this module.',
                    'bold' => true,
                    'color' => 0xC00000,
                ],
                ['type' => 'TestCenter'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Gateway-Verbindung aktiv'],
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'Gateway nicht verbunden – Schnittstelle einrichten'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function ReadHardwareBaseID(): bool
    {
        return $this->requestRead('CO_RD_IDBASE');
    }

    public function ReadGatewayInformation(): bool { return $this->requestRead('CO_RD_VERSION'); }
    public function ReadRepeater(): bool { return $this->requestRead('CO_RD_REPEATER'); }
    public function ReadFilters(): bool { return $this->requestRead('CO_RD_FILTER'); }
    public function ReadFrequency(): bool { return $this->requestRead('CO_GET_FREQUENCY_INFO'); }
    public function ReadStepCode(): bool { return $this->requestRead('CO_GET_STEPCODE'); }
    public function PrepareSelectedBaseIDPreview(): bool { return $this->PrepareBaseIDPreview($this->ReadPropertyString('BaseIDSource')); }

    public function GetDiagnosticSnapshot(): string
    {
        $result = [];
        foreach (array_merge(['HardwareBaseID', 'RemainingWriteCycles', 'LastReadStatus', 'PendingToken',
            'ReadObservations', 'SavedBaseID', 'SavedBaseIDMetadata', 'BaseIDPreview', 'WritePreparation', 'LastSuccessfulReadAt'],
            array_keys($this->informationFields())) as $name) {
            $result[$name] = $name === 'WritePreparation' ? json_encode($this->currentWritePreparation(), JSON_THROW_ON_ERROR) : $this->ReadAttributeString($name);
        }
        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    private function currentWritePreparation(): array
    {
        $preview = json_decode($this->ReadAttributeString('WritePreparation'), true) ?: [];
        if (!isset($preview['evaluatedAt'])) { return $preview; }
        $at = strtotime($preview['evaluatedAt']) ?: 0;
        $parent = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        // Attribute-only getter: safe even if the form is refreshed from RX.
        $transport = $parent > 0 && function_exists('EGMA_GetTransportState') ? json_decode(EGMA_GetTransportState($parent), true) : [];
        if (time() < $at || time() - $at > 60 || ($transport['session'] ?? '') !== ($preview['session'] ?? '')
            || ($transport['binding'] ?? '') !== ($preview['binding'] ?? '') || !($transport['connected'] ?? false)
            || ($transport['correlationUnsafe'] ?? true)) {
            $preview['readyForFutureAuthorization'] = false;
            $preview['hardwareWriteEnabled'] = false;
            $preview['state'] = 'GESPERRT – Vorschau abgelaufen / Transport geändert';
            foreach (['freshBaseID', 'freshWriteCycles', 'freshEURID', 'confirmedBackupEURIDMatches'] as $gate) { $preview['gates'][$gate] = false; }
            $preview['hardwareIdentity'] = 'erneut prüfen';
        }
        return $preview;
    }

    public function PrepareBaseIDPreview(string $source): bool
    {
        // Validate the entire input before any request reaches the arbiter.
        try {
            $requested = match ($source) {
                'manual' => $this->ReadPropertyString('ManualBaseID'),
                'saved' => $this->ReadAttributeString('SavedBaseID'),
                default => throw new ValueError('Unknown Base-ID source.'),
            };
            $requested = ESP3Codec::normalizeWritableBaseId($requested);
        } catch (Throwable $error) {
            $this->WriteAttributeString('BaseIDPreview', 'REJECTED: ' . $error->getMessage());
            $this->ReloadForm();
            return false;
        }
        if ($this->ReadAttributeString('PendingToken') !== '') { return false; }
        $this->WriteAttributeString('BaseIDPreview', json_encode(['requestedBaseID' => $requested, 'source' => $source, 'state' => 'AWAITING_FRESH_READ'], JSON_THROW_ON_ERROR));
        if (!$this->requestRead('CO_RD_IDBASE')) {
            $this->WriteAttributeString('BaseIDPreview', 'REJECTED: fresh read could not be started');
            return false;
        }
        return true;
    }

    public function SaveCurrentBaseID(): bool
    {
        if($this->ReadPropertyInteger('NativeGatewayInstanceID')>0){
            try{return$this->c2Lock(fn():bool=>$this->c2SaveBackup());}
            catch(Throwable$e){$this->productMessage('Sicherung abgelehnt: '.$e->getMessage(),true);return false;}
        }
        $parent = (string) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($this->ReadAttributeString('PendingToken') !== '' || !$this->activeGatewayTransport()
            || $this->ReadAttributeString('BaseIDReadAt') === ''
            || time() - (int) strtotime($this->ReadAttributeString('BaseIDReadAt')) > 60
            || $this->ReadAttributeString('BaseIDReadParent') !== $parent) {
            $this->setDisplayValue('LastReadStatus', 'Sicherung abgelehnt: Base-ID frisch lesen (maximal 60 Sekunden, gleicher aktiver Parent, keine laufende Abfrage).');
            $this->ReloadForm();
            return false;
        }
        $baseId = $this->ReadAttributeString('HardwareBaseID');
        try { $baseId = ESP3Codec::normalizeWritableBaseId($baseId); } catch (Throwable) { return false; }
        $observations = json_decode($this->ReadAttributeString('ReadObservations'), true) ?? [];
        $version = $observations['CO_RD_VERSION'] ?? [];
        $context = $this->readSafetyContext();
        $idObservation = $observations['CO_RD_IDBASE'] ?? [];
        if (!($context['correlationSafeAndIdle'] ?? false) || ($context['session'] ?? '') === ''
            || ($version['session'] ?? '') !== $context['session'] || ($idObservation['session'] ?? '') !== $context['session']
            || ($version['binding'] ?? '') !== ($context['binding'] ?? null) || ($idObservation['binding'] ?? '') !== ($context['binding'] ?? null)) { return false; }
        if (($version['capability'] ?? '') !== 'SUPPORTED_READ'
            || ($version['parentInstanceID'] ?? '') !== $parent
            || time() - (int) strtotime($version['readAt'] ?? '') > 60) {
            $this->setDisplayValue('LastReadStatus', 'Sicherung abgelehnt: Version/EURID und Base-ID am selben Parent innerhalb von 60 Sekunden neu lesen.');
            $this->ReloadForm();
            return false;
        }
        $this->WriteAttributeString('SavedBaseID', $baseId);
        $this->WriteAttributeString('SavedBaseIDMetadata', json_encode([
            'savedAt' => gmdate('c'), 'readAt' => $this->ReadAttributeString('BaseIDReadAt'),
            'parentInstanceID' => $parent,
            'observedEURID' => $observations['CO_RD_VERSION']['values']['eurid'] ?? null,
            'euridReadAt' => $observations['CO_RD_VERSION']['readAt'] ?? null,
            'source' => 'CO_RD_IDBASE',
            'binding' => $context['binding'],
        ], JSON_THROW_ON_ERROR));
        $this->ReloadForm();
        return true;
    }

    public function DeleteSavedBaseID(): void
    {
        if ($this->writeLeaseActive()) { return; }
        $this->WriteAttributeString('SavedBaseID', '');
        $this->WriteAttributeString('SavedBaseIDMetadata', '{}');
        $this->WriteAttributeString('BaseIDPreview', 'NOT_PREPARED');
        $this->WriteAttributeString('WritePreparation', '{}');
        if($this->ReadPropertyInteger('NativeGatewayInstanceID')>0)$this->productMessage('Lokale Sicherung gelöscht. Gateway unverändert.',true);
        else $this->ReloadForm();
    }

    private function writeLeaseActive(): bool
    {
        return json_decode($this->GetTransactionStateClassification(), true)['leaseActive'];
    }
    public function GetTransactionStateClassification(): string
    {
        $parent = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $state = $parent > 0 && function_exists('EGMA_GetWriteTransactionView') ? json_decode(EGMA_GetWriteTransactionView($parent), true) : [];
        return json_encode(TransactionStateModel::classify($state['state'] ?? '', (bool) ($state['permanentFailure'] ?? false)), JSON_THROW_ON_ERROR);
    }
    private function writeControl(string $operation, array $fields = []): array
    {
        if($this->ReadPropertyInteger('NativeGatewayInstanceID')>0)return['accepted'=>false,'reason'=>'C2 physical write preparation remains blocked in this build.'];
        if (!$this->activeGatewayTransport() || !$this->ReadPropertyBoolean('EnableReadActions') || $this->ReadAttributeString('PendingToken') !== '') { return ['accepted' => false]; }
        $result = json_decode($this->SendDataToParent(json_encode(['DataID' => self::MAINTENANCE_REQUEST_DATA_ID,
            'OwnerInstanceID' => $this->InstanceID, 'Operation' => $operation] + $fields, JSON_THROW_ON_ERROR)), true) ?: [];
        return $result;
    }
    public function BeginWriteTransaction(): bool
    {
        try { $target = ESP3Codec::normalizeWritableBaseId($this->ReadPropertyString('BaseIDSource') === 'saved'
            ? $this->ReadAttributeString('SavedBaseID') : $this->ReadPropertyString('ManualBaseID')); }
        catch (Throwable $e) { $this->setDisplayValue('LastReadStatus', $e->getMessage()); $this->ReloadForm(); return false; }
        if ($this->writeLeaseActive()) { return false; }
        $backup = json_decode($this->ReadAttributeString('SavedBaseIDMetadata'), true) ?: [];
        $backup['baseID'] = $this->ReadAttributeString('SavedBaseID');
        return ($this->writeControl('B6_BEGIN', ['Target' => $target, 'Backup' => $backup])['accepted'] ?? false) === true;
    }
    public function AuthorizeWriteTransaction(string $transactionID): bool
    {
        return ($this->writeControl('B6_AUTHORIZE', ['TransactionID' => $transactionID])['accepted'] ?? false) === true;
    }
    public function ConfirmWriteTransaction(string $transactionID, string $token, string $target): bool
    {
        try { ESP3Codec::normalizeWritableBaseId($target); }
        catch (Throwable $e) { $this->setDisplayValue('LastReadStatus', $e->getMessage()); return false; }
        return ($this->writeControl('B6_CONFIRM', ['TransactionID' => $transactionID, 'ConfirmationToken' => $token, 'Target' => $target])['accepted'] ?? false) === true;
    }
    public function CancelWriteTransaction(string $transactionID): bool
    {
        return ($this->writeControl('B6_CANCEL', ['TransactionID' => $transactionID])['accepted'] ?? false) === true;
    }
    public function RecoverWriteTransaction(string $transactionID): bool
    {
        return ($this->writeControl('B6_RECOVER', ['TransactionID' => $transactionID])['accepted'] ?? false) === true;
    }
    public function AdministrativeReadOnlyRecovery(string $transactionID): bool
    {
        $result = $this->writeControl('B6_ADMIN_RECOVER', ['TransactionID' => $transactionID]);
        if (!($result['accepted'] ?? false)) { $this->setDisplayValue('LastReadStatus', 'RECOVERY REJECTED: ' . ($result['reason'] ?? 'Owner/read-action gate denied')); }
        return ($result['accepted'] ?? false) === true;
    }
    public function ProcessTransactionView(): void
    {
        $parent = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($parent <= 0 || !function_exists('EGMA_GetWriteTransactionView')) { return; }
        $view = EGMA_GetWriteTransactionView($parent);
        if ($view === $this->GetBuffer('B6LastView')) { return; }
        $this->SetBuffer('B6LastView', $view);
        $state = json_decode($view, true) ?: [];
        $this->synchronizeTransactionReadProofs($state, $parent);
        $this->SetBuffer('B6ConfirmationChallenge', '');
        if (($state['owner'] ?? 0) === $this->InstanceID && ($state['state'] ?? '') === 'READY_FOR_CONFIRMATION') {
            $challenge = $this->writeControl('B6_CHALLENGE', ['TransactionID' => $state['transactionID']]);
            if ($challenge['accepted'] ?? false) { $this->SetBuffer('B6ConfirmationChallenge', json_encode($challenge, JSON_THROW_ON_ERROR)); }
        }
        $this->refreshManagerForm();
    }

    /** Import actual arbiter read proofs, never a preview/backup/expected value. No I/O. */
    private function synchronizeTransactionReadProofs(array $v, int $parent): void
    {
        if (($v['owner'] ?? 0) !== $this->InstanceID || ($v['parentID'] ?? 0) !== $parent
            || !in_array($v['state'] ?? '', ['VERIFIED', 'RECOVERY_NOT_APPLIED', 'POST_VERIFY', 'RECOVERED_WITH_DIFFERENT_APPLIED_VALUE', 'READ_ONLY_RESOLVED'], true)) { return; }
        $transport = json_decode(EGMA_GetTransportState($parent), true) ?: [];
        if (!($transport['connected'] ?? false) || ($transport['correlationUnsafe'] ?? true)) { return; }
        $reads = $v['reads'] ?? []; $version = $reads['CO_RD_VERSION'] ?? []; $base = $reads['CO_RD_IDBASE'] ?? [];
        foreach ([$version, $base] as $r) {
            if (($r['values']['returnName'] ?? '') !== 'RET_OK' || time() - ($r['at'] ?? 0) > 60 || ($r['at'] ?? PHP_INT_MAX) > time()
                || ($r['session'] ?? '') !== ($transport['session'] ?? null) || ($r['binding'] ?? '') !== ($transport['binding'] ?? null)) { return; }
        }
        $observations = json_decode($this->ReadAttributeString('ReadObservations'), true) ?: [];
        foreach ($reads as $op => $r) {
            $observations[$op] = ['readAt' => gmdate('c', $r['at']), 'parentInstanceID' => (string) $parent,
                'session' => $r['session'], 'binding' => $r['binding'], 'capability' => 'SUPPORTED_READ',
                'proofSource' => 'ARBITER_TRANSACTION_READ', 'transactionID' => $v['transactionID'], 'values' => $r['values']];
            $this->updateInformationDisplay($op, $r['values']);
        }
        $this->WriteAttributeString('ReadObservations', json_encode($observations, JSON_THROW_ON_ERROR));
        $id = $base['values'];
        $this->setDisplayValue('HardwareBaseID', $id['baseIdRawHex']);
        $this->setDisplayValue('RemainingWriteCycles', $id['remainingWriteCyclesMode'] === 'unlimited' ? 'UNLIMITED (raw FF)' : (string) $id['remainingWriteCycles']);
        $this->WriteAttributeString('BaseIDReadAt', gmdate('c', $base['at']));
        $this->WriteAttributeString('BaseIDReadParent', (string) $parent);
        $this->WriteAttributeString('LastSuccessfulReadAt', gmdate('c', $base['at']));
        $this->setDisplayValue('LastReadStatus', 'PASS: actual arbiter read proofs synchronized; ' . ($v['policyStatus'] ?? $v['state']));
    }

    private function readSafetyContext(): array
    {
        $parent = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($parent <= 0 || !function_exists('EGMA_GetReadSafetyContext')) { return []; }
        return json_decode(EGMA_GetReadSafetyContext($parent), true) ?: [];
    }

    /** Starts only documented reads. The timer never sends a write. */
    public function BeginWritePreparation(): bool
    {
        $lock = 'EGM_PREP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) { return false; }
        try {
            if ($this->ReadAttributeString('PendingToken') !== '' || $this->GetBuffer('WritePreparationRuntime') !== '') { return false; }
            try {
                $target = ESP3Codec::normalizeWritableBaseId(match ($this->ReadPropertyString('BaseIDSource')) {
                    'saved' => $this->ReadAttributeString('SavedBaseID'),
                    'manual' => $this->ReadPropertyString('ManualBaseID'),
                    default => throw new ValueError('Unknown Base-ID source.'),
                });
            } catch (Throwable $error) {
                $this->WriteAttributeString('WritePreparation', json_encode(['state' => 'REJECTED', 'reason' => $error->getMessage(), 'hardwareWriteEnabled' => false], JSON_THROW_ON_ERROR));
                $this->ReloadForm(); return false;
            }
            $context = $this->readSafetyContext();
            if (!($context['realConnectionActive'] ?? false) || !($context['correlationSafeAndIdle'] ?? false)) { return false; }
            $this->SetBuffer('WritePreparationRuntime', json_encode(['target' => $target, 'session' => $context['session'], 'phase' => 'VERSION_PENDING'], JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('WritePreparation', json_encode(['state' => 'AWAITING_FRESH_VERSION', 'hardwareWriteEnabled' => false], JSON_THROW_ON_ERROR));
            if (!$this->requestRead('CO_RD_VERSION', true)) { $this->SetBuffer('WritePreparationRuntime', ''); return false; }
            return true;
        } finally { IPS_SemaphoreLeave($lock); }
    }

    /** Deferred callback avoids a reentrant SendDataToParent under the arbiter RX lock. */
    public function ProcessWritePreparation(): void
    {
        if ($this->GetBuffer('WritePreparationRuntime') === '') { return; }
        $lock = 'EGM_PREP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) { return; }
        try {
            if ($this->ReadAttributeString('PendingToken') !== '') { return; }
            $runtime = json_decode($this->GetBuffer('WritePreparationRuntime'), true) ?: [];
            $context = $this->readSafetyContext();
            $observations = json_decode($this->ReadAttributeString('ReadObservations'), true) ?: [];
            if (($runtime['session'] ?? '') !== ($context['session'] ?? null) || !($context['correlationSafeAndIdle'] ?? false)
                || ($observations['CO_RD_VERSION']['capability'] ?? '') !== 'SUPPORTED_READ') {
                $this->WriteAttributeString('WritePreparation', json_encode(['state' => 'REJECTED: session, correlation or fresh version invalid', 'hardwareWriteEnabled' => false], JSON_THROW_ON_ERROR));
                $this->SetBuffer('WritePreparationRuntime', ''); $this->ReloadForm(); return;
            }
            if (($runtime['phase'] ?? '') === 'VERSION_PENDING') {
                $runtime['phase'] = 'BASE_PENDING';
                $this->SetBuffer('WritePreparationRuntime', json_encode($runtime, JSON_THROW_ON_ERROR));
                if ($this->requestRead('CO_RD_IDBASE', true)) { return; }
            }
            $backup = json_decode($this->ReadAttributeString('SavedBaseIDMetadata'), true) ?: [];
            $backup['baseID'] = $this->ReadAttributeString('SavedBaseID');
            $prepared = GatewayWritePreparation::evaluate($runtime['target'], $context, $observations, $backup, time());
            $this->WriteAttributeString('WritePreparation', json_encode($prepared, JSON_THROW_ON_ERROR));
            $this->SetBuffer('WritePreparationRuntime', ''); $this->ReloadForm();
        } finally { IPS_SemaphoreLeave($lock); }
    }

    /** A conscious local action; permanent backup remains independent of the gateway. */
    public function ConfirmSavedBackupIdentity(): bool
    {
        if ($this->GetBuffer('WritePreparationRuntime') !== '' || $this->ReadAttributeString('PendingToken') !== '') { return false; }
        $context = $this->readSafetyContext();
        $observations = json_decode($this->ReadAttributeString('ReadObservations'), true) ?: [];
        $metadata = json_decode($this->ReadAttributeString('SavedBaseIDMetadata'), true) ?: [];
        if ($this->ReadAttributeString('SavedBaseID') === '' || ($metadata['parentInstanceID'] ?? '') !== (string) ($context['arbiterID'] ?? 0)) { return false; }
        // A legacy backup has no chain fingerprint. Explicit confirmation upgrades it,
        // but only after matching the dynamically read EURID and the original parent.
        if (isset($metadata['binding']) && $metadata['binding'] !== ($context['binding'] ?? null)) { return false; }
        $metadata['binding'] = $context['binding'] ?? '';
        $metadata['baseID'] = $this->ReadAttributeString('SavedBaseID');
        $metadata['identityConfirmation'] = ['confirmedAt' => gmdate('c'), 'session' => $context['session'] ?? '',
            'backupBaseID' => $metadata['baseID'], 'eurid' => $metadata['observedEURID'] ?? null];
        $check = GatewayWritePreparation::evaluate($metadata['baseID'], $context, $observations, $metadata, time());
        foreach (['realConnectionActive', 'correlationSafeAndIdle', 'noUnknownOutcome', 'freshBaseID', 'freshWriteCycles',
            'freshEURID', 'confirmedBackupEURIDMatches', 'backupBelongsToParent', 'exclusiveUARTOwner'] as $gate) {
            if (!$check['gates'][$gate]) { return false; }
        }
        unset($metadata['baseID']);
        $this->WriteAttributeString('SavedBaseIDMetadata', json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->ReloadForm(); return true;
    }

    public function RequestHardwareWrite(): string
    {
        // No transport call, no effect encoding. B5 cannot be unlocked by UI/API/property.
        return json_encode(GatewayWritePreparation::dispatchBlocked(), JSON_THROW_ON_ERROR);
    }

    private function requestRead(string $operation, bool $preparationRead = false): bool
    {
        $lock = 'EGM_MANAGER_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) { return false; }
        try {
        if (!$preparationRead && $this->GetBuffer('WritePreparationRuntime') !== '') { return false; }
        if (!$this->ReadPropertyBoolean('EnableReadActions')) {
            $this->setDisplayValue('LastReadStatus', 'REJECTED: read actions disabled');
            return false;
        }
        if (!$this->activeGatewayTransport()) {
            $this->setDisplayValue('TransportStatus', 'NO_ACTIVE_ARBITER');
            $this->setDisplayValue('LastReadStatus', 'REJECTED: no active arbiter');
            return false;
        }
        if ($this->ReadAttributeString('PendingToken') !== '') {
            $this->setDisplayValue('LastReadStatus', 'REJECTED: request already pending');
            return false;
        }

        $token = sprintf('%d-%s', $this->InstanceID, bin2hex(random_bytes(12)));
        $this->WriteAttributeString('PendingToken', $token);
        $this->WriteAttributeString('PendingOperation', $operation);
        $observations = json_decode($this->ReadAttributeString('ReadObservations'), true) ?: [];
        unset($observations[$operation]);
        $this->WriteAttributeString('ReadObservations', json_encode($observations, JSON_THROW_ON_ERROR));
        if ($operation === 'CO_RD_IDBASE') {
            $preview = json_decode($this->ReadAttributeString('BaseIDPreview'), true);
            if (!is_array($preview) || ($preview['state'] ?? '') !== 'AWAITING_FRESH_READ') {
                $this->WriteAttributeString('BaseIDPreview', 'NOT_PREPARED');
            }
            $this->WriteAttributeString('BaseIDReadAt', '');
            $this->setDisplayValue('HardwareBaseID', 'READ_PENDING');
            $this->setDisplayValue('RemainingWriteCycles', 'UNKNOWN / READ_PENDING');
        }
        $this->setDisplayValue('LastReadStatus', 'QUEUED: ' . $token);
        } finally {
            // Release before calling the parent: a synchronous result callback
            // may enter this manager while SendDataToParent is still running.
            IPS_SemaphoreLeave($lock);
        }

        try {
            $rawResult = $this->SendDataToParent(json_encode([
                'DataID' => self::MAINTENANCE_REQUEST_DATA_ID,
                'Operation' => $operation,
                'OwnerInstanceID' => $this->InstanceID,
                'Token' => $token,
                'FrameHex' => $operation === 'CO_RD_IDBASE' ? ESP3Codec::READ_IDBASE_REQUEST_HEX : ESP3Codec::toHex(ESP3Codec::buildReadRequest($operation)),
                'TimeoutMs' => min(5000, max(100, $this->ReadPropertyInteger('ReadTimeoutMs'))),
                ...($preparationRead ? ['ExpectedSession' => (json_decode($this->GetBuffer('WritePreparationRuntime'), true)['session'] ?? '')] : []),
            ], JSON_THROW_ON_ERROR));
            $result = json_decode($rawResult, true);
        } catch (Throwable $error) {
            $this->WriteAttributeString('PendingToken', '');
            $this->setDisplayValue('LastReadStatus', 'TRANSPORT ERROR: ' . $error->getMessage());
            return false;
        }

        if (!is_array($result) || ($result['accepted'] ?? false) !== true) {
            $this->WriteAttributeString('PendingToken', '');
            $this->invalidatePreview($operation);
            $reason = is_array($result) ? (string) ($result['reason'] ?? 'unknown') : 'invalid arbiter response';
            $this->setDisplayValue('LastReadStatus', 'REJECTED: ' . $reason);
            return false;
        }

        if ($this->ReadAttributeString('PendingToken') === $token) {
            $this->setDisplayValue('TransportStatus', 'REQUEST_IN_FLIGHT');
        }
        return true;
    }

    public function ReceiveData(string $JSONString): string
    {
        $lock = 'EGM_MANAGER_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) { throw new RuntimeException('Manager result lock unavailable.'); }
        try { $this->receiveResult($JSONString); }
        finally { IPS_SemaphoreLeave($lock); }
        return '';
    }

    private function receiveResult(string $JSONString): void
    {
        if($this->c2Receive($JSONString))return;
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || ($packet['DataID'] ?? '') !== self::MAINTENANCE_RESULT_DATA_ID) {
            return;
        }
        if ((int) ($packet['OwnerInstanceID'] ?? 0) !== $this->InstanceID) {
            return;
        }

        $pendingToken = $this->ReadAttributeString('PendingToken');
        $token = (string) ($packet['Token'] ?? '');
        if ($pendingToken === '' || !hash_equals($pendingToken, $token)) {
            return;
        }

        $operation = $this->ReadAttributeString('PendingOperation');
        if (($packet['Operation'] ?? '') !== $operation) { return; }

        $this->WriteAttributeString('PendingToken', '');
        $outcome = (string) ($packet['Outcome'] ?? 'UNKNOWN_OUTCOME');
        if ($outcome !== 'RESPONSE') {
            $this->invalidatePreview($operation);
            $this->setDisplayValue('TransportStatus', 'READY_AFTER_' . $outcome);
            $this->setDisplayValue(
                'LastReadStatus',
                $outcome . ': ' . (string) ($packet['Reason'] ?? 'unspecified')
            );
            $this->ReloadForm();
            return;
        }

        try {
            $decoded = ESP3Codec::parseReadResponse($operation,
                ESP3Codec::fromHex((string) ($packet['FrameHex'] ?? ''))
            );
        } catch (Throwable $error) {
            $this->invalidatePreview($operation);
            $this->setDisplayValue('TransportStatus', 'READY_AFTER_INVALID_RESPONSE');
            $this->setDisplayValue('LastReadStatus', 'INVALID RESPONSE: ' . $error->getMessage());
            $this->ReloadForm();
            return;
        }

        $observations = json_decode($this->ReadAttributeString('ReadObservations'), true) ?? [];
        $observations[$operation] = [
            'readAt' => gmdate('c'),
            'parentInstanceID' => (string) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0),
            'session' => (string) ($packet['Session'] ?? ''),
            'binding' => (string) ($packet['Binding'] ?? ''),
            'capability' => $decoded['returnName'] === 'RET_OK' ? 'SUPPORTED_READ'
                : ($decoded['returnName'] === 'RET_NOT_SUPPORTED' ? 'NOT_SUPPORTED' : 'UNKNOWN'),
            'values' => $decoded,
        ];
        $this->WriteAttributeString('ReadObservations', json_encode($observations, JSON_THROW_ON_ERROR));
        $this->updateInformationDisplay($operation, $decoded);
        if ($decoded['returnName'] !== 'RET_OK') {
            $this->invalidatePreview($operation);
            $this->setDisplayValue('TransportStatus', 'READY');
            $this->setDisplayValue('LastReadStatus', 'DEVICE RESPONSE: ' . $decoded['returnName']);
            $this->ReloadForm();
            return;
        }
        $this->WriteAttributeString('LastSuccessfulReadAt', gmdate('c'));

        if ($operation !== 'CO_RD_IDBASE') {
            if ($operation === 'CO_RD_VERSION') {
                $this->setDisplayValue('GatewayInformation', json_encode($decoded, JSON_THROW_ON_ERROR));
            }
            $this->setDisplayValue('TransportStatus', 'READY');
            $this->setDisplayValue('LastReadStatus', 'PASS: ' . $operation . ', RET_OK, CRC valid');
            $this->ReloadForm();
            return;
        }

        $this->setDisplayValue('HardwareBaseID', $decoded['baseIdRawHex']);
        $this->WriteAttributeString('BaseIDReadAt', gmdate('c'));
        $this->WriteAttributeString('BaseIDReadParent', (string) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0));
        $preview = json_decode($this->ReadAttributeString('BaseIDPreview'), true);
        if (is_array($preview) && ($preview['state'] ?? '') === 'AWAITING_FRESH_READ') {
            try {
                $prepared = BaseIDPreflight::preview($preview['requestedBaseID'], $decoded, 5);
                $prepared += ['readAt' => gmdate('c'), 'source' => $preview['source'], 'state' => 'PREVIEW_ONLY'];
                $this->WriteAttributeString('BaseIDPreview', json_encode($prepared, JSON_THROW_ON_ERROR));
            } catch (Throwable $error) {
                $this->WriteAttributeString('BaseIDPreview', 'REJECTED: ' . $error->getMessage());
            }
        }
        $counterDisplay = match ($decoded['remainingWriteCyclesMode']) {
            'finite' => (string) $decoded['remainingWriteCycles'],
            'unlimited' => 'UNLIMITED (raw FF)',
            default => 'UNKNOWN / NOT PROVIDED',
        };
        $this->setDisplayValue('RemainingWriteCycles', $counterDisplay);
        $this->setDisplayValue('TransportStatus', 'READY');
        $this->setDisplayValue('LastReadStatus', 'PASS: RET_OK, CRC valid');
        $this->ReloadForm();
    }

    private function refreshConfiguredGatewayInformation(): void
    {
        $gatewayId = $this->ReadPropertyInteger('NativeGatewayInstanceID');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            $this->setDisplayValue('ConfiguredBaseID', 'UNKNOWN: no gateway assigned');
            $this->setDisplayValue('GatewayInformation', 'NOT_CONFIGURED');
            return;
        }

        $instance = IPS_GetInstance($gatewayId);
        $moduleId = (string) ($instance['ModuleInfo']['ModuleID'] ?? '');
        if ($moduleId !== self::NATIVE_GATEWAY_MODULE_ID) {
            $this->setDisplayValue('ConfiguredBaseID', 'UNKNOWN: assigned instance is not EnOcean Gateway');
            $this->setDisplayValue('GatewayInformation', 'INVALID_GATEWAY_INSTANCE #' . $gatewayId);
            return;
        }

        $configuredBaseId = (string) IPS_GetProperty($gatewayId, 'BaseID');
        $this->setDisplayValue('ConfiguredBaseID', $configuredBaseId === '' ? 'EMPTY' : $configuredBaseId);
        $this->setDisplayValue(
            'GatewayInformation',
            sprintf('%s (#%d); Hardwareinformationen separat über CO_RD_VERSION lesen', IPS_GetName($gatewayId), $gatewayId)
        );
    }

    private function setDisplayValue(string $name, string $value): void
    {
        if ($this->ReadAttributeString($name) !== $value) {
            $this->WriteAttributeString($name, $value);
        }
        if ($this->GetValue($name) !== $value) {
            $this->SetValue($name, $value);
        }
    }

    private function invalidatePreview(string $operation): void
    {
        if ($operation === 'CO_RD_IDBASE') {
            $this->WriteAttributeString('BaseIDReadAt', '');
            $this->WriteAttributeString('BaseIDPreview', 'REJECTED: no fresh valid Base-ID response');
            $this->setDisplayValue('HardwareBaseID', 'UNKNOWN / READ_FAILED');
            $this->setDisplayValue('RemainingWriteCycles', 'UNKNOWN / READ_FAILED');
        }
    }

    private function informationFields(): array
    {
        return ['EURID' => 'Chip-/Radio-ID (EURID)', 'ApplicationVersion' => 'Application-/Firmware-Version',
            'APIVersion' => 'API-/Software-Version', 'DeviceVersion' => 'Device-Version (roh, Hex)',
            'ApplicationDescription' => 'Application Description', 'RadioFrequency' => 'Frequenz / Funkprotokoll',
            'RepeaterState' => 'Repeater-Zustand', 'FilterEntries' => 'Filter (Kriterium und Rohwert)'];
    }

    private function capabilitySummary(): string
    {
        $observations = json_decode($this->ReadAttributeString('ReadObservations'), true) ?? [];
        $lines = [];
        foreach (ESP3Codec::READ_COMMANDS as $operation => $code) {
            $observed = $observations[$operation] ?? [];
            $status = match ($observed['capability'] ?? '') {
                'SUPPORTED_READ' => 'Lesen unterstützt', 'NOT_SUPPORTED' => 'Nicht unterstützt',
                default => 'Unbekannt / nicht ermittelt',
            };
            $lines[] = $operation . ': ' . $status
                . (isset($observed['readAt']) ? ' (' . $observed['readAt'] . ')' : '');
        }
        $lines[] = 'Base-ID Write: ESP3 CO_WR_IDBASE dokumentiert; konkrete Schreibfähigkeit nicht getestet. Im Modul GESPERRT.';
        return implode("\n", $lines);
    }

    private function updateInformationDisplay(string $operation, array $decoded): void
    {
        $notAvailable = $decoded['returnName'] === 'RET_NOT_SUPPORTED' ? 'Nicht verfügbar (RET_NOT_SUPPORTED)'
            : 'Unbekannt (' . $decoded['returnName'] . ')';
        $ok = $decoded['returnName'] === 'RET_OK';
        if ($operation === 'CO_RD_VERSION') {
            foreach (['EURID' => 'eurid', 'ApplicationVersion' => 'applicationVersion', 'APIVersion' => 'apiVersion',
                'DeviceVersion' => 'deviceVersionHex', 'ApplicationDescription' => 'applicationDescription'] as $ident => $key) {
                $this->setDisplayValue($ident, $ok ? (string) $decoded[$key] : $notAvailable);
            }
        } elseif ($operation === 'CO_RD_REPEATER') {
            $this->setDisplayValue('RepeaterState', $ok
                ? $decoded['mode'] . '; Level-Rohwert ' . $decoded['levelRaw'] . ($decoded['mode'] === 'OFF' ? ' (inaktiv, kein aktiver Level-Repeater)' : '')
                : $notAvailable);
        } elseif ($operation === 'CO_RD_FILTER') {
            $entries = [];
            foreach ($decoded['filters'] ?? [] as $filter) {
                $entries[] = $filter['criterion'] . ': ' . $filter['valueHex'];
            }
            $this->setDisplayValue('FilterEntries', $ok ? (implode('; ', $entries) ?: 'Keine Filter')
                . '. Action, Enable und Operator sind über CO_RD_FILTER nicht ermittelbar.' : $notAvailable);
        } elseif ($operation === 'CO_GET_FREQUENCY_INFO') {
            $this->setDisplayValue('RadioFrequency', $ok
                ? ($decoded['frequency'] ?? 'Unbekannte Frequenz') . ' / ' . ($decoded['protocol'] ?? 'Unbekanntes Protokoll')
                : $notAvailable);
        }
    }
}
