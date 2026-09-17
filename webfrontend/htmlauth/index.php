<?php
/**
 * Kodi NG fuer LoxBerry - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * Grundlage: LoxBerry-Plugin-Kodi 0.1.2 von Christian Fenzl (christianTF).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS und wuerde gleichnamige
 * Plugin-Variablen ueberschreiben - daher tragen hier ALLE Variablen ein
 * ko_-Praefix.
 *
 * Diese Datei ist NUR Bedienoberflaeche. Pfade, Konfiguration, MQTT, Kodi und
 * die Vorlagen stehen in bin/ko_lib.php, die Selbstpruefung und die Aktionen
 * des Reiters Test in ko_test.php daneben.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* Fehler NICHT auf die Seite. Bis 1.1.9 stand hier '1'; im Fehlerfall standen
 * damit Pfade und Quelltextausschnitte in der Oberflaeche. Was schiefgeht,
 * gehoert ins Protokoll des Webservers und in den Reiter Logdateien. */
ini_set('display_errors', '0');

/* Die Bibliothek liegt unter bin/, nicht neben dieser Datei.
 *
 * Auf dem installierten LoxBerry liegen bin/ und webfrontend/htmlauth/ in
 * getrennten Baeumen - eine feste Zahl ".." trifft den anderen nicht. Gesucht
 * wird deshalb ueber eine Kandidatenliste, und findet keiner etwas, wird
 * GESAGT welche Datei wo gesucht wurde. Ein stiller Ausfall saehe aus wie
 * eine leere Seite. */
$ko_kandidaten = array();
if (getenv('LBPBINDIR')) { $ko_kandidaten[] = getenv('LBPBINDIR') . '/ko_lib.php'; }
if (getenv('LBHOMEDIR') && getenv('LBPPLUGINDIR')) {
    $ko_kandidaten[] = getenv('LBHOMEDIR') . '/bin/plugins/' . getenv('LBPPLUGINDIR') . '/ko_lib.php';
}
// Aus dem eigenen Ort: webfrontend/htmlauth/plugins/<ordner>/ liegt vier
// Ebenen unter der Wurzel, bin/plugins/<ordner>/ drei.
$ko_kandidaten[] = dirname(dirname(dirname(dirname(__DIR__))))
                 . '/bin/plugins/' . basename(__DIR__) . '/ko_lib.php';
// Entpacktes Archiv: bin/ liegt neben webfrontend/.
$ko_kandidaten[] = dirname(dirname(__DIR__)) . '/bin/ko_lib.php';

$ko_lib = '';
foreach ($ko_kandidaten as $ko_k) {
    if (is_file($ko_k)) { $ko_lib = $ko_k; break; }
}
if ($ko_lib === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Kodi NG</h2><p><b>ko_lib.php wurde nicht gefunden.</b> '
       . 'Gesucht wurde an diesen Stellen:</p><ul>';
    foreach ($ko_kandidaten as $ko_k) {
        echo '<li><code>' . htmlspecialchars($ko_k, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul><p>Meist hilft ein erneutes Installieren des Plugins.</p>';
    exit;
}
require_once $ko_lib;
require_once __DIR__ . '/ko_test.php';

if (class_exists('LBSystem', false) === false) {
    $ko_sdk = ko_paths()['home'] . '/libs/phplib/loxberry_system.php';
    if (ko_paths()['home'] !== '' && file_exists($ko_sdk)) {
        require_once $ko_sdk;
        require_once ko_paths()['home'] . '/libs/phplib/loxberry_web.php';
    }
}

/* ================= Reiter: EINE Quelle =================
   Diese Liste steht genau einmal und AUSGESCHRIEBEN. Rechnete man sie aus
   einem Feld, saehe hausstandard_pruefen.py sie nicht mehr - und ein "-" in
   der Spalte tab sammelt sich beim Ueberfliegen wie ein Haken ein. Dass sie
   dadurch von der Leiste und den Bereichen abweichen KANN, ist der Preis;
   dagegen steht keine Hoffnung, sondern die Zeile im Reiter Test, die alle
   drei Stellen gegeneinander haelt. */
$ko_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');

$ko_tab = 'tab-settings';
// is_string zuerst: mit activetab[]=x waere (string) eine Umwandlung eines
// Feldes und meldete das auch. Alle uebrigen Zweige dieser Datei pruefen so.
if (isset($_POST['activetab']) && is_string($_POST['activetab'])
    && in_array($_POST['activetab'], $ko_reiter, true)) {
    $ko_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && in_array('tab-' . $_GET['form'], $ko_reiter, true)) {
    // is_string auch hier - bis 1.2.6 fehlte es genau in diesem Zweig, und
    // index.php?form[]=x schrieb unter PHP 8.4 bei jedem Aufruf eine Warnung.
    $ko_tab = 'tab-' . $_GET['form'];
}

/** Klasse fuer den gerade sichtbaren Reiter bzw. Bereich. */
function ko_aktiv($id) { global $ko_tab; return $ko_tab === $id ? ' sm-active' : ''; }

/** Kam die Anfrage als POST?
 *
 * Mit isset, nicht blank: $_SERVER['REQUEST_METHOD'] gibt es unter der
 * Kommandozeile NICHT, und diese Datei wird dort aufgerufen - von
 * installationslage_pruefen.py und von jedem Prueflauf. Der gemeinsame
 * Vorlauf des Pruefstands setzt die Variable selbst nach und hat den Zugriff
 * damit VERDECKT; unter PHP 8.4 ist er eine Warnung. Ein Pruefstand, der die
 * Lage bequemer aufbaut als sie ist, bestaetigt den Fehler, statt ihn zu
 * finden. */
function ko_ist_post()
{
    return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** Einen Schluessel ueber den Helfer setzen - und die ANTWORT lesen.
 *
 *  Bis 1.2.6 wurde sie an allen vier action=change-Stellen verworfen. Der
 *  Helfer antwortet seit 1.2.0 ausdruecklich mit "Error", wenn das Schreiben
 *  scheitert - genau damit die Oberflaeche nicht mehr "eingeschaltet" meldet,
 *  was nicht eingeschaltet ist. Die Falle stand eine Ebene hoeher noch da:
 *  bei nicht schreibbarer config.txt zeigte die Seite "Gespeichert", und ins
 *  Protokoll ging "Lizenzschluessel gesetzt".
 *
 *  Rueckgabe: '' bei Erfolg, sonst der Grund. */
function ko_helfer_setzen($schluessel, $wert)
{
    $j = ko_helper_json('action=change key=' . escapeshellarg((string) $schluessel)
        . ' value=' . escapeshellarg((string) $wert));
    if (!is_array($j)) { return ko_t('MELDUNG.HELFER_STUMM'); }
    if (isset($j['status']) && $j['status'] === 'OK') { return ''; }
    return isset($j['reason']) ? (string) $j['reason']
        : (isset($j['status']) ? (string) $j['status'] : '?');
}

$ko_saved = false;
$ko_err = '';
$ko_note = '';
$ko_raw = '';
$ko_rawtitel = '';
$ko_beanstandungen = array();

/* ============ EIN Wachposten vor allen Handlern ============
 *
 * Nicht elf Abfragen in elf Zweigen: ein Formular ohne gueltiges Merkmal wird
 * hier entwertet, danach kann kein Handler mehr greifen. Einen einzelnen
 * Handler kann man beim Erweitern vergessen, einen Wachposten am Eingang
 * nicht.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass der
 * Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Die Anmeldung geht dabei automatisch mit.
 *
 * Verglichen wird mit hash_equals: ein einfaches == liesse sich ueber die
 * Antwortzeit Zeichen fuer Zeichen erraten.
 *
 * $_FILES wird MIT geleert - sonst kaeme der Rueckspielzweig durch. */
$ko_fmt_wert = ko_formkey();
if (ko_ist_post()) {
    $ko_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($ko_fmt_wert === '' || !hash_equals($ko_fmt_wert, $ko_mit)) {
        $ko_err = ko_t('MELDUNG.FREMDES_FORMULAR');
        ko_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
        // Den aktiven Reiter behalten - der Anwender soll die Meldung dort
        // sehen, wo er war.
        $ko_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        $_FILES = array();
        if ($ko_behalten !== null) { $_POST['activetab'] = $ko_behalten; }
    }
}

/* ---------------- Downloads: eigene Zweige, vor allem anderen ----------------
   Sie schicken eine Datei und rufen exit auf. Wer sie mit dem Speichern in ein
   Formular legte, bekaeme entweder keinen Upload oder einen Download, der das
   Speichern verschluckt. */

if (ko_ist_post() && isset($_POST['vorlage'])) {
    list($ko_vname, $ko_vinhalt) = ($_POST['vorlage'] === 'vo')
        ? ko_vorlage_vo() : ko_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $ko_vname . '"');
    header('Content-Length: ' . strlen($ko_vinhalt));
    echo $ko_vinhalt;
    exit;
}

if (ko_ist_post() && isset($_POST['sichern'])) {
    $ko_txt = ko_sicherung_text();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="kodi_ng_einstellungen.txt"');
    header('Content-Length: ' . strlen($ko_txt));
    echo $ko_txt;
    exit;
}

if (ko_ist_post()) {

    /* ============ Einstellungen zurueckspielen ============ */
    if (isset($_POST['laden'])) {
        $ko_tab = 'tab-settings';
        /* is_string VOR is_uploaded_file.
         *
         * Heisst das Formularfeld sicherung[] statt sicherung, baut PHP
         * tmp_name als FELD. isset() ist dann wahr, und is_uploaded_file()
         * wirft unter PHP 8.4 einen TypeError - den das @-Zeichen NICHT
         * auffaengt, weil er kein Fehler alter Art ist. Mit display_errors=0
         * bekaeme der Anwender eine leere Seite. Gemessen: 7.4 liefert brav
         * false, 8.4 bricht ab. */
        if (!isset($_FILES['sicherung']) || !is_array($_FILES['sicherung'])
            || !isset($_FILES['sicherung']['tmp_name'])
            || !is_string($_FILES['sicherung']['tmp_name'])
            || !isset($_FILES['sicherung']['size'])
            || !is_scalar($_FILES['sicherung']['size'])
            || !@is_uploaded_file($_FILES['sicherung']['tmp_name'])) {
            $ko_err = ko_t('SICH.M_KEINE_DATEI');
        } elseif ((int) $_FILES['sicherung']['size'] > 65536) {
            $ko_err = ko_t('SICH.M_ZU_GROSS');
        } else {
            $ko_roh = ko_lesen($_FILES['sicherung']['tmp_name']);
            list($ko_neu, $ko_mangel) = ko_sicherung_einlesen($ko_roh);
            if ($ko_neu === null) {
                // Eine halb gueltige Datei ueberschreibt NICHTS.
                $ko_beanstandungen = $ko_mangel;
                $ko_err = ko_t('SICH.M_ABGELEHNT');
            } else {
                $ko_meldungen = array();
                $ko_cfg = ko_config();
                foreach ($ko_neu['cfg'] as $ko_k => $ko_v) { $ko_cfg[$ko_k] = $ko_v; }
                if (!ko_config_schreiben($ko_cfg)) {
                    $ko_err = sprintf(ko_t('MELDUNG.SCHREIBFEHLER'), ko_e(ko_paths()['config']));
                } else {
                    $ko_saved = true;
                    ko_config(true);
                    $ko_meldungen[] = sprintf(ko_t('SICH.U_KONFIG'), count($ko_neu['cfg']));

                    /* Autostart nachziehen - und die Antwort lesen. */
                    if ($ko_neu['autostart'] !== null) {
                        $ko_grund = ko_helfer_setzen('kodiautostart', (int) $ko_neu['autostart']);
                        $ko_meldungen[] = $ko_grund === ''
                            ? sprintf(ko_t('SICH.U_AUTOSTART'),
                                $ko_neu['autostart'] ? ko_t('ALLG.EIN') : ko_t('ALLG.AUS'))
                            : sprintf(ko_t('SICH.U_AUTOSTART_FEHL'), ko_e($ko_grund));
                    }
                    /* Und sagen, was mit dem Statussender geschah - er liest
                     * die Konfiguration beim naechsten Cron-Durchgang. */
                    $ko_meldungen[] = sprintf(ko_t('SICH.U_SENDER'),
                        (string) ko_config()['sender_ein'] === '1' ? ko_t('ALLG.EIN') : ko_t('ALLG.AUS'));

                    /* Die Addon-Felder. Sie lassen sich nur schreiben, wenn
                     * Kodi steht - sonst schreibt Kodi beim Beenden seine
                     * eigene Datei zurueck und macht die Aenderung
                     * ungeschehen. Statt still zu scheitern, wird gesagt was
                     * ist. */
                    if ($ko_neu['addon']) {
                        $ko_st = ko_status(true);
                        if (!empty($ko_st['kodistarted'])) {
                            $ko_meldungen[] = sprintf(ko_t('SICH.U_ADDON_LAEUFT'), count($ko_neu['addon']));
                        } else {
                            $ko_args = array('action=addonwrite');
                            foreach ($ko_neu['addon'] as $ko_k => $ko_v) {
                                $ko_args[] = escapeshellarg('a_' . $ko_k . '=' . rawurlencode($ko_v));
                            }
                            // ko_helper_json schneidet einen Kopfblock ab; bis
                            // 1.2.6 scheiterte json_decode hier an "Status: 200".
                            $ko_a = ko_helper_json(implode(' ', $ko_args));
                            $ko_meldungen[] = (is_array($ko_a) && isset($ko_a['status']) && $ko_a['status'] === 'OK')
                                ? sprintf(ko_t('SICH.U_ADDON'), count($ko_neu['addon']))
                                : sprintf(ko_t('SICH.U_ADDON_FEHL'),
                                    ko_e(is_array($ko_a) && isset($ko_a['reason']) ? $ko_a['reason'] : '?'));
                        }
                    }

                    /* Die Codec-Lizenzen: nur auf ausdruecklichen Wunsch UND
                     * nur bei uebereinstimmender Seriennummer. Sie gelten fuer
                     * genau einen Pi; auf einem zweiten LoxBerry waeren sie
                     * wertlos, und eine falsche Zeile in der config.txt merkt
                     * niemand, bis der Rechner nicht mehr startet. */
                    if ($ko_neu['lizenz']) {
                        if (empty($_POST['lizenz_auch'])) {
                            $ko_meldungen[] = sprintf(ko_t('SICH.U_LIZENZ_UEBERGANGEN'),
                                count(array_diff_key($ko_neu['lizenz'], array('lizenz_serie' => 1))));
                        } else {
                            $ko_st = ko_status(true);
                            $ko_hier = isset($ko_st['piserial']) ? trim((string) $ko_st['piserial']) : '';
                            $ko_dort = isset($ko_neu['lizenz']['lizenz_serie'])
                                ? $ko_neu['lizenz']['lizenz_serie'] : '';
                            if ($ko_dort === '' || $ko_hier === '' || $ko_hier === 'Not found') {
                                $ko_meldungen[] = ko_t('SICH.U_LIZENZ_UNBEKANNT');
                            } elseif ($ko_hier !== $ko_dort) {
                                $ko_meldungen[] = sprintf(ko_t('SICH.U_LIZENZ_FREMD'),
                                    ko_e($ko_dort), ko_e($ko_hier));
                            } else {
                                $ko_gesetzt = 0;
                                foreach (array('lizenz_mpeg2' => 'licmpeg2', 'lizenz_vc1' => 'licvc1')
                                         as $ko_q => $ko_key) {
                                    if (!isset($ko_neu['lizenz'][$ko_q])) { continue; }
                                    $ko_grund = ko_helfer_setzen($ko_key, $ko_neu['lizenz'][$ko_q]);
                                    // Gezaehlt wird, was GELANG, nicht was versucht wurde.
                                    if ($ko_grund === '') { $ko_gesetzt++; }
                                    else {
                                        $ko_meldungen[] = sprintf(ko_t('SICH.U_LIZENZ_FEHL'),
                                            ko_e($ko_key), ko_e($ko_grund));
                                    }
                                }
                                // Dieselbe Klasse wie im Speichern-Zweig: eben
                                // geschaltet, also den Zustand frisch holen.
                                ko_status(true);
                                $ko_meldungen[] = sprintf(ko_t('SICH.U_LIZENZ'), $ko_gesetzt);
                            }
                        }
                    }

                    ko_log('Sicherung zurueckgespielt: ' . implode(' ', $ko_meldungen));
                    $ko_note = ko_t('SICH.M_UEBERNOMMEN') . ' ' . implode(' ', $ko_meldungen);
                }
            }
        }
    }

    /* ============ Einstellungen speichern ============ */
    if (isset($_POST['save'])) {
        $ko_cfg = ko_config();
        $ko_mangel = array();
        /* Beanstandungen melden, nicht das ganze Speichern verhindern: was
         * durchgeht, wird uebernommen, und der Anwender sieht, was nicht. */
        foreach (array('kodi_host', 'kodi_port', 'kodi_user') as $ko_k) {
            if (!isset($_POST[$ko_k]) || !is_string($_POST[$ko_k])) { continue; }
            $ko_v = ko_wert_pruefen($ko_k, $_POST[$ko_k]);
            if ($ko_v === null) {
                $ko_mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($ko_k),
                    ko_e(substr((string) $_POST[$ko_k], 0, 40)));
                continue;
            }
            $ko_cfg[$ko_k] = $ko_v;
        }
        /* DAS PASSWORT: ein leeres Feld loescht nichts (Regeln/04, 05).
         *
         * Der Browser fuellt type=password nicht vor - und seit 1.2.7 steht
         * das gespeicherte Passwort auch nicht mehr im Quelltext der Seite.
         * Bis 1.2.6 hiess "nur den Benutzernamen aendern und speichern":
         * Passwort geloescht. Geloescht wird jetzt ueber den Haken daneben. */
        if (!empty($_POST['kodi_pass_loeschen'])) {
            $ko_cfg['kodi_pass'] = '';
        } elseif (isset($_POST['kodi_pass']) && is_string($_POST['kodi_pass'])
                  && $_POST['kodi_pass'] !== '') {
            $ko_v = ko_wert_pruefen('kodi_pass', $_POST['kodi_pass']);
            if ($ko_v === null || $ko_v === '') {
                $ko_mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), 'kodi_pass', '***');
            } else {
                $ko_cfg['kodi_pass'] = $ko_v;
            }
        }
        /* Haken: ein nicht angehaktes Kaestchen kommt gar nicht mit. Das ist
         * kein "unveraendert", sondern eine 0 - aber nur, wenn das Formular
         * ueberhaupt dieses Feld fuehrt. Deshalb der versteckte Begleiter
         * hat_<name>, der immer mitkommt. Der Statussender steht seit 1.2.7
         * im Reiter MQTT und hat dort seinen eigenen Handler. */
        foreach (array('rpc_ein') as $ko_k) {
            if (empty($_POST['hat_' . $ko_k])) { continue; }
            $ko_cfg[$ko_k] = empty($_POST[$ko_k]) ? '0' : '1';
        }

        /* Lizenzschluessel: ein leeres Feld loescht nichts. */
        $ko_st = ko_status();
        foreach (array('licmpeg2' => 'mpeg2lic', 'licvc1' => 'vc1lic') as $ko_key => $ko_feld) {
            if (!isset($_POST[$ko_key]) || !is_string($_POST[$ko_key])) { continue; }
            $ko_lneu = trim((string) $_POST[$ko_key]);
            if ($ko_lneu === '') { continue; }
            if ($ko_lneu === (string) (isset($ko_st[$ko_feld]) ? $ko_st[$ko_feld] : '')) { continue; }
            if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $ko_lneu)) {
                $ko_mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($ko_key), ko_e(substr($ko_lneu, 0, 40)));
                continue;
            }
            $ko_grund = ko_helfer_setzen($ko_key, $ko_lneu);
            if ($ko_grund === '') {
                ko_log('Lizenzschluessel ' . $ko_key . ' gesetzt.');
            } else {
                $ko_mangel[] = sprintf(ko_t('MELDUNG.HELFER_ABGELEHNT'), ko_e($ko_key), ko_e($ko_grund));
                ko_log('Lizenzschluessel ' . $ko_key . ' NICHT gesetzt: ' . $ko_grund);
            }
        }

        if (isset($_POST['hat_kodiautostart'])) {
            $ko_soll_auto = empty($_POST['kodiautostart']) ? '0' : '1';
            // Nur schalten, wenn sich etwas aendert - und die Antwort lesen.
            $ko_ist_auto = ko_status();
            if (!isset($ko_ist_auto['kodiautostart'])
                || (string) (int) $ko_ist_auto['kodiautostart'] !== $ko_soll_auto) {
                $ko_grund = ko_helfer_setzen('kodiautostart', $ko_soll_auto);
                if ($ko_grund !== '') {
                    $ko_mangel[] = sprintf(ko_t('MELDUNG.HELFER_ABGELEHNT'), 'kodiautostart', ko_e($ko_grund));
                }
            }
        }

        /* DEN ZUSTAND FRISCH HOLEN.
         *
         * ko_status() merkt sich seine Antwort. In diesem Zweig sind gerade
         * der Autostart und womoeglich die Codec-Lizenzen geschaltet worden -
         * ohne diese Zeile zeigte die Seite danach den Stand von VORHER:
         * gemessen stand die Kachel auf "Autostart aus", das Kaestchen ohne
         * Haken und die Pruefzeile auf "nein", waehrend der Helfer sehr wohl
         * geschaltet hatte. Der Anwender hakt dann erneut an und schaltet
         * damit wieder zurueck. */
        ko_status(true);

        if (ko_config_schreiben($ko_cfg)) {
            $ko_saved = true;
            ko_config(true);
            ko_log('Konfiguration gespeichert.');
        } else {
            $ko_err = sprintf(ko_t('MELDUNG.SCHREIBFEHLER'), ko_e(ko_paths()['config']));
        }
        if ($ko_mangel) { $ko_beanstandungen = $ko_mangel; }
    }

    /* ============ MQTT speichern ============ */
    if (isset($_POST['mqtt_save'])) {
        /* Seit 1.2.7 fuehrt dieses Formular ALLE MQTT-Einstellungen: Thema,
         * Statussender und seinen Takt (Regeln/04, "MQTT wohnt vollstaendig
         * im Reiter MQTT"). Bis 1.2.6 standen Sender und Takt im Reiter
         * Einstellungen. Beanstandungen melden, nicht alles verhindern: was
         * durchgeht, wird uebernommen. */
        $ko_cfg = ko_config();
        $ko_mangel = array();
        foreach (array('mqtt_topic', 'sender_takt') as $ko_k) {
            if (!isset($_POST[$ko_k])) { continue; }
            $ko_v = is_string($_POST[$ko_k]) ? ko_wert_pruefen($ko_k, $_POST[$ko_k]) : null;
            if ($ko_v === null) {
                $ko_gezeigt = is_string($_POST[$ko_k]) ? substr($_POST[$ko_k], 0, 40) : '';
                $ko_mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($ko_k), ko_e($ko_gezeigt));
                continue;
            }
            $ko_cfg[$ko_k] = $ko_v;
        }
        if (!empty($_POST['hat_sender_ein'])) {
            $ko_cfg['sender_ein'] = empty($_POST['sender_ein']) ? '0' : '1';
        }
        if (ko_config_schreiben($ko_cfg)) {
            $ko_saved = true;
            ko_config(true);
            ko_log('MQTT-Einstellungen gespeichert (Statussender ' . $ko_cfg['sender_ein'] . ').');
        } else {
            $ko_err = sprintf(ko_t('MELDUNG.SCHREIBFEHLER'), ko_e(ko_paths()['config']));
        }
        if ($ko_mangel) { $ko_beanstandungen = $ko_mangel; }
        $ko_tab = 'tab-mqtt';
    }

    /* ============ Die Addon-Einstellungen setzen ============ */
    if (isset($_POST['addonschreiben'])) {
        $ko_tab = 'tab-mqtt';
        $ko_soll = ko_addon_soll();
        $ko_leer = array();
        foreach ($ko_soll as $ko_k => $ko_v) { if ($ko_v === '') { $ko_leer[] = $ko_k; } }
        if ($ko_leer) {
            /* Fail closed: was wir selbst nicht wissen, schreiben wir nicht.
             * Eine geratene IP im Addon waere schlimmer als gar keine - das
             * Addon sendete dann ins Leere, und die Oberflaeche behauptete,
             * es sei eingerichtet. */
            $ko_err = sprintf(ko_t('ADDON.M_UNVOLLSTAENDIG'), ko_e(implode(', ', $ko_leer)));
        } else {
            $ko_st = ko_status(true);
            $ko_lief = !empty($ko_st['kodistarted']);
            $ko_anhalten = !empty($_POST['addon_anhalten']);
            if ($ko_lief && !$ko_anhalten) {
                $ko_err = ko_t('ADDON.M_LAEUFT');
            } else {
                if ($ko_lief) { ko_helper('action=service key=kodi value=stop'); }
                $ko_args = array('action=addonwrite');
                foreach ($ko_soll as $ko_k => $ko_v) {
                    $ko_args[] = escapeshellarg('a_' . $ko_k . '=' . rawurlencode($ko_v));
                }
                /* ko_helper_json, nicht json_decode(ko_helper()): unter sudo
                 * schrieb der Helfer bis 1.2.6 "Status: 200 OK" und
                 * Content-Type VOR das JSON (gemessen am Geraet 17.09.2026),
                 * json_decode lieferte null, und dieser Knopf meldete immer
                 * einen Fehler - auch wenn geschrieben war. */
                $ko_a = ko_helper_json(implode(' ', $ko_args));
                $ko_ok = is_array($ko_a) && isset($ko_a['status']) && $ko_a['status'] === 'OK';
                /* Und die WIRKUNG messen, nicht den Rueckgabewert glauben:
                 * gleich noch einmal lesen und vergleichen. */
                /* Das true umgeht den Zwischenspeicher: ohne es antwortete
                 * der Stand von VOR dem Schreiben, und die Probe saehe
                 * immer gleich aus - egal ob etwas ankam. */
                $ko_ist = ko_addon_lesen(true);
                $ko_ab = array();
                if (is_array($ko_ist)) {
                    foreach ($ko_soll as $ko_k => $ko_v) {
                        if (!isset($ko_ist[$ko_k]) || (string) $ko_ist[$ko_k] !== (string) $ko_v) {
                            $ko_ab[] = $ko_k;
                        }
                    }
                }
                if ($ko_lief) { ko_helper('action=service key=kodi value=start'); }
                if (!$ko_ok) {
                    $ko_err = sprintf(ko_t('ADDON.M_FEHL'),
                        ko_e(is_array($ko_a) && isset($ko_a['reason']) ? $ko_a['reason'] : '?'));
                } elseif (!is_array($ko_ist)) {
                    $ko_note = ko_t('ADDON.M_GESCHRIEBEN_UNGEPRUEFT');
                } elseif ($ko_ab) {
                    $ko_err = sprintf(ko_t('ADDON.M_NACHGEMESSEN_ABWEICHUNG'), ko_e(implode(', ', $ko_ab)));
                } else {
                    $ko_note = sprintf(ko_t('ADDON.M_OK'), count($ko_soll))
                        . ($ko_lief ? ' ' . ko_t('ADDON.M_NEUGESTARTET') : '');
                    ko_log('Addon-Einstellungen gesetzt (' . count($ko_soll) . ' Felder).');
                }
            }
        }
    }

    /* ============ Dienst steuern ============ */
    if (isset($_POST['service']) && is_string($_POST['service'])) {
        $ko_was = (string) $_POST['service'];
        if (in_array($ko_was, array('start', 'stop', 'restart'), true)) {
            ko_helper('action=service key=kodi value=' . $ko_was);
            ko_log('Dienst: ' . $ko_was);
            /* Nicht der Rueckmeldung des Klicks glauben - nachsehen, was der
             * Dienst danach WIRKLICH tut. Kodi stuerzt ohne Grafiktreiber
             * erst nach ein, zwei Sekunden ab; deshalb kurz warten, bevor
             * gemessen wird - sonst steht "laeuft" da, und eine Minute spaeter
             * ist es falsch. */
            if ($ko_was !== 'stop') { sleep(3); }
            $ko_st = ko_status(true);
            $ko_dl = ko_dienst_lage(true);
            $ko_note = sprintf(ko_t('MELDUNG.BEFEHL_GESCHICKT'), '<b>' . ko_e($ko_was) . '</b>',
                '<b>' . ko_e(ko_dienst_text($ko_st, $ko_dl)) . '</b>');
        }
        $ko_tab = 'tab-settings';
    }

    /* ============ Test-Ereignis an MQTT ============ */
    if (isset($_POST['mqtttest'])) {
        $ko_st = ko_status();
        list($ko_n, $ko_meldung, $ko_versucht) = ko_mqtt_publish(array(
            'dienst'      => isset($ko_st['kodistarted']) ? (int) $ko_st['kodistarted'] : null,
            'autostart'   => isset($ko_st['kodiautostart']) ? (int) $ko_st['kodiautostart'] : null,
            'zeitstempel' => time(),
        ));
        /* Die Meldung nennt den WIRKLICHEN Grund. Bis 1.2.6 wurde er
         * verworfen, und der Text behauptete immer "UDP-Eingang nicht
         * gesetzt" - auch wenn der gesetzt war und nur der Socket scheiterte. */
        if ($ko_n > 0 && $ko_n === $ko_versucht) {
            $ko_note = sprintf(ko_t('MELDUNG.MQTT_GESENDET'), $ko_n,
                '<span class="sm-mono">' . ko_e(ko_config()['mqtt_topic']) . '/</span>');
        } elseif ($ko_meldung === 'kein UDP-Eingang') {
            $ko_err = ko_t('MELDUNG.MQTT_NICHT_GESENDET');
        } else {
            $ko_err = sprintf(ko_t('MELDUNG.MQTT_TEILWEISE'), (int) $ko_n, (int) $ko_versucht, ko_e($ko_meldung));
        }
        $ko_tab = 'tab-test';
    }

    /* ============ Die uebrigen Aktionen des Reiters Test ============ */
    if (isset($_POST['test']) && is_string($_POST['test'])) {
        list($ko_rawtitel, $ko_raw) = ko_test_aktion((string) $_POST['test']);
        $ko_tab = 'tab-test';
    }

    /* ============ Protokoll leeren ============ */
    if (isset($_POST['clearlog'])) {
        $ko_logdatei = ko_paths()['log'];
        if (!is_dir(dirname($ko_logdatei))) { @mkdir(dirname($ko_logdatei), 0775, true); }
        // Den Erfolg pruefen: sonst stehen die alten Zeilen da, und niemand
        // sagt warum (bis 1.2.6).
        if (@file_put_contents($ko_logdatei,
                '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n") === false) {
            $ko_err = sprintf(ko_t('LOG.M_NICHT_GELEERT'), ko_e($ko_logdatei));
        } else {
            $ko_note = ko_t('LOG.M_GELEERT');
        }
        $ko_tab = 'tab-log';
    }
}

/* Fehlende Schluessel EINMAL in die Datei schreiben - danach heisst "fehlt"
 * nie mehr "gilt als Vorgabe". */
ko_cfg_vervollstaendigen();

$ko_cfg  = ko_config(true);
$ko_st   = ko_status();
$ko_gw   = ko_mqtt_gateway_info();
$ko_gwf  = ($ko_gw === null) ? 0 : (int) $ko_gw['fassung'];
$ko_port = ko_mqtt_port();
$ko_p    = ko_paths();

$ko_frame = class_exists('LBWeb', false);
if ($ko_frame) { LBWeb::lbheader(ko_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html'); }
/* NICHT hier maskieren: beide Verwendungsstellen schicken den Wert ohnehin
 * durch ko_e(). Doppelt maskiert stand ohne Host-Kopfzeile woertlich
 * "tcp://&lt;loxberry-ip&gt;:9090" auf dem Bildschirm - gemessen. */
$ko_host = ko_sicht_wirt();
if ($ko_host === '') { $ko_host = '<loxberry-ip>'; }
/* Der Verweis auf Kodis Weboberflaeche - EINMAL ermittelt, an zwei Stellen
 * angeboten (Reiter Einstellungen bei der Adresse, Reiter Test unter
 * "Ansehen"). Er zeigt die GESPEICHERTE Adresse, nicht die im Eingabefeld:
 * was noch nicht gespeichert ist, gilt fuer Kodi auch nicht. Ist sie leer,
 * wird kein Knopf angeboten - einer ins Leere ist schlimmer als keiner. */
$ko_weburl = ko_kodi_url();
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss.
   Uebernommen aus VORLAGE_hausstandard.css.html. Bis 1.1.9 fuehrte diese
   Datei einen eigenen Dialekt (sm-pane statt sm-seite, sm-small statt
   sm-hilfe, ein eigenes sm-alert-System) - damit griffen die Proben des
   Hausstandards hier ins Leere. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; max-height: 480px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-grau { color: #888; font-weight: 700; }
/* Ab hier und in .sm-pre/.sm-knopfreihe form: Ergaenzungen dieses Plugins,
   nicht aus der Vorlage.
   Eingabefelder: sie tragen data-role="none", jQuery Mobile legt also keinen
   Behaelter um sie, und die Behaelterregeln oben greifen nicht. Ohne diese
   Regel stuenden sie als nackte Browserfelder da. Die Vorlage verbietet sie
   nicht - ihr Satz "ausschliesslich der Behaelter" gilt fuer Felder, die
   jQuery Mobile umbaut (AWM-Abfuhr und ACTiKamera gestalten ebenso). */
.sm-feld input[type=text], .sm-feld input[type=password], .sm-feld input[type=number] {
    width: 100%; max-width: 520px; padding: 8px 10px; border: 1px solid #ccc;
    border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-feld input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
/* Eine Tabelle, die breiter ist als das Fenster, braucht ihre eigene
   Bildlaufleiste - sonst steht die letzte Spalte ausserhalb und ist
   UNERREICHBAR, nicht bloss unbequem. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Die Raute im SVG wird als
   %23 geschrieben: eine rohe Raute beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
</style>
<div class="sm-wrap">

<?php if (ko_sprache_fehlt()) { ?>
<!-- Bewusst fest im Quelltext und nicht ueber ko_t(): wenn diese Meldung
     noetig ist, kann ko_t() nichts uebersetzen. -->
<div class="sm-warnung">
  <b class="sm-aus">Die Sprachdateien wurden nicht gefunden.</b>
  Deshalb stehen unten nur die Schl&uuml;ssel (ALLG.TITEL, REITER.LOXONE &hellip;)
  statt der Texte. Erwartet werden sie unter
  <span class="sm-mono">&lt;LoxBerry&gt;/templates/plugins/<?= ko_e($ko_p['plugin']) ?>/lang/</span>.
  Meist hilft ein erneutes Installieren des Plugins.
</div>
<?php } ?>

<?php if ($ko_saved) { ?><div class="sm-hinweis"><?= ko_t('MELDUNG.GESPEICHERT') ?></div><?php } ?>
<?php if ($ko_note !== '') { ?><div class="sm-hinweis"><?= $ko_note ?></div><?php } ?>
<?php if ($ko_err !== '') { ?><div class="sm-warnung"><b class="sm-aus"><?= ko_t('MELDUNG.FEHLER') ?></b> <?= $ko_err ?></div><?php } ?>
<?php if ($ko_beanstandungen) { ?>
<div class="sm-warnung"><b><?= ko_t('MELDUNG.BEANSTANDET') ?></b>
<ul style="margin:6px 0 0 18px;">
<?php foreach ($ko_beanstandungen as $ko_b) { ?><li><?= $ko_b ?></li><?php } ?>
</ul></div>
<?php } ?>

<div class="sm-kacheln">
  <?php
  /* Die Klasse haengt am ZUSTAND, nicht an "leer oder nicht": "nicht
   * feststellbar" ist grau, nicht rot (Regeln/04). Bis 1.2.6 stand bei
   * schweigendem Helfer ein ROTES Fragezeichen - es sah aus wie ein Fehler
   * des Dienstes. */
  $ko_dl = ko_dienst_lage();
  ?>
  <div class="sm-kachel"><?= ko_e(ko_t('ALLG.DIENST')) ?>
    <b class="<?= !$ko_st ? 'sm-grau' : (!empty($ko_st['kodistarted']) ? 'sm-an' : 'sm-aus') ?>"><?=
      ko_e(ko_dienst_text($ko_st, $ko_dl)) ?></b></div>
  <div class="sm-kachel"><?= ko_e(ko_t('ALLG.AUTOSTART')) ?>
    <b class="<?= !$ko_st ? 'sm-grau' : (!empty($ko_st['kodiautostart']) ? 'sm-an' : 'sm-aus') ?>"><?php
      echo !$ko_st ? '?' : ko_e(!empty($ko_st['kodiautostart']) ? ko_t('ALLG.EIN') : ko_t('ALLG.AUS')); ?></b></div>
  <div class="sm-kachel"><?= ko_e(ko_t('ALLG.SENDER')) ?>
    <b class="<?= ((string) $ko_cfg['sender_ein'] === '1') ? 'sm-an' : 'sm-aus' ?>"><?php
      echo ko_e((string) $ko_cfg['sender_ein'] === '1' ? ko_t('ALLG.EIN') : ko_t('ALLG.AUS')); ?></b></div>
  <div class="sm-kachel">MPEG2 / VC1
    <b><?= ko_e(isset($ko_st['mpeg2status']) ? $ko_st['mpeg2status'] : '?') ?>
       / <?= ko_e(isset($ko_st['vc1status']) ? $ko_st['vc1status'] : '?') ?></b></div>
</div>
<?php if (!$ko_st) { ?>
<div class="sm-warnung"><?= sprintf(ko_t('MELDUNG.STATUS_NICHT_ABRUFBAR'),
    '<span class="sm-mono">sudo ' . ko_e($ko_p['bin']) . '/elevatedhelper.pl action=query</span>') ?></div>
<?php } ?>

<?php
/* Kann Kodi hier ueberhaupt starten? Gemessen am 17.09.2026: ohne
 * Grafiktreiber nicht - und die Seite sagte nur "gestoppt". Die Warnung
 * steht oben, weil sie jede andere Zeile dieser Seite erklaert. */
$ko_bild = ko_bildausgabe();
if ($ko_bild['stand'] === 0) { ?>
<div class="sm-warnung"><?= ko_t('MELDUNG.KEIN_BILD') ?></div>
<?php } ?>

<?php
/* Der Autostart des Gateways - der Wortlaut ist im Haus festgelegt und steht
 * in der Sprachdatei. Ist general.json nicht lesbar ($ko_gw === null),
 * erscheint KEINE Warnung, sondern ein Strich im Reiter Test. Ein fehlender
 * Schluessel Gatewayautostart gilt wie beim Vorbild MG iSmart als "aus". */
if ($ko_gw !== null && !$ko_gw['autostart']) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?= ko_t('MELDUNG.W_AUTOSTART') ?></div>
<?php } ?>
<?php if (!$ko_port) { ?>
<div class="sm-warnung"><?= ko_t('MELDUNG.UDP_WARNUNG') ?></div>
<?php } ?>

<div class="sm-tabs">
	<a class="sm-tab<?= ko_aktiv('tab-settings') ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= ko_e(ko_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= ko_aktiv('tab-mqtt') ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= ko_aktiv('tab-loxone') ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= ko_e(ko_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= ko_aktiv('tab-test') ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= ko_e(ko_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= ko_aktiv('tab-log') ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= ko_e(ko_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= ko_aktiv('tab-settings') ?>" id="tab-settings">
<!-- Die Legende steht OBEN und nennt genau die Farben dieses Reiters
     (Regeln/04). Bis 1.2.6 stand sie erst unter dem Speichern-Knopf. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ko_e(ko_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ko_e(ko_t('LEGENDE.AKTION')) ?></span>
</div>

<h2><?= ko_e(ko_t('EINST.H_DIENST')) ?></h2>
<div class="sm-hilfe"><?= $ko_st
    ? sprintf(ko_t('EINST.DIENST_ZUSTAND'), '<b>' . ko_e(ko_dienst_text($ko_st, $ko_dl)) . '</b>')
    : ko_e(ko_t('TEST.A_DIENST_UNBEKANNT')) ?></div>
<!-- Start ist gruen, Anhalten und Neustarten orange: die Trennlinie ist
     "kann den Betrieb stoeren" (Regeln/04). Bis 1.2.6 standen alle drei
     orange im Reiter Test. -->
<div class="sm-knopfreihe">
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings">
        <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="service" value="start"><?= ko_e(ko_t('TEST.K_START')) ?></button></form>
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="service" value="restart"><?= ko_e(ko_t('TEST.K_NEUSTART')) ?></button></form>
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="service" value="stop"><?= ko_e(ko_t('TEST.K_STOP')) ?></button></form>
</div>
<?php if ($ko_bild['stand'] === 0) { ?>
<div class="sm-hilfe"><?= ko_t('EINST.START_OHNE_BILD') ?></div>
<?php } ?>

<form action="index.php" method="post">
<?= ko_fmt() ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Kodi</h2>
<div class="sm-feld">
    <label for="ko_host"><?= ko_e(ko_t('EINST.L_HOST')) ?></label>
    <input data-role="none" type="text" id="ko_host" name="kodi_host" value="<?= ko_e($ko_cfg['kodi_host']) ?>">
    <div class="sm-hilfe"><?= sprintf(ko_t('EINST.H_HOST'), '<span class="sm-mono">127.0.0.1</span>') ?></div>
</div>
<div class="sm-feld">
    <label for="ko_portfeld"><?= ko_e(ko_t('EINST.L_PORT')) ?></label>
    <input data-role="none" type="number" id="ko_portfeld" name="kodi_port" value="<?= (int) $ko_cfg['kodi_port'] ?>">
    <div class="sm-hilfe"><?= ko_t('EINST.H_PORT') ?></div>
</div>
<?php if ($ko_weburl !== '') { ?>
<div class="sm-feld">
    <a data-role="none" class="sm-btn sm-b-lesen" target="_blank" rel="noopener noreferrer"
       href="<?= ko_e($ko_weburl) ?>"><?= ko_e(ko_t('TEST.K_WEBINTERFACE')) ?></a>
    <div class="sm-hilfe"><?= sprintf(ko_t('EINST.H_WEB'),
        '<span class="sm-mono">' . ko_e($ko_weburl) . '</span>') ?></div>
</div>
<?php } ?>
<div class="sm-feld">
    <label for="ko_user"><?= ko_e(ko_t('EINST.L_USER')) ?></label>
    <input data-role="none" type="text" id="ko_user" name="kodi_user" value="<?= ko_e($ko_cfg['kodi_user']) ?>">
</div>
<div class="sm-feld">
    <label for="ko_pass"><?= ko_e(ko_t('EINST.L_PASS')) ?></label>
    <!-- Das gespeicherte Passwort steht NICHT im Quelltext der Seite (bis
         1.2.6 stand es dort als value=). Ein leeres Feld laesst es stehen;
         geloescht wird ueber den Haken darunter. -->
    <input data-role="none" type="password" id="ko_pass" name="kodi_pass" value="" autocomplete="new-password"
        placeholder="<?= ko_e($ko_cfg['kodi_pass'] !== ''
            ? ko_t('EINST.PASS_GESETZT') : ko_t('EINST.PASS_LEER')) ?>">
    <?php if ($ko_cfg['kodi_pass'] !== '') { ?>
    <label><input data-role="none" type="checkbox" name="kodi_pass_loeschen" value="1">
        <?= ko_e(ko_t('EINST.L_PASS_LOESCHEN')) ?></label>
    <?php } ?>
    <div class="sm-hilfe"><?= ko_t('EINST.H_ZUGANG') ?></div>
</div>

<div class="sm-feld">
    <!-- Der versteckte Begleiter kommt IMMER mit. Ein nicht angehaktes
         Kaestchen schickt der Browser gar nicht - ohne ihn liesse sich der
         Haken nie wieder abwaehlen. -->
    <?php /* ... aber nur, wenn der Helfer den Autostart gemeldet hat. Sonst
             stuende das Kaestchen leer da, und ein Speichern wegen eines
             ganz anderen Feldes schaltete den Autostart ab. */ ?>
    <?php if (isset($ko_st['kodiautostart'])) { ?>
    <input data-role="none" type="hidden" name="hat_kodiautostart" value="1">
    <?php } ?>
    <label><input data-role="none" type="checkbox" name="kodiautostart" value="1"
        <?= (!empty($ko_st['kodiautostart'])) ? 'checked' : '' ?>>
        <?= ko_e(ko_t('EINST.L_AUTOSTART')) ?></label>
</div>

<h2><?= ko_e(ko_t('EINST.H2_RPC')) ?></h2>
<div class="sm-feld">
    <input data-role="none" type="hidden" name="hat_rpc_ein" value="1">
    <label><input data-role="none" type="checkbox" name="rpc_ein" value="1"
        <?= ((string) $ko_cfg['rpc_ein'] === '1') ? 'checked' : '' ?>>
        <?= ko_e(ko_t('EINST.L_RPC')) ?></label>
    <div class="sm-hilfe"><?= ko_t('EINST.H_RPC') ?></div>
</div>

<h2><?= ko_e(ko_t('EINST.H_LIZENZ')) ?></h2>
<div class="sm-hinweis"><?= sprintf(ko_t('EINST.LIZENZ_HINWEIS'),
    '<span class="sm-mono">' . ko_e(isset($ko_st['piserial']) ? $ko_st['piserial'] : '?') . '</span>') ?></div>
<div class="sm-feld">
    <label for="ko_mpeg2">MPEG2 &mdash; <?= ko_e(ko_t('EINST.HINTERLEGT')) ?>
        <span class="sm-mono"><?= ko_e(isset($ko_st['mpeg2lic']) && $ko_st['mpeg2lic'] !== ''
            ? $ko_st['mpeg2lic'] : ko_t('EINST.KEINER')) ?></span></label>
    <input data-role="none" type="text" id="ko_mpeg2" name="licmpeg2" value="" placeholder="0x00000000">
</div>
<div class="sm-feld">
    <label for="ko_vc1">VC1 &mdash; <?= ko_e(ko_t('EINST.HINTERLEGT')) ?>
        <span class="sm-mono"><?= ko_e(isset($ko_st['vc1lic']) && $ko_st['vc1lic'] !== ''
            ? $ko_st['vc1lic'] : ko_t('EINST.KEINER')) ?></span></label>
    <input data-role="none" type="text" id="ko_vc1" name="licvc1" value="" placeholder="0x00000000">
</div>

<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?= ko_e(ko_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ko_e(ko_t('SICH.H_SICHERUNG')) ?></h2>
<div class="sm-warnung"><?= ko_t('SICH.GEHEIM') ?></div>
<div class="sm-hilfe"><?= ko_t('SICH.HINWEIS') ?></div>
<!-- ZWEI GETRENNTE FORMULARE. Das Sichern schickt einen Download und ruft exit
     auf; das Zurueckspielen braucht enctype="multipart/form-data". Wer beides
     in ein Formular legt, bekommt entweder keinen Upload oder einen Download,
     der das Speichern verschluckt. -->
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?= ko_fmt() ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="sichern" value="1"><?= ko_e(ko_t('SICH.K_SICHERN')) ?></button>
</form>
</div>
<form action="index.php" method="post" enctype="multipart/form-data">
  <?= ko_fmt() ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <div class="sm-feld">
    <label for="ko_datei"><?= ko_e(ko_t('SICH.L_DATEI')) ?></label>
    <!-- accept ist ein Hinweis fuer den Dateidialog und KEINE Pruefung - der
         Browser haelt sich nicht immer daran, und ein Upload kommt ohnehin
         auch ohne Browser. Geprueft wird serverseitig. -->
    <input data-role="none" type="file" id="ko_datei" name="sicherung" accept=".txt,text/plain">
    <div class="sm-hilfe"><?= ko_t('SICH.H_DATEI') ?></div>
  </div>
  <div class="sm-feld">
    <label><input data-role="none" type="checkbox" name="lizenz_auch" value="1">
      <?= ko_e(ko_t('SICH.L_LIZENZ')) ?></label>
    <div class="sm-hilfe"><?= ko_t('SICH.H_LIZENZ') ?></div>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="laden" value="1"><?= ko_e(ko_t('SICH.K_LADEN')) ?></button>
  </div>
</form>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-seite<?= ko_aktiv('tab-mqtt') ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ko_e(ko_t('LEGENDE.AKTION')) ?></span>
</div>
<h2>MQTT</h2>

<?php
/* Zustand des Gateways - hier, im Reiter MQTT (Regeln/04). Bis 1.2.6 stand er
 * nur als Warnung ueber der Reiterleiste und als Zeile im Reiter Test. */
if ($ko_gw === null) { ?>
<div class="sm-hilfe"><?= ko_t('MQTT.GW_UNBEKANNT') ?></div>
<?php } else { ?>
<div class="sm-hilfe"><?= sprintf(ko_t('MQTT.GW_ZUSTAND'),
    $ko_gwf > 0 ? 'V' . (int) $ko_gwf : ko_e(ko_t('TEST.A_GATEWAY_FASSUNG_UNBEKANNT')),
    $ko_port ? (int) $ko_port : ko_e(ko_t('LOX.NICHT_GESETZT')),
    $ko_gw['autostart'] ? ko_e(ko_t('ALLG.EIN')) : ko_e(ko_t('ALLG.AUS'))) ?></div>
<?php } ?>

<form action="index.php" method="post">
<?= ko_fmt() ?>
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
    <label for="ko_thema"><?= ko_e(ko_t('MQTT.L_THEMA')) ?></label>
    <input data-role="none" type="text" id="ko_thema" name="mqtt_topic" value="<?= ko_e($ko_cfg['mqtt_topic']) ?>">
    <div class="sm-hilfe"><?= sprintf(ko_t('MQTT.H_THEMA'),
        '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/dienst</span>') ?></div>
</div>

<h3><?= ko_e(ko_t('EINST.H_SENDER')) ?></h3>
<div class="sm-hinweis"><?= ko_t('EINST.SENDER_TEXT') ?></div>
<div class="sm-feld">
    <input data-role="none" type="hidden" name="hat_sender_ein" value="1">
    <label><input data-role="none" type="checkbox" name="sender_ein" value="1"
        <?= ((string) $ko_cfg['sender_ein'] === '1') ? 'checked' : '' ?>>
        <?= ko_e(ko_t('EINST.L_SENDER')) ?></label>
</div>
<div class="sm-feld">
    <label for="ko_takt"><?= ko_e(ko_t('EINST.L_TAKT')) ?></label>
    <input data-role="none" type="number" id="ko_takt" name="sender_takt" value="<?= (int) $ko_cfg['sender_takt'] ?>">
    <div class="sm-hilfe"><?= ko_t('EINST.H_TAKT') ?></div>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ko_e(ko_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ko_e(ko_t('MQTT.H_ABO')) ?></h2>
<?php if ($ko_gwf >= 2) { ?>
<div class="sm-hinweis"><?= ko_t('LOX.ABO_V2') ?></div>
<?php } else { ?>
<div class="sm-step"><?= ko_e(ko_t('MQTT.ABO_KOPIEREN')) ?>
    <span class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/#</span></div>
<div class="sm-warnung"><?= ko_t('LOX.ABO_PFLICHT') ?></div>
<?php if ($ko_gwf === 0) { ?><div class="sm-hilfe"><?= ko_t('LOX.ABO_V2') ?></div><?php } ?>
<?php } ?>

<h2><?= ko_e(ko_t('LOX.H_THEMEN')) ?></h2>
<div class="sm-hilfe"><?= ko_t('LOX.THEMEN_HINWEIS') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ko_e(ko_t('LOX.TH_THEMA')) ?></th><th><?= ko_e(ko_t('LOX.TH_BEDEUTUNG')) ?></th>
    <th><?= ko_e(ko_t('LOX.TH_WERTE')) ?></th><th><?= ko_e(ko_t('LOX.TH_QUELLE')) ?></th>
    <th><?= ko_e(ko_t('LOX.TH_RETAIN')) ?></th><th><?= ko_e(ko_t('LOX.TH_VORLAGE')) ?></th></tr>
<?php foreach (ko_themen() as $ko_th) { ?>
<tr><td class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic'] . '/' . $ko_th['name']) ?></td>
    <td><?= ko_t('THEMA.' . $ko_th['schl']) ?></td>
    <td><?= ko_t('THEMA.' . $ko_th['wschl']) ?></td>
    <td><?= ko_e($ko_th['quelle'] === 'plugin' ? ko_t('LOX.Q_PLUGIN') : ko_t('LOX.Q_ADDON')) ?></td>
    <td><?= !empty($ko_th['retain']) ? ko_e(ko_t('ALLG.JA')) : ko_e(ko_t('ALLG.NEIN')) ?></td>
    <td><?= ko_thema_in_vorlage($ko_th, $ko_cfg) ? '<span class="sm-an">&#10004;</span>' : '&ndash;' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= ko_t('LOX.TEXTTHEMEN') ?></div>
<?php if ((string) $ko_cfg['sender_ein'] !== '1') { ?>
<div class="sm-warnung"><?= sprintf(ko_t('LOX.SENDER_AUS'), ko_themen_plugin_zahl($ko_cfg)) ?></div>
<?php } ?>

<h2><?= ko_e(ko_t('ADDON.H')) ?></h2>
<div class="sm-hinweis"><?= ko_t('ADDON.TEXT') ?></div>
<?php
$ko_addon = ko_addon_lesen();
$ko_soll  = ko_addon_soll();
?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ko_e(ko_t('ADDON.TH_FELD')) ?></th><th><?= ko_e(ko_t('ADDON.TH_IST')) ?></th><th><?= ko_e(ko_t('ADDON.TH_SOLL')) ?></th></tr>
<?php foreach (ko_addon_schluessel() as $ko_k) { ?>
<tr><td class="sm-mono"><?= ko_e($ko_k) ?></td>
    <td><?= $ko_addon === null ? '<span class="sm-aus">?</span>'
            : ko_e(isset($ko_addon[$ko_k]) && $ko_addon[$ko_k] !== '' ? $ko_addon[$ko_k] : '-') ?></td>
    <td><?= isset($ko_soll[$ko_k])
            ? ko_e($ko_soll[$ko_k] !== '' ? $ko_soll[$ko_k] : '?')
            : '<span class="sm-hilfe">' . ko_e(ko_t('ADDON.GEHOERT_ANWENDER')) . '</span>' ?></td></tr>
<?php } ?>
</table>
</div>
<?php if ($ko_addon === null) { ?>
<div class="sm-warnung"><?= ko_t('ADDON.NICHT_LESBAR') ?></div>
<?php } ?>
<form action="index.php" method="post">
<?= ko_fmt() ?>
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="addon_anhalten" value="1">
    <?= ko_e(ko_t('ADDON.L_ANHALTEN')) ?></label>
  <div class="sm-hilfe"><?= ko_t('ADDON.H_ANHALTEN') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="addonschreiben" value="1"><?= ko_e(ko_t('ADDON.K_SETZEN')) ?></button>
</div>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= ko_aktiv('tab-loxone') ?>" id="tab-loxone">
<!-- Sieben Schritte mit Baustein-Liste (Regeln/04). Bis 1.2.6 hatte dieser
     Reiter drei Schritte, keine Ausfallerkennung, keine Baustein-Liste und
     keine Gegenprobe. Die Themen-Tabelle steht seit 1.2.7 im Reiter MQTT. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ko_e(ko_t('LEGENDE.TECHNIK')) ?></span>
</div>

<h2><?= ko_e(ko_t('LOX.H')) ?></h2>
<?php if ((string) $ko_cfg['sender_ein'] !== '1') { ?>
<div class="sm-warnung"><?= sprintf(ko_t('LOX.SENDER_AUS'), ko_themen_plugin_zahl($ko_cfg)) ?></div>
<?php } ?>

<h3><?= ko_e(ko_t('LOX.S1')) ?></h3>
<div class="sm-step"><?= sprintf(ko_t('LOX.SCHRITT1'),
    '<b>' . ($ko_port ? ko_e($ko_port) : ko_e(ko_t('LOX.NICHT_GESETZT'))) . '</b>') ?></div>

<h3><?= ko_e(ko_t('LOX.S2')) ?></h3>
<div class="sm-step"><?= sprintf(ko_t('LOX.SCHRITT2'),
    '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/</span>') ?></div>
<?php
/* Was hier steht, haengt von der FASSUNG des MQTT-Gateways ab.
 *
 * Der Satz "Ohne diesen Eintrag kommt am Miniserver nichts an" gilt fuer V1.
 * Unter V2 schaltet der LoxBerry-Kern auf der Abonnement-Seite die Knoepfe ab
 * (mqtt-gateway.cgi, FORM_DISABLE_BUTTONS) - dort ist gar nichts mehr
 * einzutragen, und der unbedingte Satz schickte jeden V2-Anwender zu einem
 * Eingabefeld, das es nicht gibt.
 *
 * Ist die Fassung nicht lesbar, stehen BEIDE Saetze da. Einen von beiden zu
 * behaupten waere fuer die Haelfte der Anlagen falsch - und das in genau der
 * Zeile, die als haeufigste Fehlerursache gilt.
 *
 * Regel daraus: Der Satz zur Gateway-Fassung steht an GENAU EINER Stelle je
 * Fassung, und alles, was von ihr abhaengt, steht IM SELBEN Zweig. Die
 * Pruefzeile "Aussagen zur Gateway-Fassung" im Reiter Test zaehlt nach, ob
 * der V1-Satz ausserhalb seines Schluessels noch irgendwo auftaucht.
 *
 * Gemessen am 17.09.2026 im Quelltext des Geraets (LoxBerry 4.0.0.15,
 * sbin/mqtt_gateway.py): auch Gateway V2 lauscht IMMER auf Mqtt.Udpinport und
 * versteht publish und retain. Der Sendeweg dieses Plugins ist unter beiden
 * Fassungen derselbe; verschieden ist nur, was in den Abonnements zu tun ist. */
if ($ko_gwf >= 2) { ?>
<div class="sm-hinweis"><?= ko_t('LOX.ABO_V2') ?></div>
<div class="sm-step"><?= ko_t('LOX.SCHRITT3_V2') ?></div>
<?php } elseif ($ko_gwf === 1) { ?>
<div class="sm-step"><?= ko_e(ko_t('MQTT.ABO_KOPIEREN')) ?>
    <span class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/#</span></div>
<div class="sm-warnung"><?= ko_t('LOX.ABO_PFLICHT') ?></div>
<div class="sm-step"><?= ko_t('LOX.SCHRITT3_V1') ?></div>
<?php } else { ?>
<div class="sm-step"><?= ko_e(ko_t('MQTT.ABO_KOPIEREN')) ?>
    <span class="sm-mono"><?= ko_e($ko_cfg['mqtt_topic']) ?>/#</span></div>
<div class="sm-warnung"><?= ko_t('LOX.ABO_PFLICHT') ?></div>
<div class="sm-hilfe"><?= ko_t('LOX.ABO_V2') ?></div>
<div class="sm-step"><?= ko_t('LOX.SCHRITT3_V1') ?></div>
<div class="sm-step"><?= ko_t('LOX.SCHRITT3_V2') ?></div>
<?php } ?>

<h3><?= ko_e(ko_t('LOX.S3')) ?></h3>
<?php
$ko_vi = array();
$ko_text_zahl = 0;
foreach (ko_themen() as $ko_th) {
    if ($ko_th['quelle'] !== 'plugin') { continue; }
    if (ko_thema_in_vorlage($ko_th, $ko_cfg)) { $ko_vi[] = $ko_th; }
    elseif (empty($ko_th['zahl'])) { $ko_text_zahl++; }
}
?>
<div class="sm-step"><?= sprintf(ko_t('LOX.S3_TEXT'), count($ko_vi), $ko_text_zahl) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ko_e(ko_t('LOX.TH_EINGANG')) ?></th><th><?= ko_e(ko_t('LOX.TH_WERTE')) ?></th>
    <th><?= ko_e(ko_t('LOX.TH_BEDEUTUNG')) ?></th><th><?= ko_e(ko_t('LOX.TH_RETAIN')) ?></th></tr>
<?php foreach ($ko_vi as $ko_th) { ?>
<tr><td class="sm-mono"><?= ko_e(str_replace('/', '_', $ko_cfg['mqtt_topic'] . '_' . $ko_th['name'])) ?></td>
    <td><?= ko_t('THEMA.' . $ko_th['wschl']) ?></td>
    <td><?= ko_t('THEMA.' . $ko_th['schl']) ?></td>
    <td><?= !empty($ko_th['retain']) ? ko_e(ko_t('ALLG.JA')) : ko_e(ko_t('ALLG.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?= ko_t('LOX.H_VORLAGE_TEXT') ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?= ko_fmt() ?>
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ko_e(ko_t('LOX.K_VORLAGE')) ?></button>
</form>
</div>

<h3><?= ko_e(ko_t('LOX.S4')) ?></h3>
<div class="sm-step"><?= ko_t('LOX.STEUERN_TEXT') ?></div>
<div class="sm-step sm-mono">tcp://<?= ko_e(ko_steuer_wirt()) ?>:9090</div>
<div class="sm-hilfe"><?= ko_t('LOX.ADRESSE_PRUEFEN') ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?= ko_fmt() ?>
  <input data-role="none" type="hidden" name="vorlage" value="vo">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ko_e(ko_t('LOX.K_VORLAGE_VO')) ?></button>
</form>
</div>
<div class="sm-hilfe"><?= sprintf(ko_t('LOX.VORLAGE_HINWEIS'), count(ko_vo_befehle())) ?></div>

<h3><?= ko_e(ko_t('LOX.S5')) ?></h3>
<div class="sm-step"><?= sprintf(ko_t('LOX.S5_TEXT'),
    '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/zeitstempel</span>',
    '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/herzschlag</span>',
    '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/status/ok</span>') ?></div>

<h3><?= ko_e(ko_t('LOX.S6')) ?></h3>
<div class="sm-hilfe"><?= ko_t('BAUSTEIN.VORTEXT') ?></div>
<?php
/* Die Namen der Eingaenge kommen aus DERSELBEN Rechnung wie die Vorlage
 * (Praefix, Unterstrich statt Schraegstrich) - eine zweite Schreibweise hier
 * waere eine zweite Anleitung. Jede Zeile bezieht sich nur auf kleinere
 * Nummern; kein UND/ODER mit mehr als zwei Eingaengen (Regeln/04, 09). */
$ko_vn = function ($name) use ($ko_cfg) {
    return '<span class="sm-mono">' . ko_e(str_replace('/', '_', $ko_cfg['mqtt_topic'] . '_' . $name)) . '</span>';
};
$ko_bl = array(
    array('B_VE', $ko_vn('dienst'),      ko_t('BAUSTEIN.P_DIGITAL'), ko_t('BAUSTEIN.V_GATEWAY')),
    array('B_VE', $ko_vn('status/ok'),   ko_t('BAUSTEIN.P_DIGITAL'), ko_t('BAUSTEIN.V_GATEWAY')),
    array('B_VE', $ko_vn('zeitstempel'), ko_t('BAUSTEIN.P_ZEIT'),    ko_t('BAUSTEIN.V_GATEWAY')),
    array('B_FORMEL',    ko_e(ko_t('BAUSTEIN.N_ALTER')),    '<span class="sm-mono">I1+1230768000-I2</span>', ko_t('BAUSTEIN.V_ALTER')),
    array('B_SCHWELL',   ko_e(ko_t('BAUSTEIN.N_STUMM')),    ko_t('BAUSTEIN.P_STUMM'),   ko_t('BAUSTEIN.V_4')),
    array('B_NICHT',     ko_e(ko_t('BAUSTEIN.N_NICHTGEMESSEN')), '&mdash;',            ko_t('BAUSTEIN.V_2')),
    array('B_ODER',      ko_e(ko_t('BAUSTEIN.N_SAMMEL')),   '&mdash;',                   ko_t('BAUSTEIN.V_5_6')),
    array('B_EINVERZ',   ko_e(ko_t('BAUSTEIN.N_BESTAETIGT')), ko_t('BAUSTEIN.P_EINVERZ'), ko_t('BAUSTEIN.V_7')),
    array('B_BENACHR',   ko_e(ko_t('BAUSTEIN.N_MELDUNG')),  ko_t('BAUSTEIN.P_MELDUNG'), ko_t('BAUSTEIN.V_8')),
    array('B_STATUS',    ko_e(ko_t('BAUSTEIN.N_STATUS')),   ko_t('BAUSTEIN.P_STATUS'),  ko_t('BAUSTEIN.V_1_8')),
);
?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= ko_e(ko_t('BAUSTEIN.SP_BAUSTEIN')) ?></th><th><?= ko_e(ko_t('BAUSTEIN.SP_NAME')) ?></th>
    <th><?= ko_e(ko_t('BAUSTEIN.SP_PARAMETER')) ?></th><th><?= ko_e(ko_t('BAUSTEIN.SP_EINGAENGE')) ?></th></tr>
<?php foreach ($ko_bl as $ko_i => $ko_b) { ?>
<tr><td><?= (int) $ko_i + 1 ?></td><td><?= ko_e(ko_t('BAUSTEIN.' . $ko_b[0])) ?></td><td><?= $ko_b[1] ?></td>
    <td><?= $ko_b[2] ?></td><td><?= $ko_b[3] ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= ko_t('BAUSTEIN.ZU') ?></div>

<h3><?= ko_e(ko_t('LOX.S7')) ?></h3>
<div class="sm-step"><?= sprintf(ko_t('LOX.S7_TEXT'),
    '<span class="sm-mono">' . ko_e($ko_cfg['mqtt_topic']) . '/#</span>',
    $ko_vn('herzschlag')) ?></div>

</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= ko_aktiv('tab-test') ?>" id="tab-test">

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ko_e(ko_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ko_e(ko_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ko_e(ko_t('LEGENDE.AKTION')) ?></span>
</div>

<h2><?= ko_e(ko_t('TEST.H_SELBST')) ?></h2>
<?php
/* NUR, WENN DIESER REITER SERVERSEITIG DER OFFENE IST (Regeln/04). Alle
 * Reiter werden bei jedem Seitenaufruf mitgerendert; die Selbstpruefung ruft
 * den Helfer ueber sudo, fragt dpkg und liest die Addon-Einstellungen - bis
 * 1.2.6 bei jedem Klick im Reiter Einstellungen. */
if ($ko_tab === 'tab-test') { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:2em;">&nbsp;</th><th><?= ko_e(ko_t('TEST.TH_FRAGE')) ?></th><th><?= ko_e(ko_t('TEST.TH_ANTWORT')) ?></th></tr>
<?php foreach (ko_pruefungen($ko_reiter, __DIR__ . '/index.php') as $ko_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($ko_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($ko_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $ko_z['frage'] ?></td><td><?= $ko_z['antwort'] ?></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(ko_t('TEST.SELBST_NACHLADEN'), 'index.php?form=test') ?></div>
<?php } ?>

<h3><?= ko_e(ko_t('TEST.H3_ANSEHEN')) ?></h3>
<div class="sm-knopfreihe">
    <a data-role="none" class="sm-btn sm-b-lesen" href="index.php?form=test"><?= ko_e(ko_t('TEST.K_STATUS_NEU')) ?></a>
<?php /* DIESELBE Adresse wie im Reiter Einstellungen - ko_kodi_url() sagt sie
       * einmal. Bis 1.2.1 stand die Regel hier ein zweites Mal, ohne
       * rel="noopener" und ohne Pruefung des Ports: bei Port 0 zeigte der
       * Knopf auf http://<wirt>:0. */
if ($ko_weburl !== '') { ?>
    <a data-role="none" class="sm-btn sm-b-lesen" target="_blank" rel="noopener noreferrer"
       href="<?= ko_e($ko_weburl) ?>"><?= ko_e(ko_t('TEST.K_WEBINTERFACE')) ?></a>
<?php } ?>
</div>

<h3><?= ko_e(ko_t('TEST.H3_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="rohdaten"><?= ko_e(ko_t('TEST.K_ROHDATEN')) ?></button></form>
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="dienstzustand"><?= ko_e(ko_t('TEST.K_DIENSTZUSTAND')) ?></button></form>
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="kodiping"><?= ko_e(ko_t('TEST.K_KODIPING')) ?></button></form>
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="addonzeigen"><?= ko_e(ko_t('TEST.K_ADDONZEIGEN')) ?></button></form>
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="trockenlauf"><?= ko_e(ko_t('TEST.K_TROCKEN')) ?></button></form>
</div>

<h3><?= ko_e(ko_t('TEST.H3_AKTION')) ?></h3>
<!-- Der Satz, dass diese Knoepfe sofort wirken, gehoert UEBER die Reihe
     (Regeln/04). Kodi starten und anhalten steht seit 1.2.7 im Reiter
     Einstellungen, beim Dienstzustand. -->
<div class="sm-step"><?= ko_t('TEST.SCHALTEN_HINWEIS') ?></div>
<div class="sm-knopfreihe">
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mqtttest" value="1"><?= ko_e(ko_t('TEST.K_MQTTTEST')) ?></button></form>
    <form action="index.php" method="post"><?= ko_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="senderjetzt"><?= ko_e(ko_t('TEST.K_SENDERJETZT')) ?></button></form>
</div>

<?php if ($ko_raw !== '') { ?>
<h3><?= ko_e($ko_rawtitel !== '' ? $ko_rawtitel : ko_t('TEST.H_AUSGABE')) ?></h3>
<div class="sm-pre"><?= $ko_raw ?></div>
<?php } ?>

</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= ko_aktiv('tab-log') ?>" id="tab-log">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ko_e(ko_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= ko_e(ko_t('REITER.LOG')) ?></h2>
<div class="sm-hilfe"><span class="sm-mono"><?= ko_e($ko_p['log']) ?></span></div>
<!-- log/plugins liegt auf einer Ramdisk (Regeln/04): das muss dastehen. -->
<div class="sm-hilfe"><?= ko_t('LOG.RAMDISK') ?></div>
<?php
$ko_zeilen = ko_log_ende($ko_p['log'], 300);
if ($ko_zeilen) {
    echo '<div class="sm-pre">' . ko_e(implode("\n", $ko_zeilen)) . '</div>';
} else { ?>
<div class="sm-hinweis"><?= ko_e(ko_t('LOG.KEIN_PROTOKOLL')) ?></div>
<?php } ?>
<!-- Orange, nicht rot: Rot ist im Hausstandard nicht vorgesehen - es liest
     sich als Warnung vor einer Gefahr, und ein geleertes Protokoll ist keine.
     Die Farbe sagt nur: dieser Knopf veraendert etwas. -->
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <?= ko_fmt() ?>
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ko_e(ko_t('LOG.K_LEEREN')) ?></button>
</form>
</div>
</div>

</div>
<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		// Ohne JavaScript folgt der Browser dem href, und der Server liefert
		// den richtigen Reiter. Mit JavaScript geht es schneller ohne
		// Neuladen - deshalb hier den Verweis abfangen.
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($ko_tab) ?>);
})();
</script>
<?php
if ($ko_frame) { LBWeb::lbfooter(); }
