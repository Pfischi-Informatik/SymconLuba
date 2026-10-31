<?php

declare(strict_types=1);

/**
 * Mammotion Luba - IP-Symcon Modul
 * --------------------------------
 * Bindet einen Luba/Yuka-Maehroboter ueber den selbst gehosteten Dienst
 * luba-control (Docker, PyMammotion) per MQTT an. Das Modul haengt an einem
 * MQTT Server oder MQTT Client von Symcon. Topics (siehe luba-control README):
 *
 *   <Topic>/state         Status als JSON (retained)
 *   <Topic>/availability  online | offline (retained)
 *   <Topic>/notify        fertiger Meldungstext (Push)
 *   <Topic>/cmd           Befehle als Klartext, Antwort auf <Topic>/cmd/result
 */
class MammotionLuba extends IPSModule
{
    private const TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    // Taetigkeit aus state.activity -> Variable "Status"
    private const ACTIVITY = [
        'idle' => [0, 'Bereit', 'Ok'], 'mowing' => [1, 'Maeht', 'Motion'], 'paused' => [2, 'Pausiert', 'Sleep'],
        'returning' => [3, 'Faehrt zurueck', 'Return'], 'docked' => [4, 'In der Station', 'Battery'],
        'manual' => [5, 'Manuell', 'Move'], 'locked' => [6, 'Gesperrt', 'Lock'], 'error' => [7, 'Fehler', 'Warning'],
        'updating' => [8, 'Update', 'Gear'], 'sleeping' => [9, 'Schlaeft', 'Moon'], 'offline' => [10, 'Offline', 'Cross'],
        'unknown' => [11, 'Unbekannt', 'Information'],
    ];

    // Variable "Aktion" -> MQTT-Befehl
    private const ACTIONS = [
        0 => ['pause', 'Pause', 'Sleep'], 1 => ['resume', 'Fortsetzen', 'Execute'], 2 => ['dock', 'Zur Station', 'Return'],
        3 => ['cancel', 'Beenden', 'Cross'], 4 => ['leave_dock', 'Station verlassen', 'Motion'], 5 => ['wake', 'Aufwecken', 'Flag'],
    ];

    // Schaltbare Variablen -> Befehl
    private const SWITCHES = ['Rain' => 'rain', 'Headlight' => 'light', 'NightLight' => 'night_light', 'SideLed' => 'side_led'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Topic', 'luba/');
        $this->RegisterPropertyInteger('NotifyTarget', 0);

        $this->RegisterAttributeString('Plans', '[[],[]]'); // [plan_ids, Namen] in Reihenfolge der Variable "Aufgabe"
        $this->RegisterAttributeString('Limits', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->profile('LUBA.Activity', VARIABLETYPE_INTEGER, array_values(self::ACTIVITY));
        $this->profile('LUBA.Action', VARIABLETYPE_INTEGER, array_map(fn ($k, $a) => [$k, $a[1], $a[2]], array_keys(self::ACTIONS), self::ACTIONS));
        $this->profile('LUBA.Tasks.' . $this->InstanceID, VARIABLETYPE_INTEGER, []);
        $this->profile('LUBA.BladeHeight', VARIABLETYPE_INTEGER, [], ' mm');
        $this->profile('LUBA.Speed', VARIABLETYPE_FLOAT, [], ' m/s');

        $this->RegisterVariableInteger('Action', $this->Translate('Aktion'), 'LUBA.Action', 1);
        $this->EnableAction('Action');
        $this->RegisterVariableInteger('Task', $this->Translate('Aufgabe starten'), 'LUBA.Tasks.' . $this->InstanceID, 2);
        $this->EnableAction('Task');
        $this->RegisterVariableInteger('Activity', $this->Translate('Status'), 'LUBA.Activity', 3);
        $this->RegisterVariableBoolean('Online', $this->Translate('Online'), '~Switch', 4);
        $this->RegisterVariableInteger('Battery', $this->Translate('Akku'), '~Battery.100', 5);
        $this->RegisterVariableBoolean('Charging', $this->Translate('Laedt'), '~Switch', 6);
        $this->RegisterVariableInteger('Progress', $this->Translate('Fortschritt'), '~Intensity.100', 7);
        $this->RegisterVariableString('Error', $this->Translate('Fehler'), '', 8);
        $this->RegisterVariableString('Message', $this->Translate('Letzte Meldung'), '', 9);
        $this->RegisterVariableInteger('BladeHeight', $this->Translate('Schnitthoehe'), 'LUBA.BladeHeight', 10);
        $this->EnableAction('BladeHeight');
        $this->RegisterVariableFloat('Speed', $this->Translate('Geschwindigkeit'), 'LUBA.Speed', 11);
        $this->EnableAction('Speed');
        $this->RegisterVariableBoolean('Rain', $this->Translate('Regensensor'), '~Switch', 12);
        $this->RegisterVariableBoolean('Headlight', $this->Translate('Scheinwerfer'), '~Switch', 13);
        $this->RegisterVariableBoolean('NightLight', $this->Translate('Nachtlicht'), '~Switch', 14);
        $this->RegisterVariableBoolean('SideLed', $this->Translate('Seiten-LED'), '~Switch', 15);
        foreach (array_keys(self::SWITCHES) as $ident) {
            $this->EnableAction($ident);
        }
        $this->RegisterVariableFloat('Latitude', $this->Translate('Breitengrad'), '', 16);
        $this->RegisterVariableFloat('Longitude', $this->Translate('Laengengrad'), '', 17);

        $topic = trim($this->ReadPropertyString('Topic'), '/');
        if ($topic === '' || !str_contains($topic, '/')) {
            $this->SetReceiveDataFilter('NOTHING_TO_RECEIVE');
            $this->SetStatus(104);
            return;
        }
        $this->SetReceiveDataFilter('.*"Topic":"' . preg_quote($topic) . '/.*');
        $this->SetStatus(102);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Action':
                if (isset(self::ACTIONS[(int) $Value]) && $this->SendCommand(self::ACTIONS[(int) $Value][0])) {
                    $this->SetValue('Action', (int) $Value);
                }
                break;

            case 'Task':
                $plan = json_decode($this->ReadAttributeString('Plans'), true)[0][(int) $Value] ?? null;
                if ($plan !== null && $this->SendCommand('start_task ' . $plan)) {
                    $this->SetValue('Task', (int) $Value);
                }
                break;

            case 'BladeHeight':
                $this->SendCommand('blade_height ' . (int) $Value);
                break;

            case 'Speed':
                // der Status meldet das Tempo nicht zurueck, darum hier setzen
                if ($this->SendCommand('speed ' . round((float) $Value, 2))) {
                    $this->SetValue('Speed', (float) $Value);
                }
                break;

            default:
                if (isset(self::SWITCHES[$Ident])) {
                    $this->SendCommand(self::SWITCHES[$Ident] . ($Value ? ' on' : ' off'));
                    break;
                }
                $this->LogMessage('Unbekannter Ident: ' . $Ident, KL_WARNING);
        }
    }

    // =====================================================================
    // Oeffentliche Funktionen
    // =====================================================================

    /**
     * Sendet einen luba-control-Befehl, z.B. "pause", "dock", "wake", "start_task <plan_id>",
     * "blade_height 50", "speed 0.4", "rain on", "light off". Das Ergebnis kommt asynchron
     * auf cmd/result (Debug-Ausgabe, Fehler im Meldungsfenster).
     */
    public function SendCommand(string $Command): bool
    {
        $Command = trim($Command);
        if ($Command === '' || $this->GetStatus() !== 102 || !$this->HasActiveParent()) {
            $this->LogMessage('Befehl nicht gesendet: ' . ($Command ?: '(leer)') . ' - Topic oder MQTT-Instanz pruefen', KL_WARNING);
            return false;
        }
        $this->SendDebug('cmd', $Command, 0);
        $this->SendDataToParent(json_encode([
            'DataID' => self::TX,
            'PacketType' => 3,
            'QualityOfService' => 0,
            'Retain' => false,
            'Topic' => $this->topic() . '/cmd',
            'Payload' => mb_convert_encoding($Command, 'UTF-8', 'ISO-8859-1'),
        ], JSON_UNESCAPED_SLASHES));
        return true;
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        $sub = substr((string) ($data['Topic'] ?? ''), strlen($this->topic()) + 1);
        // Seit Symcon 6.3 kommt die Nutzlast Byte fuer Byte als Latin-1 nach UTF-8 kodiert
        $payload = mb_convert_encoding((string) ($data['Payload'] ?? ''), 'ISO-8859-1', 'UTF-8');
        $this->SendDebug($sub, $payload, 0);

        switch ($sub) {
            case 'state':
                $state = json_decode($payload, true);
                if (is_array($state)) {
                    $this->applyState($state);
                }
                break;

            case 'availability':
                $this->SetValue('Online', $payload === 'online');
                break;

            case 'notify':
                $this->SetValue('Message', $payload);
                $this->push($payload);
                break;

            case 'cmd/result':
                $result = json_decode($payload, true);
                if (is_array($result) && !($result['ok'] ?? false)) {
                    $this->LogMessage('Befehl "' . ($result['cmd'] ?? '') . '" fehlgeschlagen: ' . ($result['error'] ?? ''), KL_WARNING);
                }
                break;
        }
        return '';
    }

    // =====================================================================
    // Intern
    // =====================================================================

    private function topic(): string
    {
        return trim($this->ReadPropertyString('Topic'), '/');
    }

    private function applyState(array $s): void
    {
        if (!($s['connected'] ?? false)) {
            $this->SetValue('Online', false);
            $this->SetValue('Error', (string) (($s['error'] ?? '') ?: 'luba-control nicht mit der Cloud verbunden'));
            return;
        }
        $this->SetValue('Online', (bool) ($s['online'] ?? false));
        $this->SetValue('Activity', (self::ACTIVITY[$s['activity'] ?? ''] ?? self::ACTIVITY['unknown'])[0]);
        $this->SetValue('Battery', (int) ($s['battery'] ?? 0));
        $this->SetValue('Charging', (bool) ($s['charging'] ?? false));
        $this->SetValue('Progress', (int) ($s['progress'] ?? 0));
        $err = $s['error'] ?? null;
        $this->SetValue('Error', $err ? trim(($err['text'] ?? '') ?: 'Fehler ' . ($err['code'] ?? '')) : '');
        $this->SetValue('BladeHeight', (int) ($s['knife_height_mm'] ?? 0));
        $this->SetValue('Rain', (bool) ($s['rain_detection'] ?? false));
        foreach (['Headlight' => 'headlight', 'NightLight' => 'night_light', 'SideLed' => 'side_led'] as $ident => $key) {
            $this->SetValue($ident, (bool) ($s['lights'][$key] ?? false));
        }
        if (isset($s['position']['lat'], $s['position']['lon'])) {
            $this->SetValue('Latitude', (float) $s['position']['lat']);
            $this->SetValue('Longitude', (float) $s['position']['lon']);
        }
        $this->updatePlans($s['plans'] ?? []);
        $this->updateLimits($s['limits'] ?? []);
    }

    /** Aufgaben als Auswahl der Variable "Aufgabe starten"; neu gebaut nur bei Aenderung. */
    private function updatePlans(array $plans): void
    {
        $ids = array_map(fn ($p) => (string) $p['id'], $plans);
        $names = array_map(fn ($p) => ($p['name'] ?? $p['id']) . (($p['enabled'] ?? true) ? '' : ' (aus)'), $plans);
        $key = json_encode([$ids, $names]);
        if ($key === $this->ReadAttributeString('Plans')) {
            return;
        }
        $profile = 'LUBA.Tasks.' . $this->InstanceID;
        if (IPS_VariableProfileExists($profile)) {
            IPS_DeleteVariableProfile($profile);
        }
        $this->profile($profile, VARIABLETYPE_INTEGER, array_map(fn ($i, $n) => [$i, $n, 'Execute'], array_keys($names), $names));
        $this->WriteAttributeString('Plans', $key);
    }

    /** Wertebereich von Schnitthoehe und Tempo je Modell, kommt aus state.limits. */
    private function updateLimits(array $limits): void
    {
        $key = json_encode($limits);
        if ($limits === [] || $key === $this->ReadAttributeString('Limits')) {
            return;
        }
        [$lo, $hi] = $limits['blade_height'] ?? [20, 100];
        IPS_SetVariableProfileValues('LUBA.BladeHeight', $lo, $hi, 5);
        [$lo, $hi] = $limits['speed'] ?? [0.2, 1.0];
        IPS_SetVariableProfileValues('LUBA.Speed', $lo, $hi, 0.1);
        IPS_SetVariableProfileDigits('LUBA.Speed', 1);
        $this->WriteAttributeString('Limits', $key);
    }

    private function push(string $text): void
    {
        $target = $this->ReadPropertyInteger('NotifyTarget');
        if ($target <= 1 || !IPS_InstanceExists($target)) {
            return;
        }
        // ponytail: Typ per Versuch statt per Modul-GUID; Kachel-Visualisierung zuerst, sonst WebFront
        try {
            if (@VISU_PostNotification($target, 'Luba', $text, 'Information', 0)) {
                return;
            }
        } catch (Throwable) {
        }
        try {
            @WFC_PushNotification($target, 'Luba', $text, '', 0);
        } catch (Throwable $e) {
            $this->LogMessage('Push an #' . $target . ' fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
        }
    }

    private function profile(string $name, int $type, array $associations, string $suffix = ''): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
            if ($suffix !== '') {
                IPS_SetVariableProfileText($name, '', $suffix);
            }
        }
        foreach ($associations as [$value, $caption, $icon]) {
            IPS_SetVariableProfileAssociation($name, $value, $caption, $icon, -1);
        }
    }
}
