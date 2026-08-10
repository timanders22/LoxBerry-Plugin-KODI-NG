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

/**
 * Sprache der Oberflaeche.
 *
 * Bis 1.1.0 trug diese Datei ihre Texte unmittelbar im Quelltext; die beiden
 * Sprachdateien waren leere Gerueste und haben nichts bewirkt. Fuer einen
 * englischen Leser war das Plugin damit unbedienbar - der Hilfetext war
 * uebersetzt, die Oberflaeche daneben nicht.
 */
function ko_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

/** Text zu einem Schluessel der Form ABSCHNITT.NAME. */
function ko_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        global $ko_lbhome, $ko_plugin;
        $pfad = $ko_lbhome ? $ko_lbhome . '/templates/plugins/' . $ko_plugin . '/lang' : '';
        if (!$pfad || !is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(__DIR__)) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . ko_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        // Englisch als Rueckfallebene, damit eine noch nicht uebersetzte
        // Zeile nicht als blanker Schluessel auf dem Bildschirm landet.
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // INI_SCANNER_RAW liefert die Anfuehrungszeichen mit zurueck, in die
        // die Werte laut Hausregeln stehen muessen. Die gehoeren nicht ins Bild.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/* ---------------- Eingaben verarbeiten ---------------- */

$ko_saved = false; $ko_err = ''; $ko_note = ''; $ko_raw = '';
/* Wer einen Reiter hinzufuegt, muss DREI Stellen mitziehen: die
   Reiterleiste, den Bereich (sm-pane mit gleicher id) und diese
   Positivliste. Fehlt der Name hier, springt die Seite nach jedem Absenden
   zurueck auf Einstellungen. */
$ko_muster = '/^tab-(settings|loxone|test|log)$/';
$ko_tab = preg_match($ko_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';
// Die Reiter sind echte Verweise. Wer sie anklickt oder ein Lesezeichen
// darauf setzt, landet ueber ?form= im richtigen Bereich - auch dann, wenn
// im Browser kein JavaScript laeuft. Bis 1.0.0 waren es <div>-Elemente, und
// sm-active setzte ausschliesslich das JavaScript: ohne JavaScript stand
// jeder Bereich auf display:none, die Seite war also LEER.
if (isset($_GET['form'])) {
    $ko_wunsch = 'tab-' . preg_replace('/[^a-z]/', '', (string) $_GET['form']);
    if (preg_match($ko_muster, $ko_wunsch)) { $ko_tab = $ko_wunsch; }
}
/** Klasse fuer den gerade sichtbaren Reiter bzw. Bereich. */
function ko_aktiv($id) { global $ko_tab; return $ko_tab === $id ? ' sm-active' : ''; }

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
            $ko_note = str_replace('%s', '<b>' . ko_e($ko_was) . '</b>', ko_t('TEXT.BEFEHL_GESCHICKT'));
        }
        $ko_tab = 'tab-test';
    }

    if (isset($_POST['rawstatus'])) {
        $ko_raw = ko_helper('action=query');
        $ko_tab = 'tab-test';
    }

    if (isset($_POST['servicestatus'])) {
        $ko_raw = (string) @shell_exec('systemctl status kodi_ng --no-pager 2>&1');
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
            ? str_replace(array('%n', '%t'), array($ko_n, '<span class="sm-mono">' . ko_e(ko_config()['mqtt_topic']) . '/</span>'), ko_t('TEXT.MQTT_GESENDET'))
            : ko_t('TEXT.MQTT_NICHT_GESENDET');
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
// Die Hilfeseite wird jetzt wirklich angeschlossen.
//
// Der dritte Parameter blieb bis 1.1.0 leer - templates/help/kodi_main.html
// und die zugehoerigen Schluessel in kodi_main_de.ini/kodi_main_en.ini lagen
// also im Paket, ohne dass das Fragezeichen oben rechts sie je angezeigt
// haette.
/* Der dritte Parameter hiess bis 1.1.0 'kodi_main.html' - eine Datei, die es
 * nie gab. LBWeb::gethelp() fand nichts und zeigte den Ersatztext an; der
 * Hilfeknopf war damit wirkungslos. Jetzt 'help.html', dazu die Texte in
 * templates/lang/help_de.ini und help_en.ini. */
if ($ko_frame) { LBWeb::lbheader(ko_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html'); }
$ko_host = ko_e(isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '<loxberry-ip>');
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  display: inline-block; text-decoration: none !important; text-shadow: none !important; }
.sm-tab:visited, .sm-tab:hover { text-decoration: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($ko_saved) { ?><div class="sm-alert sm-ok"><?= ko_t('TEXT.GESPEICHERT') ?></div><?php } ?>
<?php if ($ko_note !== '') { ?><div class="sm-alert sm-ok"><?= $ko_note ?></div><?php } ?>
<?php if ($ko_err !== '') { ?><div class="sm-alert sm-err"><b><?= ko_t('TEXT.FEHLER') ?></b> <?= $ko_err ?></div><?php } ?>

<div class="sm-alert <?= (isset($ko_st['kodistarted']) && $ko_st['kodistarted']) ? 'sm-info' : 'sm-warn' ?>">
<b>Kodi</b>:
<?php if (!$ko_st) { ?>
<?= str_replace('%s', '<span class="sm-mono">sudo ' . ko_e($ko_bindir) . '/elevatedhelper.pl action=query</span>', ko_t('TEXT.STATUS_NICHT_ABRUFBAR')) ?>
<?php } else { ?>
<?= ko_t('TEXT.DIENST') ?> <b><?= (isset($ko_st['kodistarted']) && $ko_st['kodistarted']) ? ko_t('TEXT.LAEUFT') : ko_t('TEXT.GESTOPPT') ?></b>
&middot; <?= ko_t('TEXT.AUTOSTART') ?> <b><?= (isset($ko_st['kodiautostart']) && $ko_st['kodiautostart']) ? ko_t('TEXT.EIN') : ko_t('TEXT.AUS') ?></b>
&middot; MPEG2 <?= ko_e(isset($ko_st['mpeg2status']) ? $ko_st['mpeg2status'] : '?') ?>
&middot; VC1 <?= ko_e(isset($ko_st['vc1status']) ? $ko_st['vc1status'] : '?') ?><br>
<?= ko_t('TEXT.PISERIAL') ?> <span class="sm-mono"><?= ko_e(isset($ko_st['piserial']) ? $ko_st['piserial'] : '?') ?></span>
<?php } ?>
</div>

<?php if (!$ko_port) { ?>
<div class="sm-alert sm-warn"><?= ko_t('TEXT.UDP_WARNUNG') ?></div>
<?php } ?>

<div class="sm-tabs">
    <a class="sm-tab<?= ko_aktiv('tab-settings') ?>" data-pane="tab-settings" href="index.php?form=settings"><?= ko_t('REITER.EINSTELLUNGEN') ?></a>
    <a class="sm-tab<?= ko_aktiv('tab-loxone') ?>" data-pane="tab-loxone" href="index.php?form=loxone"><?= ko_t('REITER.LOXONE') ?></a>
    <a class="sm-tab<?= ko_aktiv('tab-test') ?>" data-pane="tab-test" href="index.php?form=test"><?= ko_t('REITER.TEST') ?></a>
    <a class="sm-tab<?= ko_aktiv('tab-log') ?>" data-pane="tab-log" href="index.php?form=log"><?= ko_t('REITER.LOG') ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-pane<?= ko_aktiv('tab-settings') ?>" id="tab-settings">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Kodi</h2>
<div class="sm-row">
    <div>
        <label><?= ko_t('TEXT.L_HOST') ?></label>
        <input data-role="none" type="text" name="kodi_host" value="<?= ko_e($ko_cfg['kodi_host']) ?>">
        <div class="sm-small"><?= str_replace('%s', '<span class="sm-mono">127.0.0.1</span>', ko_t('TEXT.H_HOST')) ?></div>
    </div>
    <div>
        <label><?= ko_t('TEXT.L_PORT') ?></label>
        <input data-role="none" type="number" name="kodi_port" value="<?= (int) $ko_cfg['kodi_port'] ?>">
    </div>
</div>

<label style="margin-top:16px;">
    <input data-role="none" type="checkbox" name="kodiautostart" value="1" <?= (isset($ko_st['kodiautostart']) && $ko_st['kodiautostart']) ? 'checked' : '' ?>>
    <?= ko_t('TEXT.L_AUTOSTART') ?>
</label>

<h2>MQTT</h2>
<label><?= ko_t('TEXT.L_THEMA') ?></label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= ko_e($ko_cfg['mqtt_topic']) ?>">
<div class="sm-small"><?= str_replace('%s', '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/dienst</span>', ko_t('TEXT.H_THEMA')) ?></div>

<h2><?= ko_t('TEXT.H_LIZENZ') ?></h2>
<div class="sm-alert sm-info">
<?= str_replace('%s', '<span class="sm-mono">' . ko_e(isset($ko_st['piserial']) ? $ko_st['piserial'] : '?') . '</span>', ko_t('TEXT.LIZENZ_HINWEIS')) ?>
</div>
<div class="sm-row">
    <div>
        <label>MPEG2 &mdash; <?= ko_t('TEXT.HINTERLEGT') ?> <span class="sm-mono"><?= ko_e(isset($ko_st['mpeg2lic']) && $ko_st['mpeg2lic'] !== '' ? $ko_st['mpeg2lic'] : ko_t('TEXT.KEINER')) ?></span></label>
        <input data-role="none" type="text" name="licmpeg2" value="" placeholder="0x00000000">
    </div>
    <div>
        <label>VC1 &mdash; <?= ko_t('TEXT.HINTERLEGT') ?> <span class="sm-mono"><?= ko_e(isset($ko_st['vc1lic']) && $ko_st['vc1lic'] !== '' ? $ko_st['vc1lic'] : ko_t('TEXT.KEINER')) ?></span></label>
        <input data-role="none" type="text" name="licvc1" value="" placeholder="0x00000000">
    </div>
</div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?= ko_t('ALLG.SPEICHERN') ?></button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-pane<?= ko_aktiv('tab-loxone') ?>" id="tab-loxone">

<h2><?= ko_t('TEXT.H_LOXONE') ?></h2>

<div class="sm-step"><?= str_replace('%s', '<b>' . ($ko_port ? ko_e($ko_port) : ko_t('TEXT.NICHT_GESETZT')) . '</b>', ko_t('TEXT.SCHRITT1')) ?></div>

<div class="sm-step"><?= str_replace('%s', '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/</span>', ko_t('TEXT.SCHRITT2')) ?></div>

<div class="sm-step"><?= ko_t('TEXT.SCHRITT3') ?></div>

<h2><?= ko_t('TEXT.H_THEMEN') ?></h2>
<table class="sm-tbl">
<tr><th><?= ko_t('TEXT.TH_THEMA') ?></th><th><?= ko_t('TEXT.TH_BEDEUTUNG') ?></th><th><?= ko_t('TEXT.TH_WERTE') ?></th></tr>
<tr><td class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/dienst</td><td><?= ko_t('TEXT.T_DIENST') ?></td><td><?= ko_t('TEXT.V_DIENST') ?></td></tr>
<tr><td class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/autostart</td><td><?= ko_t('TEXT.AUTOSTART') ?></td><td><?= ko_t('TEXT.V_AUTOSTART') ?></td></tr>
<tr><td class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/zeitstempel</td><td><?= ko_t('TEXT.T_ZEIT') ?></td><td><?= ko_t('TEXT.V_ZEIT') ?></td></tr>
<tr><td class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/status</td><td><?= ko_t('TEXT.T_STATUS') ?></td><td>play, pause, stop</td></tr>
<tr><td class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/titel</td><td><?= ko_t('TEXT.T_TITEL') ?></td><td><?= ko_t('TEXT.V_TITEL') ?></td></tr>
</table>
<div class="sm-small"><?= ko_t('TEXT.THEMEN_HINWEIS') ?></div>

<h2><?= ko_t('TEXT.H_STEUERN') ?></h2>
<div class="sm-small"><?= ko_t('TEXT.STEUERN_TEXT') ?></div>
<div class="sm-step sm-mono">http://<?= ko_e($ko_cfg['kodi_host']) ?>:<?= (int) $ko_cfg['kodi_port'] ?>/jsonrpc</div>
<div class="sm-small"><?= ko_t('TEXT.VORLAGE_HINWEIS') ?></div>

</div>

<!-- ================= Test ================= -->
<div class="sm-pane<?= ko_aktiv('tab-test') ?>" id="tab-test">

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ko_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ko_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ko_t('LEGENDE.AKTION') ?></span>
</div>

<h3 class="sm-h3"><?= ko_t('TEXT.H3_ANSEHEN') ?></h3>
<div class="sm-knopfreihe">
    <a class="sm-btn sm-b-lesen" href=""><?= ko_t('TEXT.K_STATUS_NEU') ?></a>
    <a class="sm-btn sm-b-lesen" href="http://<?= ko_e($ko_cfg['kodi_host'] === '127.0.0.1' ? $ko_host : $ko_cfg['kodi_host']) ?>:<?= (int) $ko_cfg['kodi_port'] ?>" target="_blank"><?= ko_t('TEXT.K_WEBINTERFACE') ?></a>
</div>

<h3 class="sm-h3"><?= ko_t('TEXT.H3_TECHNIK') ?></h3>
<div class="sm-knopfreihe">
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="rawstatus" value="1"><?= ko_t('TEXT.K_ROHDATEN') ?></button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="servicestatus" value="1"><?= ko_t('TEXT.K_DIENSTZUSTAND') ?></button></form>
</div>

<h3 class="sm-h3"><?= ko_t('TEXT.H3_AKTION') ?></h3>
<div class="sm-knopfreihe">
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="service" value="start"><?= ko_t('TEXT.K_START') ?></button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="service" value="stop"><?= ko_t('TEXT.K_STOP') ?></button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="service" value="restart"><?= ko_t('TEXT.K_NEUSTART') ?></button></form>
    <form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mqtttest" value="1"><?= ko_t('TEXT.K_MQTTTEST') ?></button></form>
</div>

<?php if ($ko_raw !== '') { ?>
<h2><?= ko_t('TEXT.H_AUSGABE') ?></h2>
<div class="sm-log"><?= ko_e($ko_raw) ?></div>
<?php } ?>

</div>

<!-- ================= Protokoll ================= -->
<div class="sm-pane<?= ko_aktiv('tab-log') ?>" id="tab-log">
<h2><?= ko_t('REITER.LOG') ?></h2>
<?php
$ko_zeilen = is_file($ko_logfile) ? @file($ko_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
if ($ko_zeilen) {
    $ko_zeilen = array_slice($ko_zeilen, -300);
    echo '<div class="sm-log">' . ko_e(implode("\n", array_reverse($ko_zeilen))) . '</div>';
} else { ?>
<div class="sm-alert sm-info"><?= ko_t('TEXT.KEIN_PROTOKOLL') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ko_t('LEGENDE.AKTION') ?></span>
</div>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <?php /* Orange, nicht rot: Rot ist im Hausstandard nicht vorgesehen -
       es liest sich als Warnung vor einer Gefahr, und ein geleertes
       Protokoll ist keine. Die Farbe sagt nur: dieser Knopf veraendert
       etwas. */ ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ko_t('TEXT.K_LOG_LEEREN') ?></button>
</form>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ereignis) {
            // Ohne JavaScript folgt der Browser dem href, und der Server
            // liefert den richtigen Reiter. Mit JavaScript geht es schneller
            // ohne Neuladen - deshalb hier den Verweis abfangen.
            ereignis.preventDefault();
            activate(t.dataset.pane);
        });
    });
    activate(<?= json_encode($ko_tab) ?>);
})();
</script>
<?php
if ($ko_frame) { LBWeb::lbfooter(); }
