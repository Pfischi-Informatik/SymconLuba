<?php

declare(strict_types=1);

/**
 * Test ohne Symcon und ohne Maeher: spielt MQTT-Nachrichten von luba-control ein
 * und prueft Variablen und gesendete Befehle.
 *
 *   php -d zend.assertions=1 -d assert.exception=1 test/live.php [args]
 *   DEBUG=1 ... zeigt SendDebug-Ausgaben
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_MESSAGE = 10206;
const KL_WARNING = 10;
const KL_ERROR = 20;
const KR_READY = 10103;
function IPS_VariableProfileExists($n) { global $profiles; return isset($profiles[$n]); }
function IPS_CreateVariableProfile($n, $t) { global $profiles; $profiles[$n] = []; }
function IPS_DeleteVariableProfile($n) { global $profiles; unset($profiles[$n]); }
function IPS_SetVariableProfileAssociation($n, $v, $name, $i, $c) { global $profiles; $profiles[$n][$v] = $name; }
function IPS_SetVariableProfileValues($n, $min, $max, $step) {}
function IPS_SetVariableProfileText($n, $prefix, $suffix) {}
function IPS_SetVariableProfileIcon($n, $icon) {}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_SetVariableProfileDigits($n, $d) {}
function IPS_InstanceExists($id) { return false; }

class IPSModule
{
    public $InstanceID = 12345;
    private $props = [], $attrs = [], $values = [];
    public $status = 0;
    public function Create(): void {}
    public function Destroy(): void {}
    public function ApplyChanges(): void {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    public function SetProperty($n, $v) { $this->props[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function ReadPropertyFloat($n) { return $this->props[$n]; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function ReadAttributeString($n) { return $this->attrs[$n]; }
    public function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    public function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    protected function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= false; }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= 0; }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= 0.0; }
    protected function RegisterVariableString($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= ''; }
    protected function EnableAction($i) {}
    public $sent = [], $filter = '';
    protected function SetReceiveDataFilter($f) { $this->filter = $f; }
    protected function GetStatus() { return $this->status; }
    protected function HasActiveParent() { return true; }
    protected function SendDataToParent($d) { $this->sent[] = json_decode($d, true); return ''; }
    protected function SetValue($i, $v) { $this->values[$i] = $v; }
    public function GetValue($i) { return $this->values[$i]; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function Translate($s) { return $s; }
    protected function LogMessage($m, $t) { fwrite(STDERR, "LOG: $m\n"); }
    protected function SendDebug($t, $m, $f) { if (getenv('DEBUG')) fwrite(STDERR, "DEBUG $t: " . substr((string) $m, 0, 400) . "\n"); }
}

// --- anpassen -------------------------------------------------------------

require __DIR__ . '/../Luba/module.php';

// Symcon liefert die Nutzlast Byte fuer Byte als Latin-1 -> UTF-8
function mqtt(string $topic, string $payload): string
{
    return json_encode(['DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'Topic' => $topic, 'Payload' => mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$m = new MammotionLuba();
$m->Create();
$m->ApplyChanges();
assert($m->status === 104, 'ohne Topic inaktiv');
$m->SetProperty('Topic', 'luba/Luba-VSTEST');
$m->ApplyChanges();
assert($m->status === 102, 'mit Topic aktiv');
assert(preg_match('/' . str_replace('/', '\/', $m->filter) . '/', mqtt('luba/Luba-VSTEST/state', '{}')) === 1, 'Filter laesst eigene Topics durch');
assert(preg_match('/' . str_replace('/', '\/', $m->filter) . '/', mqtt('luba/Luba-OTHER/state', '{}')) === 0, 'Filter sperrt fremde Maeher');

$state = [
    'connected' => true, 'name' => 'Luba-VSTEST', 'online' => true, 'activity' => 'returning', 'battery' => 42,
    'charging' => false, 'progress' => 77, 'knife_height_mm' => 55, 'rain_detection' => true,
    'position' => ['lat' => 47.1, 'lon' => 8.2, 'yaw' => 0],
    'error' => ['code' => 1005, 'text' => 'Mäher angehoben'],
    'lights' => ['headlight' => false, 'night_light' => true, 'side_led' => true],
    'plans' => [['id' => '9007199254740993', 'name' => 'Vorgarten', 'enabled' => true], ['id' => '2', 'name' => 'Hinten', 'enabled' => false]],
    'limits' => ['blade_height' => [25, 70], 'speed' => [0.2, 0.6]],
];
$m->ReceiveData(mqtt('luba/Luba-VSTEST/state', json_encode($state, JSON_UNESCAPED_UNICODE)));
assert($m->GetValue('Activity') === 3 && $m->GetValue('Battery') === 42 && $m->GetValue('Progress') === 77, 'Status');
assert($m->GetValue('Error') === 'Mäher angehoben', 'Umlaut im Fehlertext: ' . $m->GetValue('Error'));
assert($m->GetValue('Rain') && $m->GetValue('NightLight') && !$m->GetValue('Headlight'), 'Schalter');
assert($m->GetValue('Latitude') === 47.1, 'Position');
assert($profiles['LUBA.Tasks.12345'] === [0 => 'Vorgarten', 1 => 'Hinten (aus)'], 'Aufgaben-Profil');

$m->ReceiveData(mqtt('luba/Luba-VSTEST/notify', 'Luba-VSTEST: Steckt vermutlich fest'));
assert($m->GetValue('Message') === 'Luba-VSTEST: Steckt vermutlich fest', 'Meldung');
$m->ReceiveData(mqtt('luba/Luba-VSTEST/availability', 'offline'));
assert($m->GetValue('Online') === false, 'availability');
$m->ReceiveData(mqtt('luba/Luba-VSTEST/state', '{"connected": false, "error": null}'));
assert($m->GetValue('Error') === 'luba-control nicht mit der Cloud verbunden', 'nicht verbunden');

$m->RequestAction('Task', 0);
$m->RequestAction('Action', 2);
$m->RequestAction('BladeHeight', 60);
$m->RequestAction('Speed', 0.4);
$m->RequestAction('SideLed', false);
$cmds = array_column($m->sent, 'Payload');
assert($cmds === ['start_task 9007199254740993', 'dock', 'blade_height 60', 'speed 0.4', 'side_led off'], 'Befehle: ' . json_encode($cmds));
assert($m->sent[0]['Topic'] === 'luba/Luba-VSTEST/cmd' && $m->sent[0]['DataID'] === '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}', 'Sendeformat');
echo "OK\n";
