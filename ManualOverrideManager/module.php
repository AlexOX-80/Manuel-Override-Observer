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
        $this->RegisterVariableString('DeviceStates', 'Gerätestatus');
        $this->RegisterVariableString('LastEvent', 'Letztes Ereignis');
        $this->RegisterTimer('RefreshDebugTimer', 0, 'MOM_RefreshDebug($_IPS["TARGET"]);');
        $this->RegisterTimer('ExpireTimer', 0, 'MOM_ExpireOverrides($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $old = $this->ReadAttributeInteger('RegisteredGatewayID');
        if ($old > 0 && IPS_InstanceExists($old)) {
            @$this->UnregisterMessage($old, KL_DEBUG);
        }
        $this->WriteAttributeInteger('RegisteredGatewayID', 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('RefreshDebugTimer', 0);
            $this->SetTimerInterval('ExpireTimer', 0);
            $this->setStatusText('deaktiviert');
            $this->SetStatus(104);
            $this->renderStates();
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
            $this->SetTimerInterval('RefreshDebugTimer', 0);
            $this->setStatusText('aktiv · kein KNX-Gateway gefunden · Symcon-UI-Erkennung weiterhin nutzbar');
            $this->SetStatus(201);
        }

        $this->SetTimerInterval('ExpireTimer', 60000);
        $this->ExpireOverrides();
        $this->renderStates();
    }

    public function RefreshDebug(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) return;
        $gateway = $this->ReadAttributeInteger('RegisteredGatewayID');
        if ($gateway <= 0 || !IPS_InstanceExists($gateway)) return;
        @IPS_EnableDebug($gateway, 600);
        $this->setStatusText('aktiv · Gateway '.IPS_GetName($gateway).' (#'.$gateway.') · Symcon '.$this->ReadPropertyString('SymconSourceAddress'));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== KL_DEBUG || !$this->ReadPropertyBoolean('Active')) return;
        if ((int)$SenderID !== $this->ReadAttributeInteger('RegisteredGatewayID')) return;

        $text = $this->flattenDebugData($Data);
        if ($text === '') return;
        if (!preg_match_all('~\\b(\\d{1,2}/\\d{1,2}/\\d{1,3})\\s*\\((\\d{1,2}\\.\\d{1,2}\\.\\d{1,3})\\)~', $text, $matches, PREG_SET_ORDER)) return;

        $gaMap = $this->groupAddressMap();
        foreach ($matches as $m) {
            $ga = $m[1];
            if (!isset($gaMap[$ga])) continue;
            $sourceAddress = $m[2];
            $symconAddress = trim($this->ReadPropertyString('SymconSourceAddress'));
            $classification = ($symconAddress !== '' && $sourceAddress === $symconAddress) ? 'SYMCON_OUT' : 'KNX_DEVICE';

            foreach ($gaMap[$ga] as $deviceKey) {
                $this->appendEvent([
                    'ts' => time(),
                    'date' => date('Y-m-d'),
                    'time' => date('H:i:s'),
                    'deviceKey' => $deviceKey,
                    'source' => $classification,
                    'groupAddress' => $ga,
                    'sourceAddress' => $sourceAddress,
                    'rawDebug' => mb_substr($text, 0, 1000)
                ]);
                if ($classification === 'KNX_DEVICE') {
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
        if ($deviceKey === '') return false;
        $groups = json_decode($groupAddressesJson, true);
        if (!is_array($groups)) $groups = [];
        $groups = array_values(array_unique(array_filter(array_map('strval', $groups), fn($x) => preg_match('~^\\d{1,2}/\\d{1,2}/\\d{1,3}$~', $x))));

        $devices = $this->dynamicDevices();
        $devices[$deviceKey] = [
            'key' => $deviceKey,
            'name' => $name !== '' ? $name : $deviceKey,
            'groupAddresses' => $groups,
            'actionVariableID' => max(0, $actionVariableID),
            'overrideMinutes' => max(0, $overrideMinutes)
        ];
        $this->WriteAttributeString('DynamicDevices', json_encode($devices, JSON_UNESCAPED_UNICODE));
        $this->renderStates();
        return true;
    }

    public function UnregisterDevice(string $deviceKey): void
    {
        $devices = $this->dynamicDevices();
        unset($devices[$deviceKey]);
        $this->WriteAttributeString('DynamicDevices', json_encode($devices, JSON_UNESCAPED_UNICODE));
        $this->ClearOverride($deviceKey, 'UNREGISTERED');
        $this->renderStates();
    }

    public function NotifyAction(int $variableID, string $sender, string $valueJson = 'null'): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) return false;
        $deviceKey = $this->deviceKeyByActionVariable($variableID);
        if ($deviceKey === null) return false;

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
        if (!$this->deviceExists($deviceKey)) return false;
        $this->setManualOverride($deviceKey, $source, [], $minutes);
        return true;
    }

    public function ClearOverride(string $deviceKey, string $reason = 'CLEARED'): void
    {
        $states = $this->states();
        if (!isset($states[$deviceKey])) return;
        $old = $states[$deviceKey];
        unset($states[$deviceKey]);
        $this->WriteAttributeString('OverrideStates', json_encode($states, JSON_UNESCAPED_UNICODE));
        $this->appendEvent([
            'ts' => time(), 'date' => date('Y-m-d'), 'time' => date('H:i:s'),
            'deviceKey' => $deviceKey, 'source' => 'AUTOMATIC', 'reason' => $reason,
            'previousSource' => $old['source'] ?? null
        ]);
        $this->renderStates();
    }

    public function ClearAllOverrides(string $reason = 'CLEARED_ALL'): void
    {
        foreach (array_keys($this->states()) as $deviceKey) $this->ClearOverride((string)$deviceKey, $reason);
    }

    public function ExpireOverrides(): void
    {
        $states = $this->states();
        $now = time();
        foreach ($states as $key => $state) {
            $expires = (int)($state['expiresAt'] ?? 0);
            if ($expires > 0 && $expires <= $now) $this->ClearOverride((string)$key, 'TIMEOUT');
        }
        $this->renderStates();
    }

    public function GetDeviceStateJSON(string $deviceKey): string
    {
        $this->ExpireOverrides();
        $device = $this->allDevices()[$deviceKey] ?? null;
        $state = $this->states()[$deviceKey] ?? null;
        return json_encode([
            'deviceKey' => $deviceKey,
            'name' => $device['name'] ?? $deviceKey,
            'status' => $state ? 'MANUAL_OVERRIDE' : 'AUTOMATIC',
            'manualOverride' => $state !== null,
            'source' => $state['source'] ?? null,
            'since' => $state['since'] ?? null,
            'expiresAt' => $state['expiresAt'] ?? null,
            'details' => $state['details'] ?? null
        ], JSON_UNESCAPED_UNICODE);
    }

    public function GetAllStatesJSON(): string
    {
        $this->ExpireOverrides();
        $result = [];
        $states = $this->states();
        foreach ($this->allDevices() as $key => $device) {
            $state = $states[$key] ?? null;
            $result[$key] = [
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
        return json_encode(['schema' => 'ManualOverrideManager/1', 'generatedAt' => date(DATE_ATOM), 'devices' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function GetEventsJSON(string $date = ''): string
    {
        if ($date === '') $date = date('Y-m-d');
        $events = array_values(array_filter($this->eventLog(), fn($e) => ($e['date'] ?? '') === $date));
        return json_encode(['schema' => 'ManualOverrideEvents/1', 'date' => $date, 'eventCount' => count($events), 'events' => $events], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
                ['type' => 'Label', 'caption' => 'KNX-Taster werden über Quelladresse und Gruppenadresse erkannt. Symcon-Visualisierungen werden über MOM_NotifyAction() aus dem jeweiligen Aktionspfad gemeldet. Automationen lösen keinen Manual Override aus.'],
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
        if ($device === null) return;
        if ($minutes <= 0) $minutes = (int)($device['overrideMinutes'] ?? 0);
        if ($minutes <= 0) $minutes = max(1, $this->ReadPropertyInteger('DefaultOverrideMinutes'));
        $states = $this->states();
        $states[$deviceKey] = [
            'source' => $source,
            'since' => time(),
            'expiresAt' => time() + $minutes * 60,
            'details' => $details
        ];
        $this->WriteAttributeString('OverrideStates', json_encode($states, JSON_UNESCAPED_UNICODE));
        $this->renderStates();
    }

    private function allDevices(): array
    {
        $result = [];
        $static = json_decode($this->ReadPropertyString('Devices'), true);
        if (is_array($static)) {
            foreach ($static as $row) {
                if (!is_array($row)) continue;
                $key = trim((string)($row['key'] ?? ''));
                if ($key === '') continue;
                $groups = $row['groupAddresses'] ?? [];
                if (is_string($groups)) {
                    $decoded = json_decode($groups, true);
                    $groups = is_array($decoded) ? $decoded : [];
                }
                $result[$key] = [
                    'key' => $key,
                    'name' => (string)($row['name'] ?? $key),
                    'groupAddresses' => is_array($groups) ? array_values($groups) : [],
                    'actionVariableID' => (int)($row['actionVariableID'] ?? 0),
                    'overrideMinutes' => (int)($row['overrideMinutes'] ?? 0)
                ];
            }
        }
        foreach ($this->dynamicDevices() as $key => $row) $result[$key] = $row;
        return $result;
    }

    private function dynamicDevices(): array
    {
        $x = json_decode($this->ReadAttributeString('DynamicDevices'), true);
        return is_array($x) ? $x : [];
    }

    private function groupAddressMap(): array
    {
        $map = [];
        foreach ($this->allDevices() as $key => $device) {
            foreach (($device['groupAddresses'] ?? []) as $ga) {
                $ga = trim((string)$ga);
                if (!preg_match('~^\\d{1,2}/\\d{1,2}/\\d{1,3}$~', $ga)) continue;
                if (!isset($map[$ga])) $map[$ga] = [];
                $map[$ga][] = $key;
            }
        }
        return $map;
    }

    private function deviceKeyByActionVariable(int $variableID): ?string
    {
        if ($variableID <= 0) return null;
        foreach ($this->allDevices() as $key => $device) {
            if ((int)($device['actionVariableID'] ?? 0) === $variableID) return (string)$key;
        }
        return null;
    }

    private function deviceExists(string $deviceKey): bool
    {
        return isset($this->allDevices()[$deviceKey]);
    }

    private function states(): array
    {
        $x = json_decode($this->ReadAttributeString('OverrideStates'), true);
        return is_array($x) ? $x : [];
    }

    private function eventLog(): array
    {
        $x = json_decode($this->ReadAttributeString('EventLog'), true);
        return is_array($x) ? $x : [];
    }

    private function appendEvent(array $event): void
    {
        $all = $this->eventLog();
        $all[] = $event;
        $cut = time() - max(1, $this->ReadPropertyInteger('RetentionDays')) * 86400;
        $all = array_values(array_filter($all, fn($e) => (int)($e['ts'] ?? 0) >= $cut));
        if (count($all) > 10000) $all = array_slice($all, -10000);
        $this->WriteAttributeString('EventLog', json_encode($all, JSON_UNESCAPED_UNICODE));
        $summary = ($event['source'] ?? '?').' · '.($event['deviceKey'] ?? '?');
        SetValueString($this->GetIDForIdent('LastEvent'), $summary);
    }

    private function renderStates(): void
    {
        $states = json_decode($this->GetAllStatesJSONNoExpire(), true);
        SetValueString($this->GetIDForIdent('DeviceStates'), json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function GetAllStatesJSONNoExpire(): string
    {
        $result = [];
        $states = $this->states();
        foreach ($this->allDevices() as $key => $device) {
            $state = $states[$key] ?? null;
            $result[$key] = [
                'name' => $device['name'] ?? $key,
                'status' => $state ? 'MANUAL_OVERRIDE' : 'AUTOMATIC',
                'source' => $state['source'] ?? null,
                'since' => $state['since'] ?? null,
                'expiresAt' => $state['expiresAt'] ?? null
            ];
        }
        return json_encode(['devices' => $result], JSON_UNESCAPED_UNICODE);
    }

    private function resolveGatewayID(): int
    {
        $configured = $this->ReadPropertyInteger('KnxGatewayID');
        if ($configured > 0 && IPS_InstanceExists($configured)) return $configured;
        foreach (IPS_GetInstanceList() as $id) {
            try {
                $instance = IPS_GetInstance($id);
                $moduleInfo = $instance['ModuleInfo'] ?? [];
                $moduleID = (string)($moduleInfo['ModuleID'] ?? '');
                if ($moduleID === '') continue;
                $module = IPS_GetModule($moduleID);
                $name = (string)($module['ModuleName'] ?? '');
                if (stripos($name, 'KNX DPT') !== 0) continue;
                $connection = (int)($instance['ConnectionID'] ?? 0);
                if ($connection > 0 && IPS_InstanceExists($connection)) return $connection;
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
                if ($value !== '') $parts[] = $value;
                return;
            }
            if (is_int($value) || is_float($value)) {
                $parts[] = (string)$value;
                return;
            }
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_string($k)) $parts[] = $k;
                    $walk($v);
                }
            }
        };
        $walk($data);
        return trim(implode(' ', $parts));
    }

    private function setStatusText(string $text): void
    {
        SetValueString($this->GetIDForIdent('Status'), $text);
    }
}
