<?php
/**
 * Kodi NG - gemeinsame Bibliothek
 *
 * WARUM SIE UNTER bin/ LIEGT UND NICHT NEBEN DER OBERFLAECHE
 *
 * Zwei Aufrufer brauchen sie: die Oberflaeche unter webfrontend/htmlauth/ und
 * der Statussender bin/kodi_ng_status.php, den der Cron startet. Auf dem
 * installierten LoxBerry liegen beide in GETRENNTEN Baeumen:
 *
 *     bin/plugins/<ordner>/           <- hier
 *     webfrontend/htmlauth/plugins/<ordner>/
 *
 * Ein require aus bin/ in den Web-Baum geht nur im entpackten Archiv auf.
 * Genau daran ist der Hintergrunddienst des Abfahrts-Assistenten von 1.5.0 bis
 * 1.5.7 bei jedem Lauf abgebrochen, ohne dass es auffiel - der Cron schreibt
 * nach /dev/null.
 *
 * Deshalb liegt die Bibliothek dort, wo der unauffaellige Aufrufer sie mit
 * __DIR__ findet, und die Oberflaeche sucht sie ueber eine Kandidatenliste.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * Grundlage: LoxBerry-Plugin-Kodi 0.1.2 von Christian Fenzl (christianTF).
 */

/* Der eigene Ordnername als KONSTANTE dieses Quelltextes.
 *
 * Er steht in der plugin.cfg - die liegt im installierten Zustand aber unter
 * data/plugins/<ordner>/, und dorthin fuehrt nur der Name, den wir gerade
 * suchen. Das liefe im Kreis. Ein Systempfad duerfte hier nicht stehen, ein
 * Ordnername des eigenen Pakets schon. */
if (!defined('KO_ORDNER')) { define('KO_ORDNER', 'kodi_ng'); }

/* Die Konfigurationsdatei. Steht an EINER Stelle, damit der Rueckfall unten
 * pruefen kann, ob in einem fremden Ordner schon die EIGENE Datei liegt. */
if (!defined('KO_CFGDATEI')) { define('KO_CFGDATEI', 'kodi.json'); }

/**
 * Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation (bin/plugins/<ordner>/ - drei Ebenen) genauso wie eine an einem
 * anderen Ort. Im entpackten Archiv findet es nichts und gibt einen Leerstring
 * zurueck, was der Aufrufer ohnehin abfangen muss.
 */
if (!function_exists('ko_wurzel_ermitteln')) {
    function ko_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/**
 * Der Ordnername des Plugins.
 *
 * Massgeblich ist LBPPLUGINDIR. Fehlt es, wird der Name aus dem Ablageort
 * abgeleitet - installiert heisst das Verzeichnis dieser Datei wie das Plugin.
 *
 * DER RUECKFALL AUF DEN FESTEN NAMEN IST BEWACHT. Zwei Plugins duerfen
 * denselben FOLDER beanspruchen; LoxBerry haelt sie fuer verschieden und
 * haengt beim zweiten "01" an. Zeigte der Rueckfall dann auf das Verzeichnis
 * des FREMDEN Plugins, legte dieses Plugin dort eine Konfiguration an, die
 * niemand liest. Genommen wird er deshalb nur, wenn dort entweder noch nichts
 * liegt oder schon die eigene Konfigurationsdatei.
 */
if (!function_exists('ko_plugin_ordner')) {
    function ko_plugin_ordner($home)
    {
        $env = (string) getenv('LBPPLUGINDIR');
        if ($env !== '') { return $env; }

        $ab = basename(__DIR__);
        if ($ab !== '' && $ab !== 'bin' && $ab !== 'htmlauth' && $ab !== 'html') {
            return $ab;
        }
        // Entpacktes Archiv oder gar kein LoxBerry: der feste Name, bewacht.
        if ($home !== '') {
            $fremd = $home . '/config/plugins/' . KO_ORDNER;
            if (is_dir($fremd)
                && !is_file($fremd . '/' . KO_CFGDATEI)
                && count((array) @scandir($fremd)) > 2) {
                // Dort liegt etwas, und es ist nicht unseres. Nicht anfassen.
                return '';
            }
        }
        return KO_ORDNER;
    }
}

/** Alle Pfade an einer Stelle. */
if (!function_exists('ko_paths')) {
    function ko_paths()
    {
        static $p = null;
        if ($p !== null) { return $p; }

        $home = (string) getenv('LBHOMEDIR');
        if ($home === '') { $home = ko_wurzel_ermitteln(); }
        $plugin = ko_plugin_ordner($home);

        if ($home !== '' && $plugin !== '') {
            $p = array(
                'home'      => $home,
                'plugin'    => $plugin,
                'configdir' => $home . '/config/plugins/' . $plugin,
                'config'    => $home . '/config/plugins/' . $plugin . '/' . KO_CFGDATEI,
                // Die Zweitschrift liegt NEBEN dem Ordner, nicht darin: der
                // Installer raeumt config/plugins/<ordner>/ beim Upgrade
                // vollstaendig ab. Der Punkt im Namen ist der Unterschied.
                'zweit'     => $home . '/config/plugins/' . $plugin . '.backup.json',
                'formkey'   => $home . '/config/plugins/' . $plugin . '/ko_formkey',
                'log'       => $home . '/log/plugins/' . $plugin . '/kodi.log',
                'zustand'   => $home . '/data/plugins/' . $plugin . '/zustand.json',
                'bin'       => $home . '/bin/plugins/' . $plugin,
                'data'      => $home . '/data/plugins/' . $plugin,
                'lang'      => $home . '/templates/plugins/' . $plugin . '/lang',
                'cron'      => $home . '/system/cron/cron.01min/' . $plugin,
            );
            return $p;
        }

        // Entpacktes Archiv: alles liegt nebeneinander unter dem Paketordner.
        $basis = dirname(__DIR__);
        $p = array(
            'home'      => $home,
            'plugin'    => $plugin !== '' ? $plugin : KO_ORDNER,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/' . KO_CFGDATEI,
            'zweit'     => $basis . '/config/kodi.backup.json',
            'formkey'   => $basis . '/config/ko_formkey',
            'log'       => sys_get_temp_dir() . '/kodi_ng/kodi.log',
            'zustand'   => sys_get_temp_dir() . '/kodi_ng/zustand.json',
            'bin'       => $basis . '/bin',
            'data'      => $basis . '/data',
            'lang'      => $basis . '/templates/lang',
            'cron'      => '',
        );
        return $p;
    }
}

/* ================================================================
   Vorgaben - EINE Liste, aus der alles Weitere entsteht
   ================================================================

   Aus ihr entstehen: die Vollstaendigkeitspruefung, die Meldung ueber fremde
   Schluessel, die Sicherungsdatei und das Zurueckspielen. Ein Schluessel, der
   hier fehlt, fehlt in allen vieren - und in der Sicherung faellt es erst auf,
   wenn jemand sie zurueckspielt. */
if (!function_exists('ko_vorgaben')) {
    function ko_vorgaben()
    {
        return array(
            'mqtt_topic'  => 'kodi',
            'kodi_host'   => '127.0.0.1',
            'kodi_port'   => '8080',
            'kodi_user'   => '',
            'kodi_pass'   => '',
            /* AB WERK AUS. Der Statussender veroeffentlicht Themen im Broker;
             * eine bestehende Anlage bekaeme sie mit dem Update ungefragt.
             * "Neue Funktionen ab Werk aus" - der Reiter Test sagt deutlich,
             * dass er aus ist, damit niemand vergeblich auf Werte wartet. */
            'sender_ein'  => '0',
            'sender_takt' => '300',
            /* Der Aufruf von Kodi ueber JSON-RPC ist im Hause nicht an einem
             * Geraet erprobt worden - es lief keines. Deshalb ein eigener
             * Schalter, ebenfalls ab Werk aus. */
            'rpc_ein'     => '0',
        );
    }
}

/** Werte pruefen - dieselbe Pruefung fuer Formular UND Sicherung.
 *
 *  Zwei getrennte Pruefungen waeren zwei Wahrheiten: das Formular wies bisher
 *  einen unmoeglichen Port ab, eine von Hand bearbeitete Sicherungsdatei kaeme
 *  daran vorbei. Rueckgabe: der berichtigte Wert, oder null bei Verstoss.
 */
if (!function_exists('ko_wert_pruefen')) {
    function ko_wert_pruefen($schluessel, $wert)
    {
        $w = trim((string) $wert);
        switch ($schluessel) {
            case 'mqtt_topic':
                /* DIESELBE Positivliste wie im Helfer (%addon_muster in
                 * elevatedhelper.pl). Bis zur zweiten Sicht strich diese
                 * Stelle nur Steuerzeichen und Leerraum - gemessen kamen
                 * damit Themen wie "wohn#zimmer", "kodi+1" oder "kodi&co"
                 * durch, die der Helfer beim Setzen der Addon-Einstellungen
                 * anschliessend abwies. Zwei Wahrheiten ueber denselben
                 * Wert, und die Abweisung kam erst zwei Knoepfe spaeter.
                 *
                 * # und + sind ausserdem MQTT-Platzhalter und in einem Thema,
                 * unter dem VEROEFFENTLICHT wird, unzulaessig. */
                $w = trim($w, '/');
                return preg_match('#^[A-Za-z0-9._/-]{1,64}$#', $w) ? $w : null;
            case 'kodi_host':
                $w = preg_replace('/[\x00-\x1F\x7F"\'\s]/', '', $w);
                return $w === '' ? null : $w;
            case 'kodi_port':
                // ctype_digit() steckt in einer Erweiterung, preg_match nicht.
                if (!preg_match('/^[0-9]{1,5}$/', $w)) { return null; }
                $n = (int) $w;
                return ($n >= 1 && $n <= 65535) ? (string) $n : null;
            case 'kodi_user':
            case 'kodi_pass':
                // Zugangsdaten werden NICHT gefiltert, nur von Steuerzeichen
                // befreit - ein Passwort darf alles enthalten.
                return preg_replace('/[\x00-\x1F\x7F]/', '', $w);
            case 'sender_ein':
            case 'rpc_ein':
                return in_array($w, array('0', '1'), true) ? $w : null;
            case 'sender_takt':
                if (!preg_match('/^[0-9]{1,5}$/', $w)) { return null; }
                $n = (int) $w;
                /* Die Grenze steht GENAU EINMAL - hier. Das Formular begrenzt
                 * nicht noch einmal auf eigene Faust; sonst waere jeder Wert
                 * darueber wirkungslos, ohne dass es irgendwo staende. */
                return ($n >= 60 && $n <= 3600) ? (string) $n : null;
        }
        return null;
    }
}

/* ================================================================
   Konfiguration
   ================================================================ */

/**
 * Eine Datei lesen - erst fragen, dann oeffnen.
 *
 * Das @-Zeichen schaltet die AUSGABE ab, nicht den Fehler. Ein gesetzter
 * Fehlerbehandler sieht die Warnung trotzdem, und im Pruefstand rendern.py
 * steht sie dann als Befund da - genau so ist es beim ersten Lauf dieser
 * Fassung gewesen. Und die Dateien, die hier gelesen werden, fehlen
 * regelmaessig: die Konfiguration vor dem ersten Speichern, die Zustandsdatei
 * vor dem ersten Lauf des Senders.
 *
 * Rueckgabe ist IMMER eine Zeichenkette. Ob "leer" heisst "gibt es nicht" oder
 * "ist leer", entscheidet der Aufrufer mit is_file() - hier waeren beide
 * gleich, und genau deshalb steht der Unterschied nicht in dieser Funktion.
 */
if (!function_exists('ko_lesen')) {
    function ko_lesen($pfad)
    {
        if ($pfad === '' || !is_file($pfad)) { return ''; }
        $d = @file_get_contents($pfad);
        return $d === false ? '' : (string) $d;
    }
}

/** Eine JSON-Datei lesen. */
if (!function_exists('ko_json_lesen')) {
    function ko_json_lesen($pfad)
    {
        if (!is_file($pfad)) { return array(); }
        $d = json_decode(ko_lesen($pfad), true);
        return is_array($d) ? $d : array();
    }
}

/** Atomar schreiben - und die Rechte gehoeren an das ANLEGEN, nicht hinterher.
 *
 *  "Schreiben, dann chmod" laesst die Datei fuer die Dauer des Schreibens mit
 *  den Vorgaben der umask stehen. In dieser Konfiguration steht seit 1.2.0 das
 *  Kodi-Passwort im Klartext; das ist der Unterschied zwischen "kurz lesbar"
 *  und "nie lesbar".
 */
if (!function_exists('ko_json_schreiben')) {
    function ko_json_schreiben($pfad, $daten, $rechte = null)
    {
        $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { return false; }
        if (!is_dir(dirname($pfad))) { @mkdir(dirname($pfad), 0775, true); }
        // Die Prozessnummer im Namen: sonst zerlegen zwei gleichzeitige
        // Schreiber einander die Nebendatei.
        $tmp = $pfad . '.tmp.' . getmypid();
        $fh = @fopen($tmp, 'c');
        if ($fh === false) { return false; }
        if ($rechte !== null) { @chmod($tmp, $rechte); }
        $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
        fflush($fh);
        fclose($fh);
        if (!$ok) { @unlink($tmp); return false; }
        if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
        return true;
    }
}

/**
 * Die Lage der Konfiguration - vier Zustaende, und jeder bekommt seinen Satz.
 *
 *   ok            gelesen
 *   leer          es gibt noch keine (vor dem ersten Speichern)
 *   zweitschrift  die eigentliche fehlte, die Zweitschrift ist eingesprungen
 *   kaputt        die Datei war da und liess sich nicht auswerten
 *
 * "kaputt" wird NICHT stillschweigend zu "leer". Eine beschaedigte
 * Konfiguration ist ein Fehler, kein leerer Wert - und die Zweitschrift wird
 * dabei nicht mitgerissen: aus ihr wird gelesen, nicht in sie geschrieben.
 */
if (!function_exists('ko_config_lage')) {
    function ko_config_lage()
    {
        // ko_config() setzt die Lage beim ersten Lesen. Der Aufruf hier ist
        // deshalb kein Nebeneffekt, sondern die Voraussetzung: wer die Lage
        // fragt, bevor irgendjemand gelesen hat, bekaeme sonst "leer"
        // zurueck - und das waere eine Aussage ueber die eigene Reihenfolge,
        // nicht ueber die Datei.
        ko_config();
        return isset($GLOBALS['ko_lage_intern']) ? $GLOBALS['ko_lage_intern'] : 'leer';
    }
}

if (!function_exists('ko_config')) {
    function ko_config($neu_lesen = false)
    {
        static $cfg = null;
        static $lage = 'leer';
        if ($cfg !== null && !$neu_lesen) { return $cfg; }

        $p = ko_paths();
        $roh = trim(ko_lesen($p['config']));

        if ($roh !== '' && $roh !== '{}') {
            $d = json_decode($roh, true);
            if (is_array($d)) {
                $lage = 'ok';
                $cfg = $d;
            } else {
                /* Beschaedigt. Beiseitelegen, damit der naechste Blick sie
                 * noch hat, und die Zweitschrift versuchen. Ueberschrieben
                 * wird sie NICHT - dann waere der Beleg fort. */
                @copy($p['config'], $p['config'] . '.kaputt');
                $lage = 'kaputt';
                $cfg = ko_json_lesen($p['zweit']);
            }
        } elseif (is_file($p['zweit'])) {
            $z = ko_json_lesen($p['zweit']);
            if ($z) {
                // Zurueckschreiben, sonst springt sie bei jedem Aufruf neu ein.
                // Die Lage bleibt trotzdem 'zweitschrift': der Anwender soll
                // EINMAL erfahren, dass seine Konfiguration gefehlt hat. Beim
                // naechsten Aufruf steht die Datei da und die Lage ist 'ok' -
                // das ist die Wirkung, nicht ein Verschweigen.
                ko_json_schreiben($p['config'], $z, 0600);
                $lage = 'zweitschrift';
                $cfg = $z;
            } else {
                $lage = 'leer';
                $cfg = array();
            }
        } else {
            $lage = 'leer';
            $cfg = array();
        }

        /* Alles auf Zeichenketten: die Sicherungsdatei kennt nur Text, und ein
         * Schluessel, der einmal als Zahl und einmal als Text dasteht, laesst
         * jeden Vergleich auf Gleichheit scheitern.
         *
         * UND EIN FELD ODER OBJEKT WIRD ENTFERNT, nicht stehengelassen.
         * Gemessen: mit {"mqtt_topic":["a","b"]} in der kodi.json warf
         * htmlspecialchars() unter PHP 8.4 einen TypeError - aus der
         * Vorlagenprobe heraus, also bei JEDEM Seitenaufbau, und wegen
         * display_errors=0 sah der Anwender eine LEERE SEITE. Unter PHP 7.4
         * war es nur eine Warnung. Das Plugin selbst schreibt nie Felder;
         * eine von Hand bearbeitete Datei kann sie enthalten.
         *
         * Entfernt heisst hier: der Schluessel faellt weg und wird von der
         * Vorgabenschleife darunter mit seiner Vorgabe besetzt. Der Verlust
         * wird protokolliert - stillschweigend zurechtbiegen waere das, was
         * die Hausregel verbietet. */
        foreach ($cfg as $k => $v) {
            if (is_bool($v)) { $cfg[$k] = $v ? '1' : '0'; }
            elseif (is_scalar($v)) { $cfg[$k] = (string) $v; }
            elseif ($v === null) { unset($cfg[$k]); }
            else {
                unset($cfg[$k]);
                ko_log('Konfiguration: der Schluessel ' . $k . ' trug kein einfaches '
                     . 'Datum (Feld oder Objekt) und wurde uebergangen.');
            }
        }

        // Ergaenzen, nicht schreiben: geschrieben wird in
        // ko_cfg_vervollstaendigen(), und zwar EINMAL.
        foreach (ko_vorgaben() as $k => $v) {
            if (!array_key_exists($k, $cfg) || $cfg[$k] === null) { $cfg[$k] = $v; }
        }

        // Die Lage nach aussen reichen.
        $GLOBALS['ko_lage_intern'] = $lage;
        return $cfg;
    }
}

/** Welche Schluessel FEHLEN in der Datei, und welche stehen darin, die es
 *  nicht gibt? Beides gehoert genannt: ein fremder Eintrag wirkt nicht, und
 *  genau das ueberrascht - man hat etwas eingestellt, es steht in der Datei,
 *  und es tut nichts. Geloescht wird Fremdes NICHT. */
if (!function_exists('ko_cfg_lage')) {
    function ko_cfg_lage()
    {
        /* Auch hier gilt: eine beschaedigte Datei ist nicht dasselbe wie eine
         * leere. Ohne diese Zeile meldete die Selbstpruefung bei einer
         * kaputten kodi.json "es fehlen 8 von 8" - eine Zahl, die stimmt und
         * das Falsche sagt. Die Zeile darueber nennt den wahren Zustand. */
        if (ko_config_lage() === 'kaputt') {
            return array('fehlend' => array(), 'fremd' => array(),
                         'anzahl' => count(ko_vorgaben()), 'kaputt' => true);
        }
        $vorgaben = ko_vorgaben();
        $datei    = ko_json_lesen(ko_paths()['config']);
        $fehlend  = array_values(array_diff(array_keys($vorgaben), array_keys($datei)));
        $fremd    = array_values(array_diff(array_keys($datei), array_keys($vorgaben)));
        sort($fehlend);
        sort($fremd);
        return array('fehlend' => $fehlend, 'fremd' => $fremd,
                     'anzahl' => count($vorgaben), 'kaputt' => false);
    }
}

/** Fehlende Schluessel EINMAL mit ihrer Vorgabe in die Datei schreiben.
 *
 *  Ergaenzen beim Lesen laesst die Datei lueckenhaft, und "fehlt" ist dann von
 *  "steht auf dem Vorgabewert" nicht mehr zu unterscheiden. Geprueft wird mit
 *  array_key_exists, nicht mit isset: isset haelt einen leeren Wert fuer nicht
 *  vorhanden und schriebe eine bewusst geleerte Angabe jedes Mal zurueck. */
if (!function_exists('ko_cfg_vervollstaendigen')) {
    function ko_cfg_vervollstaendigen()
    {
        $p = ko_paths();

        /* ERST DIE LAGE, DANN SCHREIBEN.
         *
         * Hier stand ein Fehler, der beim blossen Aufruf der Seite Daten
         * vernichtet hat - gemessen, nicht vermutet:
         *
         * ko_json_lesen() bildet "nicht auswertbar" auf denselben Wert ab wie
         * "leer", naemlich array(). Bei einer beschaedigten kodi.json galten
         * damit ALLE Schluessel als fehlend, die Wache is_file() trug nicht
         * (die Datei war ja da), und ko_config_schreiben() ueberschrieb
         * kodi.json UND die Zweitschrift mit lauter Vorgabewerten. Ein
         * einziger GET auf den Reiter Test genuegte; Adresse, Thema und
         * Kodi-Passwort waren in beiden Kopien fort, die .kaputt-Beweiskopie
         * wurde nie angelegt, und die Selbstpruefung meldete "heil: ja".
         *
         * Vervollstaendigt wird deshalb nur, wenn die Datei WIRKLICH gelesen
         * werden konnte. Bei 'kaputt' wird nichts angefasst - der Anwender
         * bekommt statt dessen die rote Zeile im Reiter Test und die Datei
         * .kaputt daneben. Bei 'leer' gibt es nichts zu vervollstaendigen;
         * das erste Speichern legt die Datei vollstaendig an.
         */
        $lage = ko_config_lage();
        if ($lage !== 'ok' && $lage !== 'zweitschrift') { return array(); }

        $datei = ko_json_lesen($p['config']);
        $fehlten = array();
        foreach (ko_vorgaben() as $k => $v) {
            if (!array_key_exists($k, $datei)) { $datei[$k] = $v; $fehlten[] = $k; }
        }
        if ($fehlten && is_file($p['config'])) {
            ko_config_schreiben($datei);
            ko_log('Konfiguration vervollstaendigt: ' . implode(', ', $fehlten));
        }
        return $fehlten;
    }
}

/** Schreiben - und die Zweitschrift GEPRUEFT mitziehen.
 *
 *  Ein blankes copy() mit anschliessendem "return true" gaebe dem Anwender
 *  beim naechsten Upgrade stillschweigend einen aelteren Stand zurueck. */
if (!function_exists('ko_config_schreiben')) {
    function ko_config_schreiben($cfg)
    {
        $p = ko_paths();
        if (!ko_json_schreiben($p['config'], $cfg, 0600)) { return false; }
        if (!ko_json_schreiben($p['zweit'], $cfg, 0600)) {
            ko_log('FEHLER: Zweitschrift ' . $p['zweit'] . ' liess sich nicht schreiben.');
            return false;
        }
        return true;
    }
}

/* ================================================================
   Protokoll
   ================================================================ */

if (!function_exists('ko_log')) {
    function ko_log($text)
    {
        $datei = ko_paths()['log'];
        if (!is_dir(dirname($datei))) { @mkdir(dirname($datei), 0775, true); }
        /* Kappung nach dem Hausmuster: ab 500 kB bleiben die letzten 200
         * Zeilen stehen. Ohne sie waechst die Datei unbegrenzt - auf einer
         * SD-Karte ist das kein Schoenheitsfehler. */
        if (is_file($datei) && filesize($datei) > 512000) {
            // Mit @: zwischen filesize() und file() kann die Datei
            // verschwinden - etwa weil jemand im Reiter Logdateien geleert
            // hat. Die Nachbarzeilen unterdruecken alle, diese tat es nicht.
            $rest = array_slice(@file($datei, FILE_IGNORE_NEW_LINES) ?: array(), -200);
            @file_put_contents($datei, implode("\n", $rest) . "\n");
        }
        @file_put_contents($datei, '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
    }
}

/** Die letzten Zeilen einer Protokolldatei - rueckwaerts gelesen.
 *
 *  Nicht die ganze Datei einlesen und nicht exec("tail"). An 12.000 Zeilen
 *  gemessen: file()+array_reverse 0,37 ms bei 2 MB Spitzenspeicher,
 *  tail 2,17 ms wegen des Prozessstarts, dieser Weg 0,05 ms bei 0 MB. */
if (!function_exists('ko_log_ende')) {
    function ko_log_ende($datei, $anzahl = 300, $block = 8192)
    {
        if (!is_file($datei)) { return array(); }
        $fp = @fopen($datei, 'rb');
        if ($fp === false) { return array(); }
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $puffer = '';
        $zeilen = array();
        while ($pos > 0 && count($zeilen) <= $anzahl) {
            $lese = (int) min($block, $pos);
            $pos -= $lese;
            fseek($fp, $pos, SEEK_SET);
            $puffer = fread($fp, $lese) . $puffer;
            $zeilen = explode("\n", $puffer);
        }
        fclose($fp);
        $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
        return array_slice(array_reverse($zeilen), 0, $anzahl);
    }
}

/* ================================================================
   Der Helfer mit erhoehten Rechten
   ================================================================ */

/** elevatedhelper.pl ueber sudo aufrufen. $args ist bereits maskiert. */
if (!function_exists('ko_helper')) {
    function ko_helper($args)
    {
        $p = ko_paths();
        $cmd = 'sudo ' . escapeshellcmd($p['bin'] . '/elevatedhelper.pl') . ' ' . $args . ' 2>/dev/null';
        return (string) @shell_exec($cmd);
    }
}

/**
 * Die Antwort des Helfers als Feld - an EINER Stelle ausgewertet.
 *
 * WARUM DER KOPFBLOCK ABGESCHNITTEN WIRD. elevatedhelper.pl baut seine
 * Antwort ueber CGI. Dessen header() fragt nicht, wo es laeuft; laeuft der
 * Helfer nicht unter einem Webserver, kann trotzdem ein Kopfblock
 * ("Status: 200 OK", "Content-Type: ...", Leerzeile) vor dem JSON stehen -
 * und dann liefert json_decode NULL. Die Oberflaeche stuende vor lauter
 * Fragezeichen, und der Statussender schriebe bei jedem Takt "der Helfer hat
 * nicht geantwortet" ins Protokoll, ohne dass irgendwo ein Fehler auftaucht.
 *
 * Der Helfer gibt die Kopfzeilen seit 1.2.0 nur noch unter einem Webserver
 * aus. Hier steht die zweite Haelfte derselben Absicherung: gefunden wird die
 * erste geschweifte Klammer, und ab da wird gelesen. Beide Seiten tragen fuer
 * sich - eine Absicherung, die von der anderen abhaengt, ist keine.
 */
if (!function_exists('ko_helper_json')) {
    function ko_helper_json($args)
    {
        $roh = ko_helper($args);
        $pos = strpos($roh, '{');
        if ($pos === false) { return null; }
        $j = json_decode(substr($roh, $pos), true);
        return is_array($j) ? $j : null;
    }
}

/** Zustand von Dienst, Autostart, Lizenzen und Seriennummer. */
if (!function_exists('ko_status')) {
    function ko_status($neu = false)
    {
        static $st = null;
        if ($st !== null && !$neu) { return $st; }
        $j = ko_helper_json('action=query');
        $st = is_array($j) ? $j : array();
        return $st;
    }
}

/**
 * Ist Kodi ueberhaupt installiert?
 *
 * WOZU: Bis 1.2.1 fragte das niemand. Scheitert das apt-get beim Installieren
 * des Plugins, wird die systemd-Unit trotzdem angelegt und eingeschaltet, ihr
 * ExecStart zeigt ins Leere, und `systemctl is-active` meldet inactive. Die
 * Oberflaeche sagte daraufhin "Kodi: gestoppt" - eine Falschaussage, denn es
 * gibt nichts zu starten. Der Anwender drueckt Start, systemd bricht mit
 * 203/EXEC ab und gibt nach fuenf Versuchen auf; der wahre Grund stand
 * nirgends.
 *
 * DER PFAD KOMMT AUS DER UNIT, nicht aus dem Gedaechtnis. Er steht in
 * data/kodi_ng.service genau einmal; ihn hier ein zweites Mal hinzuschreiben
 * waere eine zweite Wahrheit, die beim naechsten Umbau der Unit zurueckbleibt.
 * Ist die Unit nicht lesbar, wird das GEMELDET und nichts geraten.
 *
 * Gefragt werden zwei Dinge, und sie sind verschieden:
 *   datei  gibt es die ausfuehrbare Datei? Daran haengt, ob der Dienst
 *          ueberhaupt starten kann.
 *   paket  kennt dpkg ein installiertes Paket "kodi"? Das ist die Auskunft
 *          ueber die Herkunft - ein von Hand gebautes Kodi hat keins und
 *          laeuft trotzdem.
 */
if (!function_exists('ko_kodi_paket')) {
    function ko_kodi_paket()
    {
        static $i = null;
        if ($i !== null) { return $i; }

        $exec = '';
        $unit = ko_lesen(ko_paths()['data'] . '/kodi_ng.service');
        if ($unit === '') {
            // Im entpackten Archiv liegt sie eine Ebene hoeher.
            $unit = ko_lesen(dirname(__DIR__) . '/data/kodi_ng.service');
        }
        if (preg_match('/^ExecStart=(\S+)/m', $unit, $m)) { $exec = $m[1]; }

        $paket = '';
        $roh = trim((string) @shell_exec(
            'dpkg-query -W -f=\'${Status}|${Version}\' kodi 2>/dev/null'));
        /* "install ok installed" ist der einzige Zustand, der zaehlt.
         * "deinstall ok config-files" heisst entfernt, die Konfiguration liegt
         * noch da - das ist NICHT installiert. */
        if (strpos($roh, 'install ok installed') === 0) {
            $t = explode('|', $roh);
            $paket = isset($t[1]) && trim($t[1]) !== '' ? trim($t[1]) : '?';
        }

        $i = array(
            'exec'  => $exec,
            'datei' => ($exec !== '' && @is_file($exec)),
            'paket' => $paket,
        );
        return $i;
    }
}

/**
 * Steht Kodis eigene Fernsteuerung an?
 *
 * Das Plugin schaltet sie beim Einspielen selbst ein - postroot.sh legt
 * /home/kodi/.kodi/userdata/advancedsettings.xml an, und darin steht
 * <webserver>true</webserver>. Ohne das antwortet weder die Weboberflaeche
 * noch JSON-RPC, und beide Knoepfe des Plugins fuehrten ins Leere, ohne dass
 * irgendwo staende warum.
 *
 * Rueckgabe null = der Helfer schweigt (dann wird ein Strich gemeldet, kein
 * Kreuz). Sonst ein Feld mit 'vorhanden', 'webserver' und 'port'.
 */
if (!function_exists('ko_adv_lesen')) {
    function ko_adv_lesen()
    {
        static $a = null;
        static $gelesen = false;
        if ($gelesen) { return $a; }
        $gelesen = true;
        $j = ko_helper_json('action=advread');
        if (!is_array($j) || !isset($j['status']) || $j['status'] !== 'OK') {
            return $a;
        }
        $a = array(
            'vorhanden' => !empty($j['vorhanden']),
            'webserver' => isset($j['webserver']) ? (string) $j['webserver'] : '',
            'port'      => isset($j['port']) ? (string) $j['port'] : '',
            'datei'     => isset($j['datei']) ? (string) $j['datei'] : '',
        );
        return $a;
    }
}

/**
 * Der Wirtsname, unter dem der ANWENDER diesen LoxBerry gerade sieht.
 *
 * Aus der laufenden Anfrage, ohne Port. Sie ist die einzige Adresse, von der
 * feststeht, dass sie ihn zum LoxBerry fuehrt - eine aus der Netzkonfiguration
 * gelesene IP kann hinter einem VPN oder einem vorgeschalteten Server falsch
 * sein. Der Kopf Host: kommt allerdings vom Browser und damit von aussen,
 * deshalb die Positivliste.
 *
 * Rueckgabe '' heisst "nicht feststellbar" - die Aufrufer bieten dann keinen
 * Verweis an, statt einen ins Leere zeigenden.
 */
if (!function_exists('ko_sicht_wirt')) {
    function ko_sicht_wirt()
    {
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $h = $_SERVER['HTTP_HOST'];
            if (preg_match('/^\[[0-9A-Fa-f:]{2,45}\]/', $h, $m)) { return $m[0]; }
            if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9._-]{0,252})/', $h, $m)) { return $m[0]; }
        }
        return ko_lokale_ip();
    }
}

/**
 * Unter welcher Adresse erreicht der ANWENDER die Weboberflaeche von Kodi?
 *
 * Das ist NICHT die Adresse aus der Konfiguration. Dort steht ab Werk
 * 127.0.0.1, und das ist richtig: das Plugin laeuft auf demselben Geraet wie
 * Kodi. Im Browser des Anwenders meint 127.0.0.1 aber dessen EIGENEN Rechner
 * - dort laeuft kein Kodi, und der Browser meldet ERR_CONNECTION_REFUSED.
 * Genau diese Verwechslung ist am 28.08.2026 vorgekommen.
 *
 * Genommen wird deshalb die Adresse, unter der der Anwender DIESE SEITE
 * gerade sieht. Sie ist die einzige, von der feststeht, dass sie ihn zum
 * LoxBerry fuehrt - eine aus der Netzkonfiguration gelesene IP kann hinter
 * einem VPN oder einem vorgeschalteten Server falsch sein.
 *
 * Zeigt kodi_host dagegen auf ein ANDERES Geraet, gilt diese Angabe: dann
 * laeuft Kodi eben dort.
 *
 * Rueckgabe '' heisst "keine nennbare Adresse" - dann wird kein Knopf
 * angeboten, statt einen ins Leere zeigenden.
 */
if (!function_exists('ko_kodi_url')) {
    function ko_kodi_url()
    {
        $cfg = ko_config();
        $port = (int) $cfg['kodi_port'];
        if ($port < 1 || $port > 65535) { return ''; }

        $host = trim((string) $cfg['kodi_host']);
        $eigen = array('127.0.0.1', 'localhost', '::1', '[::1]', '0.0.0.0');
        if ($host !== '' && !in_array(strtolower($host), $eigen, true)) {
            return 'http://' . $host . ':' . $port . '/';
        }

        // Kodi laeuft auf diesem LoxBerry - siehe ko_sicht_wirt().
        $host = ko_sicht_wirt();
        if ($host === '') { return ''; }
        return 'http://' . $host . ':' . $port . '/';
    }
}

/* ================================================================
   MQTT
   ================================================================ */

/**
 * Zustand UND FASSUNG des LoxBerry-MQTT-Gateways - aus EINEM Dateizugriff.
 *
 * Bis 1.1.9 stand general.json an zwei Stellen offen: einmal fuer den
 * UDP-Port, einmal fuer den Autostart - und letzteres mit einem fest
 * verdrahteten Installationsverzeichnis als Rueckfall.
 *
 * Der Pfad wird hier ABSICHTLICH NICHT AUSGESCHRIEBEN, auch nicht als
 * Beispiel: der Pluginpruefer von LoxBerry liest die Dateien und sucht die
 * Zeichenkette, ohne zwischen Code und Kommentar zu unterscheiden. Ein
 * erklaerender Satz, der sie enthaelt, loest dieselbe Warnung aus wie der
 * Fehler, den er beschreibt.
 *
 * Die Fassung steht als Mqtt.Gatewayversion (ab Werk 1) und entscheidet, was
 * der Anwender tun muss:
 *   V1  Das Abo wird von Hand eingetragen - ohne den Eintrag kommt am
 *       Miniserver nichts an. Das ist die haeufigste Fehlerursache ueberhaupt.
 *   V2  Das Gateway erkennt die Themengruppe selbst; in den Abonnements werden
 *       nur noch die gewuenschten Datenpunkte angehakt. Belegt am LoxBerry-Kern
 *       (webfrontend/htmlauth/system/mqtt-gateway.cgi, Zweig master): dort
 *       schaltet FORM_DISABLE_BUTTONS bei Fassung 2 die Knoepfe der
 *       Abonnement-Seite ab - von Hand eintragen kann man nichts mehr.
 *
 * DASS V2 die Themen von SELBST erkennt, ist NICHT im Hause gemessen. Es steht
 * in der Oberflaeche eines fremden Plugins und passt zu den abgeschalteten
 * Knoepfen - eine Sekundaerquelle. Wer es braucht, misst es an einer laufenden
 * V2 nach.
 *
 * Rueckgabe: null, wenn general.json nicht lesbar ist - sonst ein Feld mit
 * autostart (bool), fassung (int, 0 = unbekannt) und udpinport (int).
 */
if (!function_exists('ko_mqtt_gateway_info')) {
    function ko_mqtt_gateway_info()
    {
        static $i = null;
        static $gelesen = false;
        if ($gelesen) { return $i; }
        $gelesen = true;

        $home = ko_paths()['home'];
        if ($home === '') { $i = null; return $i; }
        $d = ko_json_lesen($home . '/config/system/general.json');
        if (!isset($d['Mqtt']) || !is_array($d['Mqtt'])) { $i = null; return $i; }

        $m = $d['Mqtt'];
        $auto = isset($m['Gatewayautostart']) ? $m['Gatewayautostart'] : '';
        $udp = 0;
        if (isset($m['Udpinport'])) { $udp = (int) $m['Udpinport']; }
        if (!$udp && isset($d['mqtt']['udpinport'])) { $udp = (int) $d['mqtt']['udpinport']; }
        $i = array(
            'autostart' => in_array((string) $auto, array('1', 'true'), true),
            // 0 = nicht lesbar. NICHT auf 1 vorbelegen: "unbekannt" und
            // "Fassung 1" sind verschiedene Aussagen, und die Oberflaeche
            // behandelt sie verschieden.
            'fassung'   => isset($m['Gatewayversion']) ? (int) $m['Gatewayversion'] : 0,
            'udpinport' => $udp,
        );
        return $i;
    }
}

/** Der UDP-Eingang des Gateways, 0 wenn nicht ermittelbar. */
if (!function_exists('ko_mqtt_port')) {
    function ko_mqtt_port()
    {
        $i = ko_mqtt_gateway_info();
        return $i === null ? 0 : (int) $i['udpinport'];
    }
}

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einem
 * Filmtitel, einer Fehlermeldung oder der Ausgabe eines Systembefehls -
 * zerlegt die Uebertragung, und aus den Bruchstuecken bildet das Gateway
 * erfundene Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und
 * Wert trennt.
 */
if (!function_exists('ko_mqtt_wert_saeubern')) {
    function ko_mqtt_wert_saeubern($v)
    {
        $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
        $wert = trim(preg_replace('/ {2,}/', ' ', $wert));
        /* Ein LEERER Wert wird zu "-".
         *
         * Sonst ginge die Zeile mit einem abschliessenden Leerzeichen und
         * leerer Nutzlast hinaus, und was das Gateway daraus macht, ist hier
         * nicht gemessen. Das Kodi-Addon macht es an derselben Stelle seit
         * jeher so; zwei Wege durch dasselbe Nadeloehr brauchen dieselbe
         * Absicherung, sonst ist eine davon die stille. Der Bindestrich steht
         * so auch in der Themen-Tabelle.
         */
        return $wert !== '' ? $wert : '-';
    }
}

/**
 * Werte per MQTT veroeffentlichen - ueber den UDP-Relay des Gateways.
 *
 * stream_socket_client() statt socket_create(): die Socket-Erweiterung ist
 * nicht garantiert geladen, und ihr Fehlen ist KEIN abfangbarer Fehler,
 * sondern ein fataler - "Call to undefined function". Das @-Zeichen haette
 * daran nichts geaendert. Bis 1.1.9 stand hier socket_create(), und
 * php-sockets stand nicht in dpkg/apt.
 *
 * Rueckgabe: array(gesendet, meldung)
 */
if (!function_exists('ko_mqtt_publish')) {
    function ko_mqtt_publish($paare, $retain = true)
    {
        $udp = ko_mqtt_port();
        if (!$udp) {
            ko_log('MQTT: kein UDP-Eingang des Gateways ermittelbar - nichts gesendet.');
            return array(0, 'kein UDP-Eingang');
        }
        $cfg = ko_config();
        $prefix = $cfg['mqtt_topic'] !== '' ? $cfg['mqtt_topic'] : 'kodi';
        $fehler = 0;
        $fp = @stream_socket_client('udp://127.0.0.1:' . $udp, $fehler, $meldung, 2);
        if ($fp === false) {
            ko_log('MQTT: UDP-Relay 127.0.0.1:' . $udp . ' nicht erreichbar - ' . $meldung);
            return array(0, (string) $meldung);
        }
        stream_set_timeout($fp, 2);
        /* UDP ist verbindungslos. stream_socket_client() gelingt auch dann,
         * wenn am Zielport niemand lauscht, und fwrite() meldet nur, dass der
         * Kern das Paket angenommen hat - nicht, dass es jemand bekommen hat.
         * Der Zaehler unten zaehlt also UEBERGEBENE Werte. Ob sie ankommen,
         * beantwortet allein der MQTT Finder; die Themen-Tabelle im Reiter
         * Einbindung sagt deshalb, wo man nachsieht. */
        $befehl = $retain ? 'retain' : 'publish';
        $n = 0;
        foreach ($paare as $k => $v) {
            // null wird uebersprungen, '' und '0' NICHT: eine leere Zeichenkette
            // ist ein Wert, null ist keiner.
            if ($v === null) { continue; }
            $msg = $befehl . ' ' . $prefix . '/' . $k . ' ' . ko_mqtt_wert_saeubern($v);
            if (@fwrite($fp, $msg) !== false) { $n++; }
        }
        fclose($fp);
        ko_log('MQTT: ' . $n . ' Werte an ' . $prefix . '/ (Gateway-Relay 127.0.0.1:' . $udp . ')');
        return array($n, '');
    }
}

/**
 * Die Themenliste - EINE Quelle.
 *
 * Aus ihr entstehen die Tabelle im Reiter "Einbindung in Loxone", die
 * Loxone-Vorlage und die Pruefzeile, die beide gegen den Sendecode haelt. Bis
 * 1.1.9 stand die Tabelle fuer sich und nannte zwei Themen, die es gar nicht
 * gab (status, titel) - waehrend die drei, die das Addon wirklich sendet,
 * fehlten.
 *
 * quelle: 'plugin' = der Statussender bin/kodi_ng_status.php
 *         'addon'  = das Kodi-Addon service.callback.handler
 * zahl:   true, wenn das Thema in die Vorlage der virtuellen Eingaenge gehoert.
 *         Textthemen bekommen keinen virtuellen Eingang - auch nicht im
 *         XML-Weg.
 */
if (!function_exists('ko_themen')) {
    function ko_themen()
    {
        return array(
            array('name' => 'dienst',      'quelle' => 'plugin', 'zahl' => true,
                  'min' => '0', 'max' => '1',          'schl' => 'T_DIENST',      'wschl' => 'V_01'),
            array('name' => 'autostart',   'quelle' => 'plugin', 'zahl' => true,
                  'min' => '0', 'max' => '1',          'schl' => 'T_AUTOSTART',   'wschl' => 'V_01'),
            array('name' => 'erreichbar',  'quelle' => 'plugin', 'zahl' => true,
                  'min' => '0', 'max' => '1',          'schl' => 'T_ERREICHBAR',  'wschl' => 'V_01'),
            array('name' => 'zeitstempel', 'quelle' => 'plugin', 'zahl' => true,
                  'min' => '0', 'max' => '2147483647', 'schl' => 'T_ZEIT',        'wschl' => 'V_ZEIT'),
            array('name' => 'herzschlag',  'quelle' => 'plugin', 'zahl' => true,
                  'min' => '0', 'max' => '2147483647', 'schl' => 'T_HERZ',        'wschl' => 'V_HERZ'),
            array('name' => 'wiedergabe',  'quelle' => 'plugin', 'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_WIEDERGABE',  'wschl' => 'V_WIEDERGABE'),
            array('name' => 'titel',       'quelle' => 'plugin', 'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_TITEL',       'wschl' => 'V_TEXT'),
            array('name' => 'event',       'quelle' => 'addon',  'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_EVENT',       'wschl' => 'V_EVENT'),
            array('name' => 'movie_title', 'quelle' => 'addon',  'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_MOVIETITLE',  'wschl' => 'V_TEXT'),
            array('name' => 'music_title', 'quelle' => 'addon',  'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_MUSICTITLE',  'wschl' => 'V_TEXT'),
            array('name' => 'episode_title', 'quelle' => 'addon', 'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_EPISODETITLE', 'wschl' => 'V_TEXT'),
            /* Es gibt sie wirklich: playing_type() im Addon liefert 'unknown',
             * wenn Kodi etwas abspielt und selbst nicht sagt was. Dann geht
             * unknown_title hinaus. Die Zeile steht hier, weil ein Thema, das
             * ankommt und in keiner Tabelle steht, den Anwender ratlos laesst. */
            array('name' => 'unknown_title', 'quelle' => 'addon', 'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_UNKNOWNTITLE', 'wschl' => 'V_TEXT'),
            array('name' => 'screensaver', 'quelle' => 'addon',  'zahl' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_SCREENSAVER', 'wschl' => 'V_ONOFF'),
        );
    }
}

/* ================================================================
   Kodi ueber JSON-RPC befragen
   ================================================================

   NICHT AM GERAET ERPROBT. Im Hause lief bei der Entwicklung kein Kodi. Die
   Methodennamen stammen aus der JSON-RPC-Dokumentation von Kodi; dass sie
   richtig sind, ist damit BELEGT, nicht ERPROBT. Der Unterschied haengt an
   einem eigenen Schalter (rpc_ein, ab Werk aus), und wo sich die Wirkung nicht
   ablesen laesst, meldet der Aufruf "nicht feststellbar" statt eines Erfolgs.

   Der Weg ist HTTP auf kodi_port (Vorgabe 8080), nicht der TCP-Port 9090:
   ueber 9090 spricht Loxone unmittelbar mit Kodi, dort gibt es keine
   Anmeldung und keine saubere Zeitschranke fuer eine Antwort. */

/** Ein JSON-RPC-Aufruf an Kodi. Rueckgabe: array(ok, ergebnis|fehlertext). */
if (!function_exists('ko_rpc')) {
    function ko_rpc($methode, $params = null, $zeit = 4)
    {
        $cfg = ko_config();
        $host = $cfg['kodi_host'] !== '' ? $cfg['kodi_host'] : '127.0.0.1';
        $port = (int) $cfg['kodi_port'];
        if ($port < 1 || $port > 65535) { return array(false, 'Port unzulaessig'); }

        $rumpf = array('jsonrpc' => '2.0', 'id' => 1, 'method' => (string) $methode);
        if ($params !== null) { $rumpf['params'] = $params; }
        $json = json_encode($rumpf);
        if ($json === false) { return array(false, 'Anfrage liess sich nicht kodieren'); }

        $kopf = "Content-Type: application/json\r\n";
        // Zugangsdaten gehoeren in die Kopfzeile, nicht in die Adresse.
        if ($cfg['kodi_user'] !== '' || $cfg['kodi_pass'] !== '') {
            $kopf .= 'Authorization: Basic '
                   . base64_encode($cfg['kodi_user'] . ':' . $cfg['kodi_pass']) . "\r\n";
        }
        $ctx = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        => $kopf,
            'content'       => $json,
            'timeout'       => $zeit,
            // Ohne das wirft file_get_contents bei 401 eine Warnung und gibt
            // false zurueck - der Statuscode waere dann nicht mehr lesbar,
            // und "nicht erreichbar" saehe genauso aus wie "abgelehnt".
            'ignore_errors' => true,
        )));
        $adresse = 'http://' . $host . ':' . $port . '/jsonrpc';

        /* EIGENER Fehler-Aufnehmer um genau diesen einen Aufruf.
         *
         * Das @-Zeichen schaltet die AUSGABE ab, nicht den Fehler: ein von
         * aussen gesetzter Fehlerbehandler sieht die Warnung trotzdem. Und
         * genau hier ist sie der NORMALFALL - Kodi laeuft nicht, die
         * Verbindung wird abgelehnt, und das ist eine Auskunft, keine
         * Stoerung. Gemessen am Pruefstand: ohne diese drei Zeilen stand bei
         * jedem Seitenaufbau ein Befund da, obwohl das Plugin die Lage
         * sauber als "nein, nicht erreichbar" ausweist.
         *
         * restore_error_handler() setzt den vorherigen Behandler zurueck -
         * nicht ueberschreiben, sonst waere die Oberflaeche danach fuer ALLE
         * Fehler blind. */
        set_error_handler(function () { return true; });
        $antwort = file_get_contents($adresse, false, $ctx);
        restore_error_handler();

        if ($antwort === false) {
            return array(false, 'keine Antwort von ' . $host . ':' . $port);
        }
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
            }
        }
        if ($code === 401) {
            return array(false, 'HTTP 401 - Kodi verlangt Benutzer und Passwort');
        }
        if ($code >= 400) {
            return array(false, 'HTTP ' . $code);
        }
        $d = json_decode($antwort, true);
        if (!is_array($d)) {
            return array(false, 'Antwort ist kein JSON: ' . substr(trim($antwort), 0, 120));
        }
        if (isset($d['error'])) {
            /* Verlustfrei zusammensetzen statt einen Schluessel zu raten:
             * Kodi meldet mal message, mal data.stack.message. Alle lesbaren
             * Teile in ihrer Reihenfolge - dann traegt jede Form. */
            $teile = array();
            array_walk_recursive($d['error'], function ($w) use (&$teile) {
                if (is_scalar($w) && trim((string) $w) !== '') { $teile[] = (string) $w; }
            });
            return array(false, 'Kodi meldet: ' . implode(' / ', array_slice($teile, 0, 6)));
        }
        return array(true, isset($d['result']) ? $d['result'] : null);
    }
}

/**
 * Was Kodi gerade tut.
 *
 * Rueckgabe: array('erreichbar' => 0|1, 'wiedergabe' => 'play|pause|stop|-',
 *                  'titel' => string, 'meldung' => string)
 * "-" heisst NICHT FESTSTELLBAR und ist von "stop" verschieden: stop ist eine
 * Aussage ueber Kodi, "-" eine ueber uns.
 */
if (!function_exists('ko_kodi_zustand')) {
    function ko_kodi_zustand($zeit = 4)
    {
        $leer = array('erreichbar' => 0, 'wiedergabe' => '-', 'titel' => '', 'meldung' => '');
        list($ok, $r) = ko_rpc('JSONRPC.Ping', null, $zeit);
        if (!$ok) { $leer['meldung'] = (string) $r; return $leer; }
        $z = $leer;
        $z['erreichbar'] = 1;

        list($ok, $spieler) = ko_rpc('Player.GetActivePlayers', null, $zeit);
        if (!$ok) { $z['meldung'] = (string) $spieler; return $z; }
        if (!is_array($spieler) || !$spieler) {
            $z['wiedergabe'] = 'stop';
            return $z;
        }
        $id = isset($spieler[0]['playerid']) ? (int) $spieler[0]['playerid'] : -1;
        if ($id < 0) { $z['meldung'] = 'Spieler ohne playerid'; return $z; }

        list($ok, $eig) = ko_rpc('Player.GetProperties',
            array('playerid' => $id, 'properties' => array('speed')), $zeit);
        if ($ok && is_array($eig) && isset($eig['speed'])) {
            $z['wiedergabe'] = ((float) $eig['speed'] == 0.0) ? 'pause' : 'play';
        } else {
            // Ein Spieler ist da, sein Tempo nicht ablesbar. Das ist NICHT
            // "play" - es ist unbekannt, und das gehoert so gesagt.
            $z['meldung'] = $ok ? 'speed fehlt in der Antwort' : (string) $eig;
        }

        list($ok, $item) = ko_rpc('Player.GetItem',
            array('playerid' => $id, 'properties' => array('title', 'showtitle')), $zeit);
        if ($ok && isset($item['item']) && is_array($item['item'])) {
            $i = $item['item'];
            $t = '';
            if (isset($i['title']) && trim((string) $i['title']) !== '') { $t = (string) $i['title']; }
            elseif (isset($i['label']) && trim((string) $i['label']) !== '') { $t = (string) $i['label']; }
            if (isset($i['showtitle']) && trim((string) $i['showtitle']) !== '' && $t !== '') {
                $t = $i['showtitle'] . ' - ' . $t;
            }
            $z['titel'] = $t;
        }
        return $z;
    }
}

/* ================================================================
   Die Einstellungen des Kodi-Addons
   ================================================================

   GELESEN WIRD UEBER DEN HELFER, NICHT UEBER DAS DATEISYSTEM.
   Das ist keine Bequemlichkeit, sondern der einzige Weg, der traegt:
   /home/kodi hat seit 1.1.0 chmod 750 und gehoert kodi:kodi, der Webserver
   laeuft als loxberry. Ein file_get_contents() auf diesen Pfad scheitert auf
   JEDEM echten LoxBerry - und zwar stumm, weil die Funktion dann null
   zurueckgibt und die Oberflaeche brav "nicht feststellbar" meldet. Beim
   Gegenlesen dieser Fassung stand hier genau das: ein Lesepfad, der im
   Pruefstand aufging (dort gehoert alles demselben Benutzer) und am Geraet
   nie. Dieselbe Klasse wie die getrennten Baeume - richtig aussehend,
   wirkungslos.

   Damit gibt es auch nur EINE Stelle, die die Datei findet und ihre beiden
   Schreibweisen kennt: addon_datei() und addon_lesen() in elevatedhelper.pl.
   Eine zweite Fundlogik hier waere eine zweite Wahrheit.

   NICHT AM GERAET GEMESSEN bleibt der Ablageort selbst:
   <Heimat>/.kodi/userdata/addon_data/<addon-id>/settings.xml ist Kodis
   dokumentierte Ablage, im Hause aber an keinem laufenden Kodi nachgesehen
   worden. Findet der Helfer die Datei nicht, MELDET das Plugin es und rechnet
   nicht mit Vorgaben weiter. */

if (!defined('KO_ADDON_ID')) { define('KO_ADDON_ID', 'service.callback.handler'); }

/**
 * Die Einstellungen des Addons als Feld. null, wenn sie nicht zu lesen sind.
 *
 * $neu = true umgeht den Zwischenspeicher. Das braucht der Schreibvorgang: er
 * misst danach seine eigene WIRKUNG nach, und ein Wert aus dem Speicher waere
 * der von VOR dem Schreiben - die Probe saehe dann immer gleich aus, egal ob
 * etwas ankam.
 */
if (!function_exists('ko_addon_lesen')) {
    function ko_addon_lesen($neu = false)
    {
        static $w = null;
        static $gelesen = false;
        if ($gelesen && !$neu) { return $w; }
        $gelesen = true;
        $w = null;

        $j = ko_helper_json('action=addonread');
        if (!is_array($j) || !isset($j['status']) || $j['status'] !== 'OK'
            || !isset($j['werte']) || !is_array($j['werte'])) {
            return $w;
        }
        $w = array();
        foreach ($j['werte'] as $k => $v) { $w[(string) $k] = (string) $v; }
        return $w;
    }
}


/**
 * Einen Addon-Wert pruefen. Rueckgabe: der Wert, oder null bei Verstoss.
 *
 * Der Helfer prueft dieselben Felder noch einmal - er ist die Rechtegrenze und
 * muss fuer sich stehen. Hier wird geprueft, damit der Anwender eine
 * BEANSTANDUNG sieht statt eines stummen Addons: ein Port 99999 laesst Python
 * im Addon einen OverflowError werfen, den es selbst faengt und nur
 * protokolliert - von aussen sieht das aus, als melde Kodi einfach nichts mehr.
 */
if (!function_exists('ko_addon_wert_pruefen')) {
    function ko_addon_wert_pruefen($feld, $wert)
    {
        $w = trim((string) $wert);

        /* LEER IST EIN GUELTIGER ZUSTAND, und zwar der Auslieferungszustand.
         * Kodi legt ein nie angefasstes Feld als
         * <setting id="udp_port" default="true"></setting> ab; das Addon
         * faellt dann auf seine eigene Vorgabe zurueck (read_settings).
         *
         * Bis 1.2.2 wies diese Stelle den leeren Wert ab - waehrend
         * ko_sicherung_text() ihn ausdruecklich als "-" schreibt. Folge: das
         * Plugin lehnte seine EIGENE Sicherung ab, auf jedem Geraet, auf dem
         * die Addon-Einstellungen noch nie angefasst wurden. Gemeldet aus dem
         * Betrieb am 28.08.2026 (addon_udp_port und addon_volume_on_start).
         *
         * Die Zeile steht VOR dem switch, weil sie fuer alle Felder gilt -
         * eine Ausnahme je Feld waere sieben Stellen, die auseinanderlaufen
         * koennen. Dieselbe Regel steht im Helfer (%addon_muster). */
        if ($w === '') { return ''; }

        switch ($feld) {
            case 'udp_address':
            case 'mqtt_address':
                return preg_match('#^[A-Za-z0-9._-]{0,64}$#', $w) ? $w : null;
            case 'udp_port':
            case 'mqtt_udpport':
                if (!preg_match('/^[0-9]{1,5}$/', $w)) { return null; }
                $n = (int) $w;
                return ($n >= 1 && $n <= 65535) ? (string) $n : null;
            case 'volume_on_start':
                if (!preg_match('/^[0-9]{1,3}$/', $w)) { return null; }
                $n = (int) $w;
                return ($n >= 0 && $n <= 100) ? (string) $n : null;
            case 'mqtt_enable':
                return in_array($w, array('true', 'false'), true) ? $w : null;
            case 'mqtt_topic':
                return preg_match('#^[A-Za-z0-9._/-]{1,64}$#', $w) ? $w : null;
        }
        return null;
    }
}

/** Welche Addon-Schluessel es gibt - aus dem mitgelieferten settings.xml des
 *  Addons abgeleitet, nicht abgeschrieben. */
if (!function_exists('ko_addon_schluessel')) {
    function ko_addon_schluessel()
    {
        return array('udp_address', 'udp_port', 'volume_on_start',
                     'mqtt_enable', 'mqtt_address', 'mqtt_udpport', 'mqtt_topic');
    }
}

/**
 * Was das Plugin im Addon stehen haben WILL.
 *
 * Vier der sieben Felder gehoeren dem Plugin: die Adresse des LoxBerry, der
 * UDP-Eingang des Gateways, der Schalter und das Thema. Die drei uebrigen
 * (udp_address, udp_port, volume_on_start) gehoeren dem Anwender und werden
 * NICHT ueberschrieben - sie stehen hier nur, damit die Sicherung sie kennt.
 */
if (!function_exists('ko_addon_soll')) {
    function ko_addon_soll()
    {
        $cfg = ko_config();
        $ip = ko_lokale_ip();
        /* Der Wertebereich des Ports steht HIER und genau einmal.
         *
         * Der Helfer prueft nur die ZEICHEN (bis fuenf Ziffern) - er ist die
         * Rechtegrenze und muss fuer sich stehen, aber eine Portnummer ist
         * keine Frage der Zeichen. Waere die Grenze an beiden Stellen
         * hinterlegt, liefen sie auseinander. Ein unmoeglicher Wert wird
         * deshalb hier zu einem LEERSTRING, und die Oberflaeche schreibt dann
         * gar nichts und sagt warum - statt eine Zahl ins Addon zu tragen,
         * an die sich nie etwas meldet. */
        $port = ko_mqtt_port();
        return array(
            'mqtt_enable'  => 'true',
            'mqtt_address' => $ip,
            'mqtt_udpport' => ($port >= 1 && $port <= 65535) ? (string) $port : '',
            'mqtt_topic'   => $cfg['mqtt_topic'],
        );
    }
}

/** Die eigene Adresse im Netz - fuer das Addon, das von Kodi aus sendet.
 *  127.0.0.1 taugt dafuer nur, solange Kodi auf demselben Rechner laeuft; ist
 *  nichts zu ermitteln, wird ein LEERSTRING geliefert und nicht geraten. */
if (!function_exists('ko_lokale_ip')) {
    function ko_lokale_ip()
    {
        $home = ko_paths()['home'];
        if ($home !== '') {
            $g = ko_json_lesen($home . '/config/system/general.json');
            if (isset($g['Network']['Ipaddress'])
                && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', (string) $g['Network']['Ipaddress'])) {
                return (string) $g['Network']['Ipaddress'];
            }
        }
        if (isset($_SERVER['SERVER_ADDR'])
            && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', (string) $_SERVER['SERVER_ADDR'])) {
            return (string) $_SERVER['SERVER_ADDR'];
        }
        return '';
    }
}

/* ================================================================
   Sichern und Zurueckspielen
   ================================================================

   Zweck ist der UMZUG auf einen zweiten LoxBerry, nicht die Sicherung gegen
   Verlust - dafuer gibt es die Zweitschrift neben dem Konfigordner.

   WAS BEI DIESEM PLUGIN HINEINGEHOERT, IST MEHR ALS DIE KONFIGURATION.
   kodi.json fuehrt acht Schluessel (ko_vorgaben). Das muehsam Eingerichtete
   liegt aber an vier Orten, und drei davon kennt kodi.json nicht:

       Autostart          systemctl is-enabled kodi_ng
       Codec-Lizenzen     /boot/firmware/config.txt
       Addon-Felder       Kodis eigene Addon-Einstellungen

   Die sieben Addon-Felder werden in Kodi selbst gesetzt, ueber die
   Fernbedienung - vier davon gehoeren dem Plugin, drei dem Anwender;
   gesichert werden alle sieben. Sie sind das, was beim Umzug wirklich weh
   tut. Wer nur kodi.json sichert, hat die Funktion halbiert.

   DIE LIZENZEN SIND GERAETEGEBUNDEN. Sie gelten fuer GENAU EINE Seriennummer.
   Auf einem zweiten LoxBerry sind sie wertlos, beim Neuaufsetzen DESSELBEN Pi
   dagegen das Wertvollste in der Datei. Deshalb stehen sie mit der
   Seriennummer, fuer die sie gekauft wurden, in der Sicherung - und
   zurueckgespielt werden sie nur, wenn der Anwender es ausdruecklich anhakt
   UND die Seriennummer uebereinstimmt. Weicht sie ab, ist das eine
   Beanstandung, kein stilles Uebergehen.

   DAS FORMULARMERKMAL GEHOERT NICHT HINEIN. Es lebt eine Anlage lang und
   schuetzt gegen fremde Absender; in einer Datei hat es nichts zu suchen. Wer
   beide verwechselt, macht aus der Umzugshilfe ein Leck. */

/** Die Sicherung als Text - das Format ist "schluessel wert", eine Zeile je
 *  Wert, Kommentare mit #. */
if (!function_exists('ko_sicherung_text')) {
    function ko_sicherung_text()
    {
        $cfg = ko_config();
        $st  = ko_status();
        $addon = ko_addon_lesen();

        $t  = "# Kodi NG - Sicherung der Einstellungen\n";
        $t .= '# ' . date('d.m.Y H:i') . ' - ACHTUNG: enthaelt Zugangsdaten.'
            . " Wie ein Passwort behandeln.\n";

        foreach (ko_vorgaben() as $k => $vorgabe) {
            $w = isset($cfg[$k]) ? (string) $cfg[$k] : (string) $vorgabe;
            // Leere Werte als - schreiben: eine Zeile mit einem Feld waere
            // beim Zurueckspielen eine Beanstandung, und ein Schluessel, der
            // fehlt, kaeme aus der Vorgabe zurueck - genau das ist falsch,
            // wenn jemand das Feld bewusst geleert hat.
            $t .= $k . ' ' . ($w === '' ? '-' : ko_sicherung_wert($w)) . "\n";
        }

        $auto = isset($st['kodiautostart']) ? (int) $st['kodiautostart'] : 0;
        $t .= 'autostart ' . $auto . "\n";

        foreach (ko_addon_schluessel() as $k) {
            if ($addon === null) { continue; }
            $w = isset($addon[$k]) ? (string) $addon[$k] : '';
            $t .= 'addon_' . $k . ' ' . ($w === '' ? '-' : ko_sicherung_wert($w)) . "\n";
        }
        if ($addon === null) {
            $t .= "# Die Addon-Einstellungen waren nicht lesbar und fehlen deshalb\n"
                . "# in dieser Sicherung. Ein leerer Platz ist keine leere Angabe.\n";
        }

        /* Die Lizenzen: mit der Seriennummer, fuer die sie gelten. */
        $serie = isset($st['piserial']) ? trim((string) $st['piserial']) : '';
        $mp = isset($st['mpeg2lic']) ? trim((string) $st['mpeg2lic']) : '';
        $vc = isset($st['vc1lic']) ? trim((string) $st['vc1lic']) : '';
        if ($serie !== '' && $serie !== 'Not found' && ($mp !== '' || $vc !== '')) {
            $t .= "#\n# Codec-Lizenzen. Sie gelten fuer GENAU DIESE Seriennummer und sind\n"
                . "# auf einem anderen Pi wertlos. Zurueckgespielt werden sie nur, wenn\n"
                . "# der Haken gesetzt ist UND die Seriennummer uebereinstimmt.\n";
            $t .= 'lizenz_serie ' . ko_sicherung_wert($serie) . "\n";
            if ($mp !== '') { $t .= 'lizenz_mpeg2 ' . ko_sicherung_wert($mp) . "\n"; }
            if ($vc !== '') { $t .= 'lizenz_vc1 ' . ko_sicherung_wert($vc) . "\n"; }
        }
        return $t;
    }
}

/** Ein Wert in der Sicherungsdatei darf keinen Leerraum enthalten - sonst
 *  zerfaellt die Zeile beim Einlesen in mehr als zwei Felder. Betroffen sind
 *  in der Praxis nur Passwoerter. Ein solcher Wert wird deshalb hexadezimal
 *  hinterlegt und beim Einlesen zurueckverwandelt; ein GEKUERZTES Passwort
 *  waere schlimmer als ein unleserliches. */
if (!function_exists('ko_sicherung_wert')) {
    function ko_sicherung_wert($w)
    {
        $w = (string) $w;
        /* Der Bindestrich steht in der Datei fuer den LEEREN Wert. Ein Wert,
         * der wirklich aus einem einzelnen Bindestrich besteht, muss deshalb
         * hexadezimal hinterlegt werden - sonst kaeme er leer zurueck.
         * Gemessen an der Rundreise: mit kodi_pass = "-" meldete der Reiter
         * Test eine Abweichung, und beim echten Zurueckspielen waere das
         * Passwort still geloescht worden. */
        if ($w !== '-' && $w !== ''
            && preg_match('/^[\x21-\x7E]+$/', $w) && strpos($w, '=hex:') !== 0) {
            return $w;
        }
        if ($w === '') { return '-'; }
        return '=hex:' . bin2hex($w);
    }
}

if (!function_exists('ko_sicherung_rohwert')) {
    function ko_sicherung_rohwert($w)
    {
        $w = (string) $w;
        if (strpos($w, '=hex:') === 0) {
            $h = substr($w, 5);
            if (preg_match('/^([0-9a-fA-F]{2})*$/', $h)) { return hex2bin($h); }
            return null;   // beschaedigt - das ist eine Beanstandung
        }
        return $w === '-' ? '' : $w;
    }
}

/**
 * Eine hochgeladene Sicherung pruefen.
 *
 * Rueckgabe: array($ergebnis, $beanstandungen)
 * $ergebnis ist null, sobald EINE Beanstandung vorliegt - eine halb gueltige
 * Datei ueberschreibt NICHTS. Und es werden ALLE Beanstandungen gesammelt:
 * wer nur die erste zeigt, schickt den Anwender in eine Schleife aus je einem
 * Fund pro Anlauf.
 *
 * $ergebnis: array('cfg' => …, 'autostart' => 0|1|null,
 *                  'addon' => array(), 'lizenz' => array())
 */
if (!function_exists('ko_sicherung_einlesen')) {
    function ko_sicherung_einlesen($roh)
    {
        $mangel = array();
        $cfg = array();
        $addon = array();
        $lizenz = array();
        $autostart = null;
        $gefunden = 0;

        $vorgaben = ko_vorgaben();
        $addon_bekannt = ko_addon_schluessel();
        $lizenz_bekannt = array('lizenz_serie', 'lizenz_mpeg2', 'lizenz_vc1');

        foreach (preg_split('/\R/', (string) $roh) as $z) {
            $t = trim($z);
            if ($t === '' || $t[0] === '#') { continue; }
            $f = preg_split('/\s+/', $t);
            if (count($f) !== 2) {
                $mangel[] = sprintf(ko_t('SICH.M_ZEILE'), ko_e(substr($t, 0, 80)));
                continue;
            }
            $k = strtolower($f[0]);
            $w = ko_sicherung_rohwert($f[1]);
            if ($w === null) {
                $mangel[] = sprintf(ko_t('SICH.M_WERT'), ko_e($k));
                continue;
            }

            if (array_key_exists($k, $vorgaben)) {
                $gut = ko_wert_pruefen($k, $w);
                if ($gut === null) {
                    $mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($k), ko_e(substr($w, 0, 40)));
                    continue;
                }
                $cfg[$k] = $gut;
                $gefunden++;
            } elseif ($k === 'autostart') {
                if (!in_array($w, array('0', '1'), true)) {
                    $mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($k), ko_e(substr($w, 0, 40)));
                    continue;
                }
                $autostart = (int) $w;
                $gefunden++;
            } elseif (strpos($k, 'addon_') === 0
                      && in_array(substr($k, 6), $addon_bekannt, true)) {
                $feld = substr($k, 6);
                $gut = ko_addon_wert_pruefen($feld, $w);
                if ($gut === null) {
                    $mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($k), ko_e(substr($w, 0, 40)));
                    continue;
                }
                $addon[$feld] = $gut;
                $gefunden++;
            } elseif (in_array($k, $lizenz_bekannt, true)) {
                // Lizenzschluessel und Seriennummer: enge Positivliste. Diese
                // Werte gehen ueber den Helfer in die BOOTKONFIGURATION.
                if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $w)) {
                    $mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($k), ko_e(substr($w, 0, 40)));
                    continue;
                }
                $lizenz[$k] = $w;
                $gefunden++;
            } else {
                // Unbekannte Schluessel sind eine Beanstandung, kein stiller
                // Verlust - sonst schluckt dieses Plugin die Sicherung eines
                // anderen und meldet Erfolg.
                $mangel[] = sprintf(ko_t('SICH.M_SCHLUESSEL'), ko_e(substr($k, 0, 60)));
            }
        }

        if ($gefunden === 0) { $mangel[] = ko_t('SICH.M_LEER'); }
        if ($mangel) { return array(null, $mangel); }

        return array(array('cfg' => $cfg, 'autostart' => $autostart,
                           'addon' => $addon, 'lizenz' => $lizenz), array());
    }
}

/* ================================================================
   Formularmerkmal gegen fremde Absender
   ================================================================

   htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass der
   Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf einer
   fremden Seite steht. Die Anmeldung geht dabei automatisch mit; SameSite
   greift nicht.

   Bis 1.1.9 trug keines der elf Formulare ein Merkmal. Erreichbar waren damit
   unter anderem: den Kodi-Dienst anhalten, die Konfiguration ueberschreiben,
   das Protokoll leeren - und ueber die Lizenzfelder ein Schreibvorgang in die
   BOOTKONFIGURATION ueber sudo. Der Helfer prueft seine Eingaben zwar seit
   1.1.0, das begrenzt den Schaden; der Aufruf selbst kam durch.

   Das Merkmal liegt in einer EIGENEN Datei neben der Konfiguration, nicht
   darin: sonst stuende es in der Sicherungsdatei, und das waere genau die
   Verwechslung, vor der die Hausregel warnt. */

if (!function_exists('ko_formkey')) {
    function ko_formkey()
    {
        static $k = null;
        if ($k !== null) { return $k; }
        $datei = ko_paths()['formkey'];
        if (is_file($datei)) {
            $g = trim(ko_lesen($datei));
            if (preg_match('/^[0-9a-f]{32}$/', $g)) { $k = $g; return $k; }
        }
        $k = bin2hex(function_exists('random_bytes')
            ? random_bytes(16)
            : pack('H*', md5(uniqid('ko', true) . getmypid())));
        if (!is_dir(dirname($datei))) { @mkdir(dirname($datei), 0775, true); }
        // Rechte VOR dem Inhalt, sonst steht das Merkmal kurz offen da.
        $tmp = $datei . '.tmp.' . getmypid();
        $abgelegt = false;
        $fh = @fopen($tmp, 'c');
        if ($fh !== false) {
            @chmod($tmp, 0600);
            ftruncate($fh, 0);
            fwrite($fh, $k);
            fflush($fh);
            fclose($fh);
            if (@rename($tmp, $datei)) { $abgelegt = true; }
            else { @unlink($tmp); }
        }
        /* Laesst sich das Merkmal nicht ablegen, wuerfelt JEDER Aufruf ein
         * neues - und dann scheitert hash_equals immer, jedes Formular endet
         * in "kam nicht von dieser Seite", und die Oberflaeche ist tot, ohne
         * dass irgendwo steht warum. Das gehoert ins Protokoll. */
        if (!$abgelegt) {
            ko_log('FEHLER: das Formularmerkmal liess sich nicht nach ' . $datei
                 . ' schreiben. Solange das so bleibt, weist die Oberflaeche JEDES '
                 . 'Formular ab. Rechte des Ordners pruefen.');
        }
        return $k;
    }
}

/** Das versteckte Feld. Steht in JEDEM Formular, gleich ob es etwas aendert
 *  oder nur einen Download ausloest. */
if (!function_exists('ko_fmt')) {
    function ko_fmt()
    {
        return '<input data-role="none" type="hidden" name="fmt" value="'
             . ko_e(ko_formkey()) . '">';
    }
}

/* ================================================================
   Der Cron-Eintrag
   ================================================================ */

/**
 * Liegt der Cron-Eintrag da, wo LoxBerry ihn ausfuehrt?
 *
 * `cron/cron.01min` ist eine DATEI, kein Verzeichnis. Der Installer legt das
 * ausgelieferte Ding unter system/cron/cron.01min/<plugin> ab; ist es ein
 * Verzeichnis, entsteht dort ein Unterverzeichnis - und LoxBerry fuehrt in
 * diesen Ordnern nur Dateien aus. Der Cron liefe nie, ohne ein Wort zu sagen.
 *
 * Rueckgabe: array(zustand, hinweis)
 *   1  Datei da
 *   0  Verzeichnis statt Datei, oder nichts da
 *  -1  nicht feststellbar (kein LoxBerry unter den Fuessen)
 */
if (!function_exists('ko_cron_lage')) {
    function ko_cron_lage()
    {
        $p = ko_paths();
        if ($p['cron'] === '' || $p['home'] === '') {
            return array(-1, '', array());
        }
        // Reste frueherer Fassungen an den anderen Cron-Orten: sonst findet
        // die nie wieder jemand.
        $reste = array();
        foreach (glob($p['home'] . '/system/cron/cron.*') ?: array() as $ordner) {
            $k = $ordner . '/' . $p['plugin'];
            if ($k !== $p['cron'] && file_exists($k)) { $reste[] = basename($ordner); }
        }
        if (is_file($p['cron'])) { return array(1, $p['cron'], $reste); }
        if (is_dir($p['cron']))  { return array(0, $p['cron'], $reste); }
        return array(0, '', $reste);
    }
}

/* ================================================================
   Sprache
   ================================================================ */

if (!function_exists('ko_e')) {
    function ko_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('ko_sprache')) {
    function ko_sprache()
    {
        $s = 'de';
        if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
            $s = LBSystem::lblanguage();
        } elseif (getenv('LBLANG')) {
            $s = getenv('LBLANG');
        }
        $s = strtolower(substr((string) $s, 0, 2));
        // Englisch ist Rueckfallebene, nicht Deutsch: wer eine fremde Sprache
        // eingestellt hat, versteht eher Englisch.
        return in_array($s, array('de', 'en'), true) ? $s : 'en';
    }
}

/**
 * Der Ordner mit den Sprachdateien.
 *
 * Gesucht wird nach dem Ordner, der wirklich eine language_de.ini enthaelt -
 * nicht nach einem anderen Ordner, aus dem man auf ihn schliessen koennte. Bis
 * 1.1.2 stand hier ein Schluss vom KONFIGURATIONSORDNER auf den
 * VORLAGENORDNER, dazu ein Rueckfall auf den Ordnernamen VOR der Umbenennung.
 * Ergebnis war eine Oberflaeche, auf der ALLG.TITEL und REITER.LOXONE standen
 * statt der Texte - und es fiel nicht auf, weil @parse_ini_file die Warnung
 * schluckt und ko_t() den Schluessel zurueckgibt.
 */
if (!function_exists('ko_langdir')) {
    function ko_langdir()
    {
        static $gefunden = null;
        if ($gefunden !== null) { return $gefunden; }
        $p = ko_paths();
        $kandidaten = array();
        if ($p['lang'] !== '') { $kandidaten[] = $p['lang']; }
        if ($p['home'] !== '') {
            $kandidaten[] = $p['home'] . '/templates/plugins/' . KO_ORDNER . '/lang';
        }
        // Entpacktes Archiv: die Sprachdateien liegen neben bin/ bzw.
        // neben webfrontend/.
        $kandidaten[] = dirname(__DIR__) . '/templates/lang';
        $kandidaten[] = dirname(dirname(__DIR__)) . '/templates/lang';
        $kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/templates/lang';
        foreach ($kandidaten as $k) {
            if (is_file($k . '/language_de.ini') || is_file($k . '/language_en.ini')) {
                $gefunden = $k;
                return $gefunden;
            }
        }
        $gefunden = '';
        return $gefunden;
    }
}

/** Text zu einem Schluessel der Form ABSCHNITT.NAME. */
if (!function_exists('ko_t')) {
    function ko_t($schluessel)
    {
        static $texte = null;
        if ($texte === null) {
            $pfad = ko_langdir();
            $texte = $pfad === ''
                ? false
                : @parse_ini_file($pfad . '/language_' . ko_sprache() . '.ini', true, INI_SCANNER_RAW);
            if (!is_array($texte)) { $texte = array(); }
            $rueck = $pfad === ''
                ? false
                : @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
            if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
            // INI_SCANNER_RAW liefert die Anfuehrungszeichen mit zurueck, in
            // die die Werte laut Hausregeln stehen muessen.
            foreach ($texte as $ab => $paare) {
                if (!is_array($paare)) { continue; }
                foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
            }
        }
        list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
        return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
    }
}

/** Wurde ueberhaupt eine Sprachdatei gefunden? Die Oberflaeche zeigt daraus
 *  einen Hinweis - sonst sieht eine unbeschriftete Seite nach einem
 *  Bedienfehler aus statt nach einer fehlenden Datei. */
/**
 * Fehlt die Sprachdatei, die WIRKLICH gelesen wird?
 *
 * ko_langdir() ist schon zufrieden, wenn EINE der beiden Dateien da ist. Bei
 * eingestelltem Englisch und fehlender language_en.ini war der Ordner damit
 * "gefunden", ko_t() fand nichts, und es standen 92 rohe Schluessel auf dem
 * Bildschirm - ohne die Warnung, die genau dafuer da ist. Gemessen.
 *
 * Geprueft wird deshalb die Datei der eingestellten Sprache UND die
 * englische Rueckfallebene: fehlen beide, kann ko_t() nichts uebersetzen.
 */
if (!function_exists('ko_sprache_fehlt')) {
    function ko_sprache_fehlt()
    {
        $verz = ko_langdir();
        if ($verz === '') { return true; }
        return !is_file($verz . '/language_' . ko_sprache() . '.ini')
            && !is_file($verz . '/language_en.ini');
    }
}

/* ================================================================
   Loxone-Vorlagen
   ================================================================ */

/**
 * Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff:
 * VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus
 * 604800 s, nur damit Loxone die richtig benannten Eingaenge anlegt - die
 * Werte kommen vom MQTT-Gateway. Format wie Original-Export aus Loxone
 * Config 17.1.
 *
 * Enthalten sind AUSSCHLIESSLICH die Zahlenwerte aus ko_themen(). Textthemen
 * bekommen keinen virtuellen Eingang, auch nicht im XML-Weg.
 */
if (!function_exists('ko_vorlage')) {
    function ko_vorlage()
    {
        $cfg = ko_config();
        $topic = $cfg['mqtt_topic'] !== '' ? $cfg['mqtt_topic'] : 'kodi';
        $crlf = "\r\n";
        $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
        $o .= '<VirtualInHttp HintText="" Title="Kodi Zustand" Comment="Erzeugt vom LoxBerry-Plugin Kodi NG ('
            . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo '
            . htmlspecialchars($topic, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . '/# noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
        $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
        foreach (ko_themen() as $t) {
            if (!$t['zahl'] || $t['quelle'] !== 'plugin') { continue; }
            $o .= "\t" . '<VirtualInHttpCmd Title="'
                . htmlspecialchars($topic . '_' . $t['name'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '" ';
            $o .= 'Comment="' . htmlspecialchars(ko_t('THEMA.' . $t['schl']), ENT_QUOTES | ENT_XML1, 'UTF-8') . '" Check=" " ';
            $o .= 'Signed="false" Analog="true" SourceValLow="0" DestValLow="0" '
                . 'SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $t['min']
                . '" MaxVal="' . $t['max'] . '" Unit="&lt;v.0&gt;" HintText=""/>' . $crlf;
        }
        $o .= '</VirtualInHttp>' . $crlf;
        return array('VI_kodi_ng.xml', $o);
    }
}

/**
 * Die Steuerbefehle als VirtualOut.
 *
 * Sie gehen an Kodis TCP-Schnittstelle (Port 9090), nicht ueber den LoxBerry -
 * der Ordnername des Plugins kommt in keiner Loxone-Adresse vor.
 *
 * Bis 1.1.9 lag zusaetzlich eine fertige data/VO_Kodi_V1.xml im Paket, auf die
 * die Oberflaeche ausdruecklich verwies. Sie war gegenueber diesem Erzeuger
 * veraltet - ohne Info-Zeile, ohne HintText, mit BOM und mit LF statt CRLF -,
 * und sie trug den Platzhalter "loxberry" statt der eingetragenen Adresse.
 *
 * Seit 1.2.0 ist sie ERSATZLOS entfallen. Sie neu zu erzeugen und mitzuliefern
 * waere wieder ein zweiter Stand desselben Inhalts gewesen: einer, der beim
 * naechsten Eingriff in diese Funktion still zurueckbleibt, und einer, den
 * niemand braucht, weil der Knopf daneben dieselbe Datei mit der RICHTIGEN
 * Adresse erzeugt.
 */
if (!function_exists('ko_vo_befehle')) {
    function ko_vo_befehle()
    {
        // $c[1] traegt die Maskierung des Originals (&quot;) bereits in sich.
        return array(
            array('Input.Back', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Back&quot;, &quot;id&quot;: 1}'),
            array('Input.ContextMenu', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.ContextMenu&quot;, &quot;id&quot;: 1}'),
            array('Input.Down', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Down&quot;, &quot;id&quot;: 1}'),
            array('Input.Home', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Home&quot;, &quot;id&quot;: 1}'),
            array('Input.Info', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Info&quot;, &quot;id&quot;: 1}'),
            array('Input.Left', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Left&quot;, &quot;id&quot;: 1}'),
            array('Input.Right', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Right&quot;, &quot;id&quot;: 1}'),
            array('Input.Select', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Select&quot;, &quot;id&quot;: 1}'),
            array('Input.ShowOSD', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.ShowOSD&quot;, &quot;id&quot;: 1}'),
            array('Input.Up', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Up&quot;, &quot;id&quot;: 1}'),
            array('Player.Seek Forward', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.Seek&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;value&quot;:&quot;smallforward&quot;},&quot;id&quot;:1}'),
            array('Player.Seek Backwards', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.Seek&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;value&quot;:&quot;smallbackward&quot;},&quot;id&quot;:1}'),
            array('Player.Stop', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.Stop&quot;,&quot;params&quot;:{&quot;playerid&quot;:1},&quot;id&quot;:1}'),
            array('Player.PlayPause Play', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.PlayPause&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;play&quot;:true},&quot;id&quot;:1}'),
            array('Player.PlayPause Pause', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.PlayPause&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;play&quot;:false},&quot;id&quot;:1}'),
            array('Player.PlayPause Toggle', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.PlayPause&quot;,&quot;params&quot;:{&quot;playerid&quot;:1},&quot;id&quot;:1}'),
            array('Player.GoTo Next', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.GoTo&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;to&quot;:&quot;next&quot;},&quot;id&quot;:1}'),
            array('Player.GoTo Previous', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.GoTo&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;to&quot;:&quot;previous&quot;},&quot;id&quot;:1}'),
            array('Input.executeaction PageDown', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Input.ExecuteAction&quot;,&quot;params&quot;:{&quot;action&quot;:&quot;pagedown&quot;},&quot;id&quot;:1}'),
            array('Input.executeaction PageUp', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Input.ExecuteAction&quot;,&quot;params&quot;:{&quot;action&quot;:&quot;pageup&quot;},&quot;id&quot;:1}'),
            array('Application.SetVolume VolumeUp', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetVolume&quot;,&quot;params&quot;:{&quot;volume&quot;:&quot;increment&quot;},&quot;id&quot;:1}'),
            array('Application.SetVolume VolumeDown', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetVolume&quot;,&quot;params&quot;:{&quot;volume&quot;:&quot;decrement&quot;},&quot;id&quot;:1}'),
            array('Application.Quit', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.Quit&quot;,&quot;id&quot;:1}'),
            array('Application.SetMute Toggle', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetMute&quot;,&quot;id&quot;:1}'),
            array('Application.SetMute Mute', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetMute&quot;,&quot;params&quot;:{&quot;mute&quot;:true},&quot;id&quot;:1}'),
            array('Application.SetMute Unmute', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetMute&quot;,&quot;params&quot;:{&quot;mute&quot;:false},&quot;id&quot;:1}'),
        );
    }
}

if (!function_exists('ko_vorlage_vo')) {
    function ko_vorlage_vo($host = null)
    {
        if ($host === null) {
            $cfg = ko_config();
            $host = ($cfg['kodi_host'] !== '' && $cfg['kodi_host'] !== '127.0.0.1')
                ? $cfg['kodi_host']
                : (isset($_SERVER['HTTP_HOST'])
                    ? preg_replace('/:.*$/', '', (string) $_SERVER['HTTP_HOST'])
                    : 'loxberry');
        }
        $crlf = "\r\n";
        $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
        $o .= '<VirtualOut HintText="" Title="Kodi steuern (LoxBerry-Plugin)" '
            . 'Comment="Erzeugt vom LoxBerry-Plugin Kodi NG (' . date('d.m.Y')
            . '). JSON-RPC an Kodis TCP-Schnittstelle." Address="tcp://'
            . htmlspecialchars($host, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . ':9090" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
        $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
        foreach (ko_vo_befehle() as $c) {
            $o .= "\t" . '<VirtualOutCmd Title="'
                . htmlspecialchars($c[0], ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
            $o .= 'CmdOn="' . $c[1] . '" ';
            $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
            $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
        }
        $o .= '</VirtualOut>' . $crlf;
        return array('VQ_kodi_ng_steuern.xml', $o);
    }
}
