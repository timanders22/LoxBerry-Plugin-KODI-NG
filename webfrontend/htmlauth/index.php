<?php
/**
 * Kodi fuer LoxBerry - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Protokoll
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * Grundlage: LoxBerry-Plugin-Kodi 0.1.2 von Christian Fenzl (christianTF).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS und wuerde gleichnamige
 * Plugin-Variablen ueberschreiben - daher tragen hier ALLE Variablen ein
 * ko_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$ko_lbhome = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
$ko_plugin = getenv('LBPPLUGINDIR') ?: basename(dirname(__DIR__));
if ($ko_lbhome && is_dir($ko_lbhome . '/config/plugins/' . $ko_plugin) === false) { $ko_plugin = 'kodi'; }
if ($ko_lbhome) {
    $ko_sdk = $ko_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($ko_sdk)) { require_once $ko_sdk; require_once $ko_lbhome . '/libs/phplib/loxberry_web.php'; }
    $ko_cfgdir  = $ko_lbhome . '/config/plugins/' . $ko_plugin;
    $ko_bkfile  = $ko_lbhome . '/config/plugins/' . $ko_plugin . '.backup.json';
    $ko_logfile = $ko_lbhome . '/log/plugins/' . $ko_plugin . '/kodi.log';
    $ko_bindir  = $ko_lbhome . '/bin/plugins/' . $ko_plugin;
    $ko_datadir = $ko_lbhome . '/data/plugins/' . $ko_plugin;
} else {
    $ko_cfgdir  = dirname(dirname(__DIR__)) . '/config';
    $ko_bkfile  = $ko_cfgdir . '/kodi.backup.json';
    $ko_logfile = sys_get_temp_dir() . '/kodi/kodi.log';
    $ko_bindir  = dirname(dirname(__DIR__)) . '/bin';
    $ko_datadir = dirname(dirname(__DIR__)) . '/data';
}
$ko_cfgfile = $ko_cfgdir . '/kodi.json';

/* Konfigurationssicherung ausserhalb des Plugin-Ordners zurueckholen (Hausstandard) */
if ((!is_file($ko_cfgfile) || trim((string) @file_get_contents($ko_cfgfile)) === '' || trim((string) @file_get_contents($ko_cfgfile)) === '{}') && is_file($ko_bkfile)) {
    @mkdir($ko_cfgdir, 0775, true);
    @copy($ko_bkfile, $ko_cfgfile);
    @chmod($ko_cfgfile, 0600);
}

function ko_config() {
    global $ko_cfgfile;
    $c = @json_decode((string) @file_get_contents($ko_cfgfile), true);
    if (!is_array($c)) { $c = array(); }
    if (!isset($c['mqtt_topic']) || trim((string) $c['mqtt_topic']) === '') { $c['mqtt_topic'] = 'kodi'; }
    if (!isset($c['kodi_host']) || trim((string) $c['kodi_host']) === '') { $c['kodi_host'] = '127.0.0.1'; }
    if (!isset($c['kodi_port'])) { $c['kodi_port'] = 8080; }
    return $c;
}

function ko_log($text) {
    global $ko_logfile;
    @mkdir(dirname($ko_logfile), 0775, true);
    @file_put_contents($ko_logfile, '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Helfer mit erhoehten Rechten aufrufen (schreibt config.txt, steuert den Dienst) */
function ko_helper($args) {
    global $ko_bindir;
    $cmd = 'sudo ' . escapeshellcmd($ko_bindir . '/elevatedhelper.pl') . ' ' . $args . ' 2>/dev/null';
    return (string) @shell_exec($cmd);
}

function ko_status() {
    $raw = ko_helper('action=query');
    $j = @json_decode($raw, true);
    return is_array($j) ? $j : array();
}

/** UDP-In-Port des LoxBerry MQTT Gateways ermitteln (Hausstandard) */
function ko_mqtt_port() {
    global $ko_lbhome;
    if (!$ko_lbhome) { return 0; }
    $gen = @json_decode((string) @file_get_contents($ko_lbhome . '/config/system/general.json'), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    return $udp;
}

/** Werte per MQTT veroeffentlichen - ueber den UDP-Relay des Gateways */
function ko_mqtt_publish($paare) {
    $udp = ko_mqtt_port();
    if (!$udp) { ko_log('MQTT: Kein UDP-In-Port des Gateways gefunden - uebersprungen.'); return 0; }
    $cfg = ko_config();
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'kodi';
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) { ko_log('MQTT: UDP-Relay nicht erreichbar.'); return 0; }
    $n = 0;
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') { continue; }
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udp);
        $n++;
    }
    socket_close($s);
    ko_log('MQTT: ' . $n . ' Werte an ' . $prefix . ' (Gateway-Relay 127.0.0.1:' . $udp . ')');
    return $n;
}

function ko_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- Eingaben verarbeiten ---------------- */

$ko_saved = false; $ko_err = ''; $ko_note = ''; $ko_raw = '';
$ko_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save'])) {
        $ko_cfg = ko_config();
        /* Eingaben nie hart filtern - nur Steuerzeichen und Leerraum weg (Hausstandard) */
        $ko_cfg['mqtt_topic'] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['mqtt_topic']));
        if ($ko_cfg['mqtt_topic'] === '') { $ko_cfg['mqtt_topic'] = 'kodi'; }
        $ko_cfg['kodi_host'] = trim(preg_replace('/[\x00-\x1F\x7F"\'\s]/', '', (string) $_POST['kodi_host']));
        $ko_cfg['kodi_port'] = (int) $_POST['kodi_port'];
        if ($ko_cfg['kodi_port'] < 1 || $ko_cfg['kodi_port'] > 65535) { $ko_cfg['kodi_port'] = 8080; }

        /* Lizenzschluessel: leeres Feld loescht nichts (Hausstandard) */
        $ko_st = ko_status();
        foreach (array('licmpeg2' => 'mpeg2lic', 'licvc1' => 'vc1lic') as $ko_key => $ko_feld) {
            $ko_neu = trim((string) $_POST[$ko_key]);
            if ($ko_neu === '') { continue; }
            if ($ko_neu === (string) (isset($ko_st[$ko_feld]) ? $ko_st[$ko_feld] : '')) { continue; }
            ko_helper('action=change key=' . escapeshellarg($ko_key) . ' value=' . escapeshellarg($ko_neu));
            ko_log('Lizenzschluessel ' . $ko_key . ' gesetzt.');
        }

        $ko_auto = isset($_POST['kodiautostart']) ? 1 : 0;
        ko_helper('action=change key=kodiautostart value=' . $ko_auto);

        @mkdir($ko_cfgdir, 0775, true);
        @file_put_contents($ko_cfgfile, json_encode($ko_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($ko_cfgfile, 0600);
        @copy($ko_cfgfile, $ko_bkfile);
        @chmod($ko_bkfile, 0600);
        $ko_saved = true;
        ko_log('Konfiguration gespeichert.');
    }

    if (isset($_POST['service'])) {
        $ko_was = (string) $_POST['service'];
        if (in_array($ko_was, array('start', 'stop', 'restart'), true)) {
            ko_helper('action=service key=kodi value=' . $ko_was);
            ko_log('Dienst: ' . $ko_was);
            $ko_note = 'Befehl <b>' . ko_e($ko_was) . '</b> an den Kodi-Dienst geschickt.';
        }
        $ko_tab = 'tab-test';
    }

    if (isset($_POST['rawstatus'])) {
        $ko_raw = ko_helper('action=query');
        $ko_tab = 'tab-test';
    }

    if (isset($_POST['servicestatus'])) {
        $ko_raw = (string) @shell_exec('systemctl status kodi --no-pager 2>&1');
        $ko_tab = 'tab-test';
    }

    if (isset($_POST['mqtttest'])) {
        $ko_st = ko_status();
        $ko_n = ko_mqtt_publish(array(
            'test'        => 1,
            'dienst'      => isset($ko_st['kodistarted']) ? (int) $ko_st['kodistarted'] : 0,
            'autostart'   => isset($ko_st['kodiautostart']) ? (int) $ko_st['kodiautostart'] : 0,
            'zeitstempel' => time(),
        ));
        $ko_note = $ko_n > 0
            ? $ko_n . ' Werte an das MQTT Gateway geschickt. Im MQTT Finder unter <span class="ko-mono">' . ko_e(ko_config()['mqtt_topic']) . '/</span> nachsehen.'
            : 'Es wurde nichts gesendet &mdash; der UDP-Eingang des MQTT Gateways ist nicht gesetzt. Im LoxBerry unter <i>Dienste &rarr; MQTT Gateway &rarr; Allgemein</i> aktivieren.';
        $ko_tab = 'tab-test';
    }

    if (isset($_POST['clearlog'])) {
        @mkdir(dirname($ko_logfile), 0775, true);
        @file_put_contents($ko_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
        $ko_tab = 'tab-log';
    }
}

$ko_cfg  = ko_config();
$ko_st   = ko_status();
$ko_port = ko_mqtt_port();

$ko_frame = class_exists('LBWeb', false);
if ($ko_frame) { LBWeb::lbheader('Kodi f&uuml;r LoxBerry', 'https://wiki.loxberry.de/', ''); }
$ko_host = ko_e(isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '<loxberry-ip>');
?>
<style>
.ko-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.ko-wrap, .ko-wrap * { text-shadow: none !important; }
.ko-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.ko-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.ko-wrap input[type=text], .ko-wrap input[type=number], .ko-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.ko-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.ko-row { display: flex; gap: 12px; flex-wrap: wrap; }
.ko-row > div { flex: 1; min-width: 150px; }
.ko-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.ko-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.ko-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.ko-err { background: #ffebee; border: 1px solid #ef9a9a; }
.ko-warn { background: #fff8e1; border: 1px solid #ffe082; }
.ko-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.ko-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.ko-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.ko-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.ko-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.ko-tab.ko-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.ko-pane { display: none; padding-top: 4px; }
.ko-pane.ko-active { display: block; }
.ko-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.ko-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.ko-tbl { border-collapse: collapse; margin: 8px 0; }
.ko-tbl th, .ko-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.ko-tbl th { background: #f0f0f0; }
.ko-wrap a.ko-btn, .ko-wrap a.ko-btn:visited, .ko-wrap a.ko-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.ko-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.ko-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.ko-knopfreihe form { margin: 0; display: flex; }
.ko-knopfreihe .ko-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.ko-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.ko-legende span { display: inline-flex; align-items: center; gap: 6px; }
.ko-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.ko-btn.ko-b-lesen   { background: #6dac20; }
.ko-btn.ko-b-technik { background: #546e7a; }
.ko-btn.ko-b-aktion  { background: #e0620d; }
.ko-punkt.ko-b-lesen   { background: #6dac20; }
.ko-punkt.ko-b-technik { background: #546e7a; }
.ko-punkt.ko-b-aktion  { background: #e0620d; }
</style>
<div class="ko-wrap">

<?php if ($ko_saved) { ?><div class="ko-alert ko-ok"><b>Konfiguration gespeichert</b> (Dateirechte 0600, mit Sicherungskopie f&uuml;r Updates).</div><?php } ?>
<?php if ($ko_note !== '') { ?><div class="ko-alert ko-ok"><?= $ko_note ?></div><?php } ?>
<?php if ($ko_err !== '') { ?><div class="ko-alert ko-err"><b>Fehler:</b> <?= $ko_err ?></div><?php } ?>

<div class="ko-alert <?= (isset($ko_st['kodistarted']) && $ko_st['kodistarted']) ? 'ko-info' : 'ko-warn' ?>">
<b>Kodi</b>:
<?php if (!$ko_st) { ?>
<b>Status nicht abrufbar</b> &mdash; l&auml;uft der Helfer? Pr&uuml;fen mit <span class="ko-mono">sudo <?= ko_e($ko_bindir) ?>/elevatedhelper.pl action=query</span>
<?php } else { ?>
Dienst <b><?= (isset($ko_st['kodistarted']) && $ko_st['kodistarted']) ? 'l&auml;uft' : 'gestoppt' ?></b>
&middot; Autostart <b><?= (isset($ko_st['kodiautostart']) && $ko_st['kodiautostart']) ? 'ein' : 'aus' ?></b>
&middot; MPEG2 <?= ko_e(isset($ko_st['mpeg2status']) ? $ko_st['mpeg2status'] : '?') ?>
&middot; VC1 <?= ko_e(isset($ko_st['vc1status']) ? $ko_st['vc1status'] : '?') ?><br>
Seriennummer des Pi: <span class="ko-mono"><?= ko_e(isset($ko_st['piserial']) ? $ko_st['piserial'] : '?') ?></span>
<?php } ?>
</div>

<?php if (!$ko_port) { ?>
<div class="ko-alert ko-warn"><b>UDP-Eingang des MQTT Gateways nicht gesetzt.</b> Ohne ihn kommen keine Werte
am Miniserver an. Im LoxBerry unter <i>Dienste &rarr; MQTT Gateway &rarr; Allgemein</i> den UDP-Eingang
aktivieren; der Standardport ist 11884.</div>
<?php } ?>

<div class="ko-tabs">
    <div class="ko-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="ko-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="ko-tab" data-pane="tab-test">Test</div>
    <div class="ko-tab" data-pane="tab-log">Protokoll</div>
</div>

<!-- ================= Einstellungen ================= -->
<div class="ko-pane" id="tab-settings">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Kodi</h2>
<div class="ko-row">
    <div>
        <label>Adresse des Kodi-Rechners</label>
        <input data-role="none" type="text" name="kodi_host" value="<?= ko_e($ko_cfg['kodi_host']) ?>">
        <div class="ko-small">Meist <span class="ko-mono">127.0.0.1</span>, wenn Kodi auf demselben LoxBerry l&auml;uft.</div>
    </div>
    <div>
        <label>Port des Webinterface</label>
        <input data-role="none" type="number" name="kodi_port" value="<?= (int) $ko_cfg['kodi_port'] ?>">
    </div>
</div>

<label style="margin-top:16px;">
    <input data-role="none" type="checkbox" name="kodiautostart" value="1" <?= (isset($ko_st['kodiautostart']) && $ko_st['kodiautostart']) ? 'checked' : '' ?>>
    Kodi beim Hochfahren automatisch starten
</label>

<h2>MQTT</h2>
<label>MQTT-Thema</label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= ko_e($ko_cfg['mqtt_topic']) ?>">
<div class="ko-small">Die Werte erscheinen darunter, zum Beispiel <span class="ko-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/dienst</span>.</div>

<h2>Lizenzschl&uuml;ssel</h2>
<div class="ko-alert ko-info">
Die Schl&uuml;ssel gibt es im Raspberry-Pi-Shop, gebunden an die Seriennummer
<span class="ko-mono"><?= ko_e(isset($ko_st['piserial']) ? $ko_st['piserial'] : '?') ?></span>.
Sie werden in die <span class="ko-mono">config.txt</span> geschrieben und wirken erst nach einem Neustart.
<b>Ein leeres Feld l&ouml;scht nichts</b> &mdash; der gespeicherte Wert bleibt stehen.
</div>
<div class="ko-row">
    <div>
        <label>MPEG2 &mdash; hinterlegt: <span class="ko-mono"><?= ko_e(isset($ko_st['mpeg2lic']) && $ko_st['mpeg2lic'] !== '' ? $ko_st['mpeg2lic'] : 'keiner') ?></span></label>
        <input data-role="none" type="text" name="licmpeg2" value="" placeholder="0x00000000">
    </div>
    <div>
        <label>VC1 &mdash; hinterlegt: <span class="ko-mono"><?= ko_e(isset($ko_st['vc1lic']) && $ko_st['vc1lic'] !== '' ? $ko_st['vc1lic'] : 'keiner') ?></span></label>
        <input data-role="none" type="text" name="licvc1" value="" placeholder="0x00000000">
    </div>
</div>

<button data-role="none" class="ko-btn" type="submit" name="save" value="1">Speichern</button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="ko-pane" id="tab-loxone">

<h2>So kommen die Werte in den Miniserver</h2>

<div class="ko-step"><b>1. UDP-Eingang des Gateways aktivieren.</b> Das MQTT Gateway geh&ouml;rt zu LoxBerry
&mdash; du findest es im Hauptmen&uuml; unter <i>Dienste &rarr; MQTT Gateway</i>, ein Plugin muss daf&uuml;r nicht
installiert werden. Unter <i>Allgemein</i> den UDP-Eingang einschalten (Standardport 11884). Dieser Port ist
derzeit <b><?= $ko_port ? ko_e($ko_port) : 'nicht gesetzt' ?></b>.</div>

<div class="ko-step"><b>2. Themen finden.</b> Im MQTT Gateway den <i>MQTT Finder</i> &ouml;ffnen und den Zweig
<span class="ko-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/</span> aufklappen. Dort erscheint, was Kodi meldet.
Zum Ausprobieren im Reiter <i>Test</i> auf <i>Test-Ereignis an MQTT senden</i> dr&uuml;cken.</div>

<div class="ko-step"><b>3. In Loxone verwenden.</b> Im Gateway die gew&uuml;nschten Themen dem Miniserver
zuweisen; sie erscheinen dort als virtuelle Eing&auml;nge. Ein eigener virtueller Ausgang ist daf&uuml;r
nicht n&ouml;tig.</div>

<h2>Themen</h2>
<table class="ko-tbl">
<tr><th>Thema</th><th>Bedeutung</th><th>Werte</th></tr>
<tr><td class="ko-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/dienst</td><td>Kodi-Dienst</td><td>1 = l&auml;uft, 0 = gestoppt</td></tr>
<tr><td class="ko-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/autostart</td><td>Autostart</td><td>1 = ein, 0 = aus</td></tr>
<tr><td class="ko-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/zeitstempel</td><td>Zeitpunkt der letzten Meldung</td><td>Unix-Zeit</td></tr>
<tr><td class="ko-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/status</td><td>Wiedergabe</td><td>play, pause, stop</td></tr>
<tr><td class="ko-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/titel</td><td>laufender Titel</td><td>Text</td></tr>
</table>
<div class="ko-small">Die letzten beiden liefert das Kodi-Addon <i>Callback Handler</i>, sobald in Kodi
etwas abgespielt wird. Die ersten drei kommen vom Plugin selbst.</div>

<h2>Kodi steuern</h2>
<div class="ko-small">Umgekehrt &mdash; also Loxone steuert Kodi &mdash; geht &uuml;ber die JSON-RPC-Schnittstelle.
Ein virtueller Ausgang mit dieser Adresse gen&uuml;gt:</div>
<div class="ko-step ko-mono">http://<?= ko_e($ko_cfg['kodi_host']) ?>:<?= (int) $ko_cfg['kodi_port'] ?>/jsonrpc</div>
<div class="ko-small">Die fertige Vorlage <span class="ko-mono">VO_Kodi_V1.xml</span> liegt im Plugin-Ordner unter
<span class="ko-mono">data/</span> und l&auml;sst sich in Loxone Config &uuml;ber
<i>Virtueller Ausgang &rarr; Vorlage einf&uuml;gen</i> laden.</div>

</div>

<!-- ================= Test ================= -->
<div class="ko-pane" id="tab-test">

<div class="ko-legende">
<span><i class="ko-punkt ko-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="ko-punkt ko-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="ko-punkt ko-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert etwas</span>
</div>

<h3 class="ko-h3">Ansehen</h3>
<div class="ko-knopfreihe">
    <a class="ko-btn ko-b-lesen" href="">Status neu abfragen</a>
    <a class="ko-btn ko-b-lesen" href="http://<?= ko_e($ko_cfg['kodi_host'] === '127.0.0.1' ? $ko_host : $ko_cfg['kodi_host']) ?>:<?= (int) $ko_cfg['kodi_port'] ?>" target="_blank">Kodi-Webinterface &ouml;ffnen</a>
</div>

<h3 class="ko-h3">Technische Auskunft</h3>
<div class="ko-knopfreihe">
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="ko-btn ko-b-technik" type="submit" name="rawstatus" value="1">Rohdaten der Statusabfrage</button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="ko-btn ko-b-technik" type="submit" name="servicestatus" value="1">Dienstzustand (systemctl)</button></form>
</div>

<h3 class="ko-h3">L&ouml;st etwas aus</h3>
<div class="ko-knopfreihe">
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="ko-btn ko-b-aktion" type="submit" name="service" value="start">Kodi starten</button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="ko-btn ko-b-aktion" type="submit" name="service" value="stop">Kodi stoppen</button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="ko-btn ko-b-aktion" type="submit" name="service" value="restart">Kodi neu starten</button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="ko-btn ko-b-aktion" type="submit" name="mqtttest" value="1">Test-Ereignis an MQTT senden</button></form>
</div>

<?php if ($ko_raw !== '') { ?>
<h2>Ausgabe</h2>
<div class="ko-log"><?= ko_e($ko_raw) ?></div>
<?php } ?>

</div>

<!-- ================= Protokoll ================= -->
<div class="ko-pane" id="tab-log">
<h2>Protokoll</h2>
<?php
$ko_zeilen = is_file($ko_logfile) ? @file($ko_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
if ($ko_zeilen) {
    $ko_zeilen = array_slice($ko_zeilen, -300);
    echo '<div class="ko-log">' . ko_e(implode("\n", array_reverse($ko_zeilen))) . '</div>';
} else { ?>
<div class="ko-alert ko-info">Noch keine Protokoll-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="ko-btn" type="submit" style="background:#c62828;">Protokoll leeren</button>
</form>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.ko-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('ko-active', t.dataset.pane === id); });
        document.querySelectorAll('.ko-pane').forEach(function (p) { p.classList.toggle('ko-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($ko_tab) ?>);
})();
</script>
<?php
if ($ko_frame) { LBWeb::lbfooter(); }
