<?php
/**
 * Kodi NG - Statussender
 *
 * WOZU ES IHN GIBT
 * Bis 1.1.9 hatte dieses Plugin keinen Cron, keinen Dienst und keinen
 * Endpunkt. Die drei Themen kodi/dienst, kodi/autostart und kodi/zeitstempel
 * wurden an GENAU EINER Stelle veroeffentlicht: im Reiter Test, wenn jemand
 * auf "Test-Ereignis an MQTT senden" drueckte. Die mitgelieferte
 * Loxone-Vorlage legte fuer eben diese Werte virtuelle Eingaenge an - die im
 * Betrieb nie einen Wert bekamen. In Loxone sieht das aus wie "Kodi ist aus",
 * nicht wie "hier sendet niemand".
 *
 * AUFRUF
 *     php kodi_ng_status.php              regulaerer Lauf (aus dem Cron)
 *     php kodi_ng_status.php --jetzt      Takt uebergehen, sofort senden
 *     php kodi_ng_status.php --trocken    alles messen, NICHTS senden
 *
 * --jetzt uebergeht den TAKT und NICHT den Schalter. Bis zur zweiten Sicht
 * stand hier eine Bedingung, die beides zusammenfasste: der Knopf im Reiter
 * Test haette damit retained-Themen im Broker angelegt, obwohl der Anwender
 * den Sender ausgeschaltet hat. --trocken misst dagegen in jeder Lage; es
 * sendet ohnehin nichts.
 *
 * $_GET wird unter der Kommandozeile nicht aus QUERY_STRING gefuellt - die
 * Schalter kommen deshalb aus $argv.
 *
 * IM NORMALFALL SCHWEIGT DIESES SKRIPT. Der Cron schreibt in den
 * Systemlogger; ein Skript, das jede Minute eine Zeile absetzt, macht das
 * Protokoll unlesbar. Gemeldet wird nur, was schiefging - und dann laut.
 */

/* Die Bibliothek liegt NEBEN dieser Datei.
 *
 * Kein require in den Web-Baum: auf dem installierten LoxBerry liegen bin/
 * und webfrontend/ in getrennten Baeumen, ein dirname(__DIR__) . '/webfrontend/…'
 * geht nur im entpackten Archiv auf. Genau daran ist der Hintergrunddienst
 * des Abfahrts-Assistenten acht Fassungen lang bei jedem Lauf abgebrochen,
 * ohne dass es auffiel. Deshalb liegt ko_lib.php hier und nicht dort - und
 * wenn sie fehlt, wird es GESAGT statt stillschweigend weitergelaufen. */
$ko_lib = __DIR__ . '/ko_lib.php';
if (!is_file($ko_lib)) {
    fwrite(STDERR, "Kodi NG: ko_lib.php fehlt - gesucht wurde " . $ko_lib . "\n");
    exit(1);
}
require_once $ko_lib;

$ko_argv    = isset($argv) && is_array($argv) ? $argv : array();
$ko_jetzt   = in_array('--jetzt', $ko_argv, true);
$ko_trocken = in_array('--trocken', $ko_argv, true);

$ko_p = ko_paths();
$ko_cfg = ko_config();

/* Ist der Sender ausgeschaltet, hat dieser Lauf nichts zu tun.
 *
 * Ab Werk ist er AUS. Ein Update darf einer bestehenden Anlage nicht ungefragt
 * Themen in den Broker legen; der Reiter Test sagt deutlich, dass er aus ist,
 * damit niemand vergeblich auf Werte wartet. */
if ((string) $ko_cfg['sender_ein'] !== '1' && !$ko_trocken) {
    /* Der Schalter steht ueber dem Takt. --jetzt hilft hier NICHT: wer den
     * Sender ausgeschaltet hat, will keine Themen im Broker haben, und ein
     * Knopf, der das uebergeht, aendert das Verhalten ungefragt. Der Reiter
     * Test sagt statt dessen, dass der Sender aus ist. */
    exit(0);
}

/* Eine Sperre, und zwar EINE fuer alle Laeufe.
 *
 * Der Cron startet minuetlich. Ein Lauf mit drei JSON-RPC-Aufrufen und je vier
 * Sekunden Zeitschranke kann laenger dauern; ohne Sperre lauefen dann zwei
 * uebereinander und schreiben sich die Zustandsdatei kaputt. */
$ko_sperre = $ko_p['data'] . '/kodi_ng_status.lock';
if (!is_dir(dirname($ko_sperre))) { @mkdir(dirname($ko_sperre), 0775, true); }
$ko_lock = @fopen($ko_sperre, 'c');
if ($ko_lock === false) {
    fwrite(STDERR, "Kodi NG: Sperrdatei " . $ko_sperre . " liess sich nicht anlegen.\n");
    exit(1);
}
if (!flock($ko_lock, LOCK_EX | LOCK_NB)) {
    // Ein Lauf ist noch unterwegs. Das ist kein Fehler und keine Zeile wert.
    fclose($ko_lock);
    exit(0);
}

$ko_zustand = ko_json_lesen($ko_p['zustand']);
$ko_letzte  = isset($ko_zustand['gesendet_ts']) ? (int) $ko_zustand['gesendet_ts'] : 0;
$ko_takt    = (int) $ko_cfg['sender_takt'];
if ($ko_takt < 60) { $ko_takt = 60; }

$ko_jetzt_ts = time();
/* Fuenf Sekunden Nachsicht.
 *
 * Der Cron feuert zur vollen Minute, time() kann eine Sekunde spaeter liegen.
 * Ohne die Nachsicht waere die Differenz beim faelligen Lauf 299 < 300, der
 * Lauf fiele aus, und der naechste kaeme erst eine Minute spaeter - der Takt
 * schwankte sichtbar zwischen fuenf und sechs Minuten. */
if (!$ko_jetzt && !$ko_trocken && $ko_letzte > 0
    && ($ko_jetzt_ts - $ko_letzte) < ($ko_takt - 5)) {
    /* Der Takt ist noch nicht um. Der uebersprungene Lauf ist KEIN Fehler -
     * genau dafuer steht der Abstand in der Konfiguration und nicht im
     * Ordnernamen des Cron. */
    flock($ko_lock, LOCK_UN);
    fclose($ko_lock);
    exit(0);
}

/* ---------- messen ---------- */

$ko_st = ko_status();
if (!$ko_st) {
    /* Der Helfer hat nicht geantwortet. Das ist ein Fehler und wird gemeldet -
     * ein Waechter, der schweigend nichts tut, ist schlimmer als keiner. Es
     * werden trotzdem die Werte gesendet, die feststehen: ein ausgebliebener
     * Wert ist in Loxone von einer 0 nicht zu unterscheiden, ein FEHLENDER
     * dagegen laesst den alten Wert stehen - deshalb geht wenigstens der
     * Zeitstempel hinaus, an dem sich das Alter ablesen laesst. */
    ko_log('Statussender: der Helfer (elevatedhelper.pl) hat nicht geantwortet.');
}

$ko_dienst    = isset($ko_st['kodistarted'])   ? (int) $ko_st['kodistarted']   : null;
$ko_autostart = isset($ko_st['kodiautostart']) ? (int) $ko_st['kodiautostart'] : null;

$ko_erreichbar = null;
$ko_wiedergabe = null;
$ko_titel      = null;
$ko_rpc_meldung = '';

if ((string) $ko_cfg['rpc_ein'] === '1') {
    $z = ko_kodi_zustand(4);
    $ko_erreichbar  = (int) $z['erreichbar'];
    $ko_rpc_meldung = (string) $z['meldung'];
    /* "-" heisst NICHT FESTSTELLBAR und ist von "stop" verschieden. Ein
     * Textthema, das den Unterschied verwischt, waere eine stille
     * Falschaussage: stop ist eine Aussage ueber Kodi, "-" eine ueber uns. */
    $ko_wiedergabe = $z['wiedergabe'];
    $ko_titel      = $z['titel'];
}

/* ---------- senden ---------- */

$ko_zaehler = isset($ko_zustand['herzschlag']) ? (int) $ko_zustand['herzschlag'] : 0;
/* Ein ZAEHLER, kein Alter. Ein Alter uebersteht keinen Zeitsprung - wird die
 * Uhr des LoxBerry gestellt, springt es. Der Zaehler zaehlt Zustellungen,
 * nicht Schleifendurchlaeufe: er wird erst NACH dem erfolgreichen Senden
 * erhoeht. */
$ko_werte = array(
    'dienst'      => $ko_dienst,
    'autostart'   => $ko_autostart,
    'erreichbar'  => $ko_erreichbar,
    'wiedergabe'  => $ko_wiedergabe,
    'titel'       => $ko_titel,
    'zeitstempel' => $ko_jetzt_ts,
    'herzschlag'  => $ko_zaehler + 1,
);

if ($ko_trocken) {
    foreach ($ko_werte as $k => $v) {
        echo $ko_cfg['mqtt_topic'] . '/' . $k . ' = '
           . ($v === null ? '(nicht feststellbar - wird nicht gesendet)' : $v) . "\n";
    }
    if ($ko_rpc_meldung !== '') { echo 'Kodi: ' . $ko_rpc_meldung . "\n"; }
    flock($ko_lock, LOCK_UN);
    fclose($ko_lock);
    exit(0);
}

list($ko_n, $ko_meldung) = ko_mqtt_publish($ko_werte);

if ($ko_n > 0) {
    ko_json_schreiben($ko_p['zustand'], array(
        'gesendet_ts'  => $ko_jetzt_ts,
        'herzschlag'   => $ko_zaehler + 1,
        'anzahl'       => $ko_n,
        'dienst'       => $ko_dienst,
        'autostart'    => $ko_autostart,
        'erreichbar'   => $ko_erreichbar,
        'wiedergabe'   => $ko_wiedergabe,
        'titel'        => $ko_titel,
        'rpc_meldung'  => $ko_rpc_meldung,
    ), 0644);
    flock($ko_lock, LOCK_UN);
    fclose($ko_lock);
    exit(0);
}

/* Nichts gesendet. Das gehoert laut gesagt: in die eigene Protokolldatei UND
 * auf die Fehlerausgabe, damit es der Cron in den Systemlogger schreibt.
 * Die Zustandsdatei wird dabei NICHT aufgefrischt - sonst saehe ein
 * fehlgeschlagener Lauf im Reiter Test aus wie ein gelungener. */
$ko_text = 'Kodi NG: der Statussender konnte nichts veroeffentlichen'
         . ($ko_meldung !== '' ? ' (' . $ko_meldung . ')' : '') . '.';
ko_log($ko_text);
fwrite(STDERR, $ko_text . "\n");
flock($ko_lock, LOCK_UN);
fclose($ko_lock);
exit(1);
