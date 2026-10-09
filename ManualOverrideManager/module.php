<?php
declare(strict_types=1);

class ManualOverrideManager extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('KnxGatewayID', 0);
        $this->RegisterPropertyString('SymconSourceAddress', '15.15.241');
        $this->RegisterPropertyInteger('DefaultOverrideMinutes', 120);
        $this->RegisterPropertyInteger('RetentionDays', 3);
        $this->RegisterPropertyString('Devices', '[]');

        $this->RegisterAttributeInteger('RegisteredGatewayID', 0);
        $this->RegisterAttributeString('OverrideStates', '{}');
        $this->RegisterAttributeString('EventLog', '[]');
        $this->RegisterAttributeString('DynamicDevices', '{}');

        $this->RegisterVariableString('Status', 'Status');
        $this->RegisterVariableString('DeviceStates', 'Gerätestatus JSON');
        $this->RegisterVariableString('LastEvent', 'Letztes Ereignis');

        $this->RegisterTimer('RefreshDebugTimer', 0, 'MOM_RefreshDebug($_IPS["TARGET"]);');
        $this->RegisterTimer('ExpireTimer', 0, 'MOM_ExpireOverrides($_IPS["TARGET"]);');
        $this->RegisterTimer('TreeSyncTimer', 0, 'MOM_SyncVisibleDeviceTree($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $oldGateway = $this->ReadAttributeInteger('RegisteredGatewayID');
        if ($oldGateway > 0 && IPS_InstanceExists($oldGateway)) {
            @$this->UnregisterMessage($oldGateway, KL_DEBUG);
        }
        $this->WriteAttributeInteger('RegisteredGatewayID', 0);

        $this->SetTimerInterval('RefreshDebugTimer', 0);
        $this->SetTimerInterval('ExpireTimer', 0);
        $this->SetTimerInterval('TreeSyncTimer', 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->setStatusText('deaktiviert');
            $this->SetStatus(104);
            $this->renderStateValues();
            return;
        }

        $gateway = $this->resolveGatewayID();
        if ($gateway > 0 && IPS_InstanceExists($gateway)) {
            $this->RegisterMessage($gateway, KL_DEBUG);
            $this->WriteAttributeInteger('RegisteredGatewayID', $gateway);
            $this->SetTimerInterval('RefreshDebugTimer', 300000);
            $this->RefreshDebug();
            $this->SetStatus(102);
        } else {
            $this->setStatusText('aktiv · kein KNX-Gateway gefunden · Symcon-UI-Erkennung weiterhin nutzbar');
            $this->SetStatus(201);
        }

        $this->SetTimerInterval('ExpireTimer', 60000);
        $this->SetTimerInterval('TreeSyncTimer', 3000);
        $this->renderStateValues();
    }

    public function RefreshDebug(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $gateway = $this->ReadAttributeInteger('RegisteredGatewayID');
        if ($gateway <= 0 || !IPS_InstanceExists($gateway)) {
            return;
        }
        @IPS_EnableDebug($gateway, 600);
        $this->setStatusText('aktiv · Gateway '.IPS_GetName($gateway).' (#'.$gateway.') · Symcon '.$this->ReadPropertyString('SymconSourceAddress'));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== KL_DEBUG || !$this->ReadPropertyBoolean('Active')) {
            return;
        }
        if ((int)$SenderID !== $this->ReadAttributeInteger('RegisteredGatewayID')) {
            return;
        }

        $text = $this->flattenDebugData($Data);
        if ($text === '' || !preg_match_all('~\b(\d{1,2}/\d{1,2}/\d{1,3})\s*\((\d{1,2}\.\d{1,2}\.\d{1,3})\)~', $text, $matches, PREG_SET_ORDER)) {
            return;
        }

        $gaMap = $this->groupAddressMap();
        $symconAddress = trim($this->ReadPropertyString('SymconSourceAddress'));

        foreach ($matches as $match) {
            $ga = $match[1];
            if (!isset($gaMap[$ga])) {
                continue;
            }
            $sourceAddress = $match[2];
            $source = ($symconAddress !== '' && $sourceAddress === $symconAddress) ? 'SYMCON_OUT' : 'KNX_DEVICE';

            foreach ($gaMap[$ga] as $deviceKey) {
                $this->appendEvent([
                    'ts' => time(),
                    'date' => date('Y-m-d'),
                    'time' => date('H:i:s'),
                    'deviceKey' => $deviceKey,
                    'source' => $source,
                    'groupAddress' => $ga,
                    'sourceAddress' => $sourceAddress
                ]);
                if ($source === 'KNX_DEVICE') {
                    $this->setManualOverride($deviceKey, 'KNX_DEVICE', [
                        'groupAddress' => $ga,
                        'sourceAddress' => $sourceAddress
                    ]);
                }
            }
        }
    }

    public function RegisterDevice(string $deviceKey, string $name, string $groupAddressesJson = '[]', int $actionVariableID = 0, int $overrideMinutes = 0): bool
    {
        $deviceKey = trim($deviceKey);
        if ($deviceKey === '') {
            return false;
        }

        $groups = json_decode($groupAddressesJson, true);
        if (!is_array($groups)) {
            $groups = [];
        }
        $groups = array_values(array_unique(array_filter(
            array_map('strval', $groups),
            fn($x) => preg_match('~^\d{1,2}/\d{1,2}/\d{1,3}$~', $x) === 1
        )));
        sort($groups);

        $new = [
            'key' => $deviceKey,
            'name' => $name !== '' ? $name : $deviceKey,
            'groupAddresses' => $groups,
            'actionVariableID' => max(0, $actionVariableID),
            'overrideMinutes' => max(0, $overrideMinutes)
        ];

        $devices = $this->dynamicDevices();
        $old = $devices[$deviceKey] ?? null;
        if ($old === $new) {
            return true;
        }

        $devices[$deviceKey] = $new;
        $this->WriteAttributeString('DynamicDevices', json_encode($devices, JSON_UNESCAPED_UNICODE));

        // Object creation is deliberately deferred. SmartShading may call this method
        // for many devices in one evaluation; creating the visible tree here caused
        // repeated full-tree work and could overload IP-Symcon during module updates.
        $this->SetTimerInterval('TreeSyncTimer', 1500);
        return true;
    }

    public function UnregisterDevice(string $deviceKey): void
    {
        $devices = $this->dynamicDevices();
        if (!isset($devices[$deviceKey])) {
            return;
        }
        unset($devices[$deviceKey]);
        $this->WriteAttributeString('DynamicDevices', json_encode($devices, JSON_UNESCAPED_UNICODE));

        $states = $this->states();
        if (isset($states[$deviceKey])) {
            unset($states[$deviceKey]);
            $this->WriteAttributeString('OverrideStates', json_encode($states, JSON_UNESCAPED_UNICODE));
        }
        $this->SetTimerInterval('TreeSyncTimer', 1500);
        $this->renderStateValues();
    }

    public function NotifyAction(int $variableID, string $sender, string $valueJson = 'null'): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }
        $deviceKey = $this->deviceKeyByActionVariable($variableID);
        if ($deviceKey === null) {
            return false;
        }

        $manual = in_array($sender, ['WebFront', 'WebInterface', 'WebHook', 'WebOAuth'], true);
        $source = $manual ? 'SYMCON_UI' : 'SYMCON_AUTOMATION';
        $value = json_decode($valueJson, true);

        $this->appendEvent([
            'ts' => time(),
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
            'deviceKey' => $deviceKey,
            'source' => $source,
            'sender' => $sender,
            'variableID' => $variableID,
            'value' => $value
        ]);

        if ($manual) {
            $this->setManualOverride($deviceKey, 'SYMCON_UI', [
                'sender' => $sender,
                'variableID' => $variableID,
                'value' => $value
            ]);
        }
        return true;
    }

    public function SetManualOverride(string $deviceKey, string $source = 'EXTERNAL', int $minutes = 0): bool
    {
        if (!$this->deviceExists($deviceKey)) {
            return false;
        }
        $this->setManualOverride($deviceKey, $source, [], $minutes);
        return true;
    }

    public function ClearOverride(string $deviceKey, string $reason = 'CLEARED'): void
    {
        $states = $this->states();
        if (!isset($states[$deviceKey])) {
            return;
        }

        $old = $states[$deviceKey];
        unset($states[$deviceKey]);
        $this->WriteAttributeString('OverrideStates', json_encode($states, JSON_UNESCAPED_UNICODE));
        $this->appendEvent([
            'ts' => time(),
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
            'deviceKey' => $deviceKey,
            'source' => 'AUTOMATIC',
            'reason' => $reason,
            'previousSource' => $old['source'] ?? null
        ]);
        $this->renderStateValues();
    }

    public function ClearAllOverrides(string $reason = 'CLEARED_ALL'): void
    {
        $states = $this->states();
        if ($states === []) {
            return;
        }

        $now = time();
        foreach ($states as $deviceKey => $old) {
            $this->appendEvent([
                'ts' => $now,
                'date' => date('Y-m-d', $now),
                'time' => date('H:i:s', $now),
                'deviceKey' => (string)$deviceKey,
                'source' => 'AUTOMATIC',
                'reason' => $reason,
                'previousSource' => $old['source'] ?? null
            ]);
        }
        $this->WriteAttributeString('OverrideStates', '{}');
        $this->renderStateValues();
    }

    public function ExpireOverrides(): void
    {
        $states = $this->states();
        if ($states === []) {
            return;
        }

        $now = time();
        $changed = false;
        foreach ($states as $deviceKey => $state) {
            $expiresAt = (int)($state['expiresAt'] ?? 0);
            if ($expiresAt <= 0 || $expiresAt > $now) {
                continue;
            }
            unset($states[$deviceKey]);
            $changed = true;
            $this->appendEvent([
                'ts' => $now,
                'date' => date('Y-m-d', $now),
                'time' => date('H:i:s', $now),
                'deviceKey' => (string)$deviceKey,
                'source' => 'AUTOMATIC',
                'reason' => 'TIMEOUT',
                'previousSource' => $state['source'] ?? null
            ]);
        }

        if ($changed) {
            $this->WriteAttributeString('OverrideStates', json_encode($states, JSON_UNESCAPED_UNICODE));
            $this->renderStateValues();
        }
    }

    public function SyncVisibleDeviceTree(): void
    {
        $this->SetTimerInterval('TreeSyncTimer', 0);
        $root = $this->ensureCategory($this->InstanceID, 'Devices', 'Geräte', 10);
        foreach ($this->allDevices() as $key => $device) {
            $category = $this->ensureCategory($root, $this->deviceIdent((string)$key), (string)($device['name'] ?? $key), 0);
            $this->ensureVariable($category, 'OperatingState', 'Betriebszustand', 3, 10);
            $this->ensureVariable($category, 'ManualOverride', 'Manual Override', 0, 20);
            $this->ensureVariable($category, 'Source', 'Quelle', 3, 30);
            $this->ensureVariable($category, 'Since', 'Seit', 3, 40);
            $this->ensureVariable($category, 'Until', 'Bis', 3, 50);
        }
        $this->renderStateValues();
    }

    public function GetDeviceStateJSON(string $deviceKey): string
    {
        $device = $this->allDevices()[$deviceKey] ?? null;
        $state = $this->states()[$deviceKey] ?? null;
        return json_encode($this->deviceState($deviceKey, $device, $state), JSON_UNESCAPED_UNICODE);
    }

    public function GetAllStatesJSON(): string
    {
        return json_encode($this->allStateData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function GetEventsJSON(string $date = ''): string
    {
        if ($date === '') {
            $date = date('Y-m-d');
        }
        $events = array_values(array_filter($this->eventLog(), fn($e) => ($e['date'] ?? '') === $date));
        return json_encode([
            'schema' => 'ManualOverrideEvents/1',
            'date' => $date,
            'eventCount' => count($events),
            'events' => $events
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Aktiv'],
                ['type' => 'SelectInstance', 'name' => 'KnxGatewayID', 'caption' => 'KNX-Gateway (0 = automatisch erkennen)'],
                ['type' => 'ValidationTextBox', 'name' => 'SymconSourceAddress', 'caption' => 'KNX-Quelladresse von Symcon'],
                ['type' => 'NumberSpinner', 'name' => 'DefaultOverrideMinutes', 'caption' => 'Standard Manual Override in Minuten', 'minimum' => 1, 'maximum' => 1440],
                ['type' => 'NumberSpinner', 'name' => 'RetentionDays', 'caption' => 'Ereignisprotokoll in Tagen', 'minimum' => 1, 'maximum' => 14],
                ['type' => 'Label', 'caption' => 'Geräte werden idempotent registriert. Der sichtbare Gerätebaum wird außerhalb der Automationsauswertung verzögert synchronisiert.'],
                ['type' => 'List', 'name' => 'Devices', 'caption' => 'Statisch konfigurierte Geräte', 'add' => true, 'delete' => true, 'rowCount' => 8,
                    'columns' => [
                        ['name' => 'key', 'caption' => 'Schlüssel', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['name' => 'name', 'caption' => 'Name', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['name' => 'groupAddresses', 'caption' => 'KNX GAs JSON', 'width' => '180px', 'add' => '[]', 'edit' => ['type' => 'ValidationTextBox']],
                        ['name' => 'actionVariableID', 'caption' => 'Action Variable', 'width' => '120px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['name' => 'overrideMinutes', 'caption' => 'Override min', 'width' => '90px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner']]
                    ]
                ]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Gerätebaum synchronisieren', 'onClick' => 'MOM_SyncVisibleDeviceTree($id);'],
                ['type' => 'Button', 'caption' => 'Debug jetzt erneuern', 'onClick' => 'MOM_RefreshDebug($id);'],
                ['type' => 'Button', 'caption' => 'Gerätestatus anzeigen', 'onClick' => 'echo MOM_GetAllStatesJSON($id);'],
                ['type' => 'Button', 'caption' => 'Alle Overrides auf Automatik', 'confirm' => 'Alle Manual Overrides löschen?', 'onClick' => 'MOM_ClearAllOverrides($id, "USER_CLEAR_ALL");']
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Inaktiv'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'KNX-Gateway nicht gefunden']
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    private function setManualOverride(string $deviceKey, string $source, array $details = [], int $minutes = 0): void
    {
        $device = $this->allDevices()[$deviceKey] ?? null;
        if ($device === null) {
            return;
        }
        if ($minutes <= 0) {
            $minutes = (int)($device['overrideMinutes'] ?? 0);
        }
        if ($minutes <= 0) {
            $minutes = max(1, $this->ReadPropertyInteger('DefaultOverrideMinutes'));
        }

        $now = time();
        $states = $this->states();
        $states[$deviceKey] = [
            'source' => $source,
            'since' => $now,
            'expiresAt' => $now + ($minutes * 60),
            'details' => $details
        ];
        $this->WriteAttributeString('OverrideStates', json_encode($states, JSON_UNESCAPED_UNICODE));
        $this->renderStateValues();
    }

    private function allDevices(): array
    {
        $result = [];
        $static = json_decode($this->ReadPropertyString('Devices'), true);
        if (is_array($static)) {
            foreach ($static as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = trim((string)($row['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $groups = $row['groupAddresses'] ?? [];
                if (is_string($groups)) {
                    $decoded = json_decode($groups, true);
                    $groups = is_array($decoded) ? $decoded : [];
                }
                $groups = array_values(array_map('strval', is_array($groups) ? $groups : []));
                sort($groups);
                $result[$key] = [
                    'key' => $key,
                    'name' => (string)($row['name'] ?? $key),
                    'groupAddresses' => $groups,
                    'actionVariableID' => (int)($row['actionVariableID'] ?? 0),
                    'overrideMinutes' => (int)($row['overrideMinutes'] ?? 0)
                ];
            }
        }
        foreach ($this->dynamicDevices() as $key => $row) {
            $result[$key] = $row;
        }
        return $result;
    }

    private function dynamicDevices(): array
    {
        $data = json_decode($this->ReadAttributeString('DynamicDevices'), true);
        return is_array($data) ? $data : [];
    }

    private function groupAddressMap(): array
    {
        $map = [];
        foreach ($this->allDevices() as $key => $device) {
            foreach (($device['groupAddresses'] ?? []) as $ga) {
                $ga = trim((string)$ga);
                if (preg_match('~^\d{1,2}/\d{1,2}/\d{1,3}$~', $ga) !== 1) {
                    continue;
                }
                $map[$ga] ??= [];
                $map[$ga][] = (string)$key;
            }
        }
        return $map;
    }

    private function deviceKeyByActionVariable(int $variableID): ?string
    {
        if ($variableID <= 0) {
            return null;
        }
        foreach ($this->allDevices() as $key => $device) {
            if ((int)($device['actionVariableID'] ?? 0) === $variableID) {
                return (string)$key;
            }
        }
        return null;
    }

    private function deviceExists(string $deviceKey): bool
    {
        return isset($this->allDevices()[$deviceKey]);
    }

    private function states(): array
    {
        $data = json_decode($this->ReadAttributeString('OverrideStates'), true);
        return is_array($data) ? $data : [];
    }

    private function eventLog(): array
    {
        $data = json_decode($this->ReadAttributeString('EventLog'), true);
        return is_array($data) ? $data : [];
    }

    private function appendEvent(array $event): void
    {
        $events = $this->eventLog();
        $events[] = $event;
        $cut = time() - (max(1, $this->ReadPropertyInteger('RetentionDays')) * 86400);
        $events = array_values(array_filter($events, fn($e) => (int)($e['ts'] ?? 0) >= $cut));
        if (count($events) > 10000) {
            $events = array_slice($events, -10000);
        }
        $this->WriteAttributeString('EventLog', json_encode($events, JSON_UNESCAPED_UNICODE));
        $this->setStringIfChanged($this->GetIDForIdent('LastEvent'), ($event['source'] ?? '?').' · '.($event['deviceKey'] ?? '?'));
    }

    private function allStateData(): array
    {
        $result = [];
        $states = $this->states();
        foreach ($this->allDevices() as $key => $device) {
            $result[$key] = $this->deviceState((string)$key, $device, $states[$key] ?? null);
        }
        return [
            'schema' => 'ManualOverrideManager/3',
            'generatedAt' => date(DATE_ATOM),
            'devices' => $result
        ];
    }

    private function deviceState(string $key, ?array $device, ?array $state): array
    {
        return [
            'deviceKey' => $key,
            'name' => $device['name'] ?? $key,
            'status' => $state ? 'MANUAL_OVERRIDE' : 'AUTOMATIC',
            'manualOverride' => $state !== null,
            'source' => $state['source'] ?? null,
            'since' => $state['since'] ?? null,
            'expiresAt' => $state['expiresAt'] ?? null,
            'details' => $state['details'] ?? null
        ];
    }

    private function renderStateValues(): void
    {
        $data = $this->allStateData();
        $this->setStringIfChanged($this->GetIDForIdent('DeviceStates'), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $root = @IPS_GetObjectIDByIdent('Devices', $this->InstanceID);
        if (!$root || !IPS_CategoryExists((int)$root)) {
            return;
        }

        foreach ($data['devices'] as $key => $state) {
            $category = @IPS_GetObjectIDByIdent($this->deviceIdent((string)$key), (int)$root);
            if (!$category || !IPS_CategoryExists((int)$category)) {
                continue;
            }

            $this->setExistingString((int)$category, 'OperatingState', (string)$state['status']);
            $this->setExistingBoolean((int)$category, 'ManualOverride', (bool)$state['manualOverride']);
            $this->setExistingString((int)$category, 'Source', (string)($state['source'] ?? ''));
            $this->setExistingString((int)$category, 'Since', $this->formatTimestamp($state['since'] ?? null));
            $this->setExistingString((int)$category, 'Until', $this->formatTimestamp($state['expiresAt'] ?? null));
        }
    }

    private function ensureCategory(int $parentID, string $ident, string $name, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if (!$id || !IPS_CategoryExists((int)$id)) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
        }
        if (IPS_GetName((int)$id) !== $name) {
            IPS_SetName((int)$id, $name);
        }
        return (int)$id;
    }

    private function ensureVariable(int $parentID, string $ident, string $name, int $type, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if (!$id || !IPS_VariableExists((int)$id)) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
            IPS_SetPosition($id, $position);
        }
        return (int)$id;
    }

    private function setExistingString(int $parentID, string $ident, string $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id && IPS_VariableExists((int)$id)) {
            $this->setStringIfChanged((int)$id, $value);
        }
    }

    private function setExistingBoolean(int $parentID, string $ident, bool $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id && IPS_VariableExists((int)$id) && (bool)GetValue((int)$id) !== $value) {
            SetValueBoolean((int)$id, $value);
        }
    }

    private function setStringIfChanged(int $variableID, string $value): void
    {
        if ($variableID > 0 && IPS_VariableExists($variableID) && (string)GetValue($variableID) !== $value) {
            SetValueString($variableID, $value);
        }
    }

    private function deviceIdent(string $deviceKey): string
    {
        return 'Device_'.substr(sha1($deviceKey), 0, 16);
    }

    private function formatTimestamp(mixed $timestamp): string
    {
        $timestamp = (int)($timestamp ?? 0);
        return $timestamp > 0 ? date('d.m.Y H:i:s', $timestamp) : '';
    }

    private function resolveGatewayID(): int
    {
        $configured = $this->ReadPropertyInteger('KnxGatewayID');
        if ($configured > 0 && IPS_InstanceExists($configured)) {
            return $configured;
        }
        foreach (IPS_GetInstanceList() as $id) {
            try {
                $instance = IPS_GetInstance($id);
                $moduleID = (string)($instance['ModuleInfo']['ModuleID'] ?? '');
                if ($moduleID === '') {
                    continue;
                }
                $module = IPS_GetModule($moduleID);
                $name = (string)($module['ModuleName'] ?? '');
                if (stripos($name, 'KNX DPT') !== 0) {
                    continue;
                }
                $connection = (int)($instance['ConnectionID'] ?? 0);
                if ($connection > 0 && IPS_InstanceExists($connection)) {
                    return $connection;
                }
            } catch (Throwable $e) {
            }
        }
        return 0;
    }

    private function flattenDebugData(mixed $data): string
    {
        $parts = [];
        $walk = function ($value) use (&$walk, &$parts): void {
            if (is_string($value)) {
                if ($value !== '') {
                    $parts[] = $value;
                }
                return;
            }
            if (is_int($value) || is_float($value)) {
                $parts[] = (string)$value;
                return;
            }
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    if (is_string($key)) {
                        $parts[] = $key;
                    }
                    $walk($child);
                }
            }
        };
        $walk($data);
        return trim(implode(' ', $parts));
    }

    private function setStatusText(string $text): void
    {
        $this->setStringIfChanged($this->GetIDForIdent('Status'), $text);
    }
}
