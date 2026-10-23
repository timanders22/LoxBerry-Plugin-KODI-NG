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
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation (bin/plugins/<ordner>/ - drei Ebenen)
 * genauso wie eine an einem anderen Ort. Findet es nichts, gibt es einen
 * Leerstring zurueck, was der Aufrufer abfangen muss.
 *
 * general.json ist die entscheidende Bedingung (Regeln/06): ein LoxBerry hat
 * sie immer, ein Rest aus Pruefstaenden nie. Bis 1.2.9 genuegten
 * config/plugins und webfrontend. In WSL gemessen (Pruefung-KODI-NG-1.2.10,
 * Fall W1): in einem fremden Baum ohne general.json schrieb der Statussender
 * dort Sperrdatei, Zustand und Protokoll.
 */
if (!function_exists('ko_wurzel_ermitteln')) {
    function ko_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
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

/**
 * Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und danach nichts mehr.
 *
 * Ein gesetztes LBHOMEDIR gilt nur mit config/plugins UND data/plugins
 * darunter; general.json wird hier nicht verlangt, damit Attrappen ohne sie
 * weiter tragen (Bauart tb_lbhome(), Spotpreis-Tibber 0.9.19). Bis 1.2.9
 * wurde LBHOMEDIR ungeprueft genommen: zeigte es auf einen leeren Ordner,
 * galten dessen Pfade statt der Installation (Fall W4). Rueckgabe '' heisst
 * "keine Wurzel"; jeder Aufrufer muss das abfangen.
 */
if (!function_exists('ko_lbhome')) {
    function ko_lbhome()
    {
        $h = rtrim((string) getenv('LBHOMEDIR'), '/');
        if ($h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
            return $h;
        }
        return ko_wurzel_ermitteln();
    }
}

/** Alle Pfade an einer Stelle. */
if (!function_exists('ko_paths')) {
    function ko_paths()
    {
        static $p = null;
        if ($p !== null) { return $p; }

        $home = ko_lbhome();
        /* ARCHIVMODUS. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek
         * dort installiert liegt (<Wurzel>/bin/plugins/<ordner>, physisch
         * verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt
         * ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit
         * ihrer Attrappe, und so ruft die Deinstallation den Sender). Sonst
         * ist das ein ausgepacktes Archiv oder ein Pruefordner: alles bleibt
         * in dessen eigenem Ordner, der Statussender steigt aus, und der
         * Helfer wird nicht gerufen.
         *
         * Bis 1.2.9 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel
         * und den festen Namen kodi_ng - Konfiguration, Zustand,
         * Formularmerkmal, UDP-Eingang und den Helfer der Anlage (sudo
         * elevatedhelper.pl: Kodi starten und anhalten); mit $LBHOMEDIR
         * allein, wie es am Geraet in /etc/environment steht, ebenso. In WSL
         * gemessen (Pruefung-KODI-NG-1.2.10, Faelle A1, A2, A5 bis A8).
         * Bauart wie tb_paths() in Spotpreis-Tibber 0.9.19. */
        $gefunden = $home;
        if ($home !== '') {
            $soll = @realpath($home . '/bin/plugins/' . basename(__DIR__));
            $ist = @realpath(__DIR__);
            $installiert = ($soll !== false && $ist !== false && $soll === $ist);
            $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
            $ausdruecklich = $lbp !== ''
                && !in_array($lbp, array('.', 'bin', 'html', 'htmlauth', 'plugins'), true)
                && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
            if (!$installiert && !$ausdruecklich) { $home = ''; }
        }
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
        // 'home' ist hier IMMER leer - daran erkennen Sender und Helfer den
        // Archivmodus; die gefundene Wurzel steht nur fuer die Meldung da.
        $basis = dirname(__DIR__);
        $p = array(
            'home'      => '',
            'archiv'    => $gefunden,
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
            /* AB WERK AN - NUR FUER NEUINSTALLATIONEN (1.2.22, stehende
             * Entscheidung "MQTT ab Werk an, nur Neuinstallationen"; Entwurf
             * Gruppe 10, 2.7, E6). Die Vorgabe wirkt nur, wo kodi.json den
             * Schluessel noch nicht traegt - das ist die Neuinstallation, die
             * Datei entsteht beim ersten Speichern. Bestehende Anlagen tragen
             * ihn seit 1.2.7 (ko_cfg_vervollstaendigen) und behalten ihren
             * Wert; ein Update schaltet also nichts ungefragt ein. Ohne
             * kodi.json steigt cron.01min weiter vor dem Sender aus: ab Werk an
             * heisst "ab dem ersten Speichern". Ohne den Sender fehlt dem
             * Musterprojekt das Lebenszeichen. Bis 1.2.21 stand hier '0'. */
            'sender_ein'  => '1',
            'sender_takt' => '300',
            /* Der Aufruf von Kodi ueber JSON-RPC ist im Hause nicht an einem
             * Geraet erprobt worden - es lief keines. Deshalb ein eigener
             * Schalter, ebenfalls ab Werk aus. */
            'rpc_ein'     => '0',
            /* Befehle ueber MQTT (1.2.22, Entwurf Gruppe 10, 3.3, E1-E3): die
             * gemeinsame Befehlsbruecke nimmt <praefix>/befehl/... an; der
             * zweite Haken laesst zusaetzlich das eingreifende "beenden" zu.
             * Beide ab Werk aus - jeder im Heimnetz kann ueber den UDP-Eingang
             * des MQTT-Gateways Befehle senden. */
            'befehle_mqtt_ein'     => '0',
            'befehle_mqtt_steuern' => '0',
            /* Kodis Fernsteuerung aus dem Heimnetz (1.2.22, Entwurf 3.6, E4):
             * <esallinterfaces> in der advancedsettings.xml - JSON-RPC TCP 9090
             * und EventServer UDP 9777 fuer andere Rechner. Vorgabe 1 fuer
             * Update UND Neuinstallation (wie bisher; keine
             * Verhaltensaenderung). Aus: nur noch fuer den LoxBerry selbst,
             * nach einem Neustart von Kodi; die Bruecke wirkt weiter. */
            'fernsteuerung_netz_ein' => '1',
        );
    }
}

/** Ist das ein zulaessiger Wirt fuer eine Adresse, die dieses Plugin baut?
 *
 *  Drei Formen und nichts sonst: IPv4, ein Rechnername aus Buchstaben,
 *  Ziffern, Punkt und Bindestrich, oder IPv6 in eckigen Klammern. Kein
 *  Schraegstrich, kein Fragezeichen, kein @ und kein Doppelpunkt ausserhalb
 *  der Klammern - jedes davon verschiebt in http://<wirt>:<port>/ das Ziel. */
if (!function_exists('ko_wirt_gueltig')) {
    function ko_wirt_gueltig($w)
    {
        $w = (string) $w;
        if ($w === '' || strlen($w) > 253) { return false; }
        // \z statt $: $ laesst einen Zeilenumbruch am Ende durch
        // ("kodi.local" mit angehaengtem Zeilenumbruch galt als gueltig -
        // gegengelesen 17.09.2026).
        if (preg_match('/^\[[0-9A-Fa-f:.]{2,45}\]\z/', $w)) { return true; }
        // Unterstrich: kein gueltiger DNS-Name, aber in Heimnetzen als
        // Rechnername verbreitet und im URL-Wirt ohne Wirkung auf das Ziel.
        return (bool) preg_match('/^[A-Za-z0-9]([A-Za-z0-9._-]{0,252})\z/', $w);
    }
}

/** IPv6 ohne Klammern ("::1", "fd00::1") in die Klammerform bringen, alles
 *  andere unveraendert lassen. Bis 1.2.6 wurde "::1" angenommen und steht
 *  deshalb womoeglich noch in einer kodi.json; die Positivliste allein
 *  haette solche Anlagen bei jedem Speichern beanstandet. */
if (!function_exists('ko_wirt_form')) {
    function ko_wirt_form($w)
    {
        $w = trim((string) $w);
        if (substr_count($w, ':') >= 2 && preg_match('/^[0-9A-Fa-f:.]{2,45}\z/', $w)) {
            return '[' . $w . ']';
        }
        return $w;
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
        /* Zugangsdaten werden NICHT getrimmt (Befund U2, 29.09.2026): bis
         * 1.2.11 wurde aus " geheim " still "geheim", Kodi antwortete danach
         * mit 401, und die Oberflaeche hatte "gespeichert" gemeldet. Fuer
         * Adresse, Port, Thema und Takt darf das Formular Leerraum am Rand
         * abschneiden - es speichert und zeigt den gekuerzten Wert. */
        $w = in_array($schluessel, array('kodi_user', 'kodi_pass'), true)
            ? (string) $wert : trim((string) $wert);
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
                /* Ein Schraegstrich am Rand wird ABGEWIESEN, nicht still
                 * abgeschnitten (Befund U2): aus "/kodi/" wurde bis 1.2.11
                 * "kodi" - ein anderes Praefix, als eingegeben war. */
                if ($w !== '' && ($w[0] === '/' || substr($w, -1) === '/')) { return null; }
                return preg_match('#^[A-Za-z0-9._/-]{1,64}$#', $w) ? $w : null;
            case 'kodi_host':
                /* Seit 1.2.7 eine Positivliste - bis 1.2.6 wurden nur
                 * Steuerzeichen, Anfuehrungszeichen und Leerraum entfernt.
                 * Damit gingen / ? # @ : durch, und aus
                 * "nachbar.example.net/pfad?a=" wurde eine Anfrage SAMT
                 * Authorization-Kopf an einen fremden Wirt und Pfad. Ein
                 * unzulaessiger Wert wird abgewiesen und gemeldet, nicht
                 * zurechtgebogen. Dieselbe Beurteilung gilt beim Lesen
                 * (ko_rpc), denn eine von Hand bearbeitete kodi.json kommt am
                 * Formular vorbei. */
                $w = ko_wirt_form($w);
                return ko_wirt_gueltig($w) ? $w : null;
            case 'kodi_port':
                // ctype_digit() steckt in einer Erweiterung, preg_match nicht.
                if (!preg_match('/^[0-9]{1,5}$/', $w)) { return null; }
                $n = (int) $w;
                return ($n >= 1 && $n <= 65535) ? (string) $n : null;
            case 'kodi_user':
            case 'kodi_pass':
                // Zugangsdaten werden NICHT gefiltert und nicht getrimmt - ein
                // Passwort darf alles enthalten ausser Steuerzeichen. Die werden
                // seit dem Befund U2 ABGEWIESEN statt still entfernt: aus
                // "tab<Tabulator>pw" wurde bis 1.2.11 "tabpw".
                return preg_match('/[\x00-\x1F\x7F]/', $w) ? null : $w;
            case 'sender_ein':
            case 'rpc_ein':
            case 'befehle_mqtt_ein':
            case 'befehle_mqtt_steuern':
            case 'fernsteuerung_netz_ein':
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

/** Nimmt das ZURUECKSPIELEN diesen Wert an? Rueckgabe: der Wert, oder null.
 *
 *  Strenger als das Formular (Befund U2, 29.09.2026; Regeln/05, Ergaenzung
 *  24.09.2026: beim Zurueckspielen gilt das Abweisen): ein Wert mit Leerraum
 *  am Rand oder mit Steuerzeichen wird abgewiesen, nicht gekuerzt. Er kann nur
 *  hexadezimal in die Datei gekommen sein, also von Hand. Fuer Benutzer und
 *  Passwort gehoert Leerraum am Rand zum Wert und bleibt.
 *
 *  Dieselbe Pruefung entscheidet beim Sichern, welcher gespeicherte Wert nur
 *  als Hinweis in die Datei kommt (Befund U3, ko_cfg_altwerte()). */
if (!function_exists('ko_wert_zurueckspielbar')) {
    function ko_wert_zurueckspielbar($schluessel, $wert)
    {
        $w = (string) $wert;
        if (preg_match('/[\x00-\x1F\x7F]/', $w)) { return null; }
        if (!in_array($schluessel, array('kodi_user', 'kodi_pass'), true) && trim($w) !== $w) {
            return null;
        }
        return ko_wert_pruefen($schluessel, $w);
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
        /* ZAEHLEN, NICHT HOFFEN (Befund K1, 29.09.2026). Bei voller Karte oder
         * Groessengrenze liefert fwrite() die Zahl der geschriebenen Bytes,
         * nicht false - "!== false" hielt 2048 von 3199 Byte fuer gelungen
         * (in WSL gemessen). Jetzt muessen genau strlen Bytes, fflush und
         * fclose gelingen, und die Nebendatei wird zurueckgelesen, bevor sie
         * ihren Namen bekommt. */
        $ok = ftruncate($fh, 0) && fwrite($fh, $json) === strlen($json);
        $ok = fflush($fh) && $ok;
        $ok = fclose($fh) && $ok;
        if ($ok) {
            clearstatcache(true, $tmp);
            $ok = (@file_get_contents($tmp) === $json);
        }
        if (!$ok) { @unlink($tmp); return false; }
        if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
        return true;
    }
}

/** Eine vorhandene Datei unteilbar neben ihren Platz legen.
 *
 *  Ein blankes copy($quelle, $ziel) oeffnet $ziel mit O_TRUNC: die dort
 *  liegende Datei ist SOFORT leer und wird erst danach gefuellt. Bricht das
 *  Schreiben in diesem Fenster ab, gibt es weder die alte noch die neue.
 *
 *  Gemessen am 18.09.2026 in WSL/Ubuntu, PHP 8.3.6
 *  (Pruefung-KODI-NG-1.2.8/Pruefstaende/messe_kodi_d.sh, Fall M4.1; der
 *  Abbruch ist mit "ulimit -f 0" hergestellt): mit copy() war die schon
 *  vorhandene .kaputt hinterher 0 Byte gross, ueber diesen Weg blieb sie
 *  unveraendert stehen.
 *
 *  Die Nebendatei liegt im SELBEN Verzeichnis - nur dort ist rename() ein
 *  Umbenennen und kein Kopieren; die Prozessnummer im Namen, damit zwei
 *  gleichzeitige Schreiber einander nicht die Nebendatei zerlegen. Die Rechte
 *  gehoeren an das ANLEGEN: in der Konfiguration steht seit 1.2.0 das
 *  Kodi-Passwort im Klartext, und die Abschrift traegt es mit.
 */
if (!function_exists('ko_datei_beiseite')) {
    function ko_datei_beiseite($quelle, $ziel, $rechte = 0600)
    {
        if (!is_file($quelle)) { return false; }
        $inhalt = @file_get_contents($quelle);
        if ($inhalt === false) { return false; }
        $tmp = $ziel . '.tmp.' . getmypid();
        $fh = @fopen($tmp, 'c');
        if ($fh === false) { return false; }
        @chmod($tmp, $rechte);
        $ok = ftruncate($fh, 0) && fwrite($fh, $inhalt) === strlen($inhalt);
        fflush($fh);
        fclose($fh);
        if (!$ok) { @unlink($tmp); return false; }
        if (!@rename($tmp, $ziel)) { @unlink($tmp); return false; }
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

        /* LEER WIRD AM INHALT ENTSCHIEDEN, nicht an der Zeichenfolge.
         *
         * Bis 1.2.9 stand hier $roh !== '{}'. "{ }" oder "[]" galten damit als
         * gelesene Konfiguration ohne Schluessel, ko_cfg_vervollstaendigen()
         * schrieb Vorgaben darueber - UND ueber die Zweitschrift, die den
         * wirklichen Stand trug (in WSL gemessen, Pruefung-KODI-NG-1.2.10,
         * Faelle K1 und K2). Jetzt gilt ein lesbares, aber leeres Feld als
         * "leer", und die Zweitschrift springt ein - dieselbe Bedingung wie in
         * postinstall.sh ("lesbar und nicht leer"). */
        $d = ($roh !== '') ? json_decode($roh, true) : null;
        if ($roh !== '' && !(is_array($d) && count($d) === 0)) {
            if (is_array($d)) {
                $lage = 'ok';
                $cfg = $d;
            } else {
                /* Beschaedigt. Beiseitelegen, damit der naechste Blick sie
                 * noch hat, und die Zweitschrift versuchen. Ueberschrieben
                 * wird sie NICHT - dann waere der Beleg fort.
                 *
                 * Bis 1.2.7 stand hier @copy(). Lag schon eine .kaputt aus
                 * einem frueheren Schaden da, kappte copy() sie, bevor es
                 * schrieb - brach das Schreiben ab, war der aeltere Beleg
                 * weg und der neue nicht da (gemessen, siehe
                 * ko_datei_beiseite()). Und die Abschrift entstand mit den
                 * Rechten der umask, obwohl in ihr dasselbe Kodi-Passwort
                 * im Klartext steht wie in der Konfiguration. */
                ko_datei_beiseite($p['config'], $p['config'] . '.kaputt', 0600);
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
        /* Die Zweitschrift erst, wenn die Hauptdatei ZURUECKGELESEN den
         * geschriebenen Stand traegt (Befund K1). Bis 1.2.11 entstanden beide
         * Kopien aus demselben Speicherinhalt, ohne dass die Hauptdatei je
         * gelesen wurde - eine abgeschnittene Datei stand dann zweimal da. */
        $ko_fl = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        clearstatcache(true, $p['config']);
        $ko_zurueck = json_decode(ko_lesen($p['config']), true);
        if (!is_array($ko_zurueck) || json_encode($ko_zurueck, $ko_fl) !== json_encode($cfg, $ko_fl)) {
            ko_log('FEHLER: ' . $p['config'] . ' traegt nach dem Schreiben nicht den geschriebenen '
                 . 'Stand - die Zweitschrift bleibt unberuehrt.');
            return false;
        }
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
        clearstatcache(true, $datei);
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
        /* Ohne Wurzel oder aus einem ausgepackten Archiv (ko_paths(),
         * Archivmodus) wird der Helfer NICHT gerufen: er laeuft als root, die
         * sudoers-Regel gilt nur fuer den installierten Pfad, und ein Archiv
         * hat an Kodi nichts zu schalten. Bis 1.2.9 rief ein Archiv unter der
         * Anlage deren Helfer (in WSL gemessen, Pruefung-KODI-NG-1.2.10,
         * Fall A6). */
        if ($p['home'] === '') { return ''; }
        /* Mit Zeitschranke und ohne Rueckfrage (Befund K4, 29.09.2026): bis
         * 1.2.11 wartete shell_exec ohne Grenze, und eine FIFO unter
         * /home/kodi hielt Helfer und Seitenaufbau fest. -n: sudo fragt nie
         * nach einem Kennwort, sondern scheitert. */
        $cmd = 'timeout 15 sudo -n ' . escapeshellcmd($p['bin'] . '/elevatedhelper.pl') . ' ' . $args . ' 2>/dev/null';
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

        /* ZUERST DIE EINGESPIELTE UNIT, dann die Paketkopie.
         *
         * Bis 1.2.6 wurde nur die Paketkopie unter data/ gelesen. Ob die Unit
         * ueberhaupt bei systemd angekommen ist, fragte niemand - hatte
         * postroot.sh "<FAIL> data/kodi_ng.service fehlt" gemeldet, sagte
         * die Oberflaeche trotzdem "gestoppt" statt "es gibt keinen Dienst".
         * 'unit' sagt deshalb, WOHER die Zeile stammt. */
        $exec = '';
        $herkunft = '';
        $unit = ko_lesen('/etc/systemd/system/kodi_ng.service');
        if ($unit !== '') {
            $herkunft = 'eingespielt';
        } else {
            $unit = ko_lesen(ko_paths()['data'] . '/kodi_ng.service');
            if ($unit === '') {
                // Im entpackten Archiv liegt sie eine Ebene hoeher.
                $unit = ko_lesen(dirname(__DIR__) . '/data/kodi_ng.service');
            }
            if ($unit !== '') { $herkunft = 'paketkopie'; }
        }
        if (preg_match('/^ExecStart=(\S+)/m', $unit, $m)) { $exec = $m[1]; }

        $paket = '';
        $zustand = '';
        // dpkg gibt es nur auf Linux; unter Windows (Pruefstand) schrieb cmd
        // bei jedem Seitenaufbau eine Fehlermeldung auf die Fehlerausgabe.
        $roh = DIRECTORY_SEPARATOR === '/' ? trim((string) @shell_exec(
            'dpkg-query -W -f=\'${Status}|${Version}\' kodi 2>/dev/null')) : '';
        /* ${Status} hat DREI Woerter: Wunsch, Kennzeichen, Zustand. Massgeblich
         * ist das DRITTE. Bis 1.2.6 stand hier ein Vergleich auf den
         * Zeilenanfang "install ok installed" - damit galt "hold ok installed"
         * (nach apt-mark hold kodi) als NICHT installiert, obwohl Kodi da ist.
         * "deinstall ok config-files" ist weiterhin nicht installiert: dort
         * steht im dritten Wort config-files. Gemessen am Geraet 17.09.2026:
         * "install ok installed|3:21.3+dfsg-1+rpt3". */
        $t = explode('|', $roh, 2);
        $woerter = preg_split('/\s+/', trim($t[0]));
        if (count($woerter) === 3) {
            $zustand = $woerter[2];
            if ($zustand === 'installed') {
                $paket = isset($t[1]) && trim($t[1]) !== '' ? trim($t[1]) : '?';
            }
        }

        $i = array(
            'exec'    => $exec,
            'datei'   => ($exec !== '' && @is_file($exec)),
            'paket'   => $paket,
            'zustand' => $zustand,
            'unit'    => $herkunft,
        );
        return $i;
    }
}

/**
 * Kann Kodi auf diesem Geraet ueberhaupt ein Bild erzeugen?
 *
 * GEMESSEN AM 17.09.2026, an einem LoxBerry 4 auf DietPi (Raspberry Pi 4):
 * Kodi 21 startet dort nicht. Im Protokoll steht "CDRMUtils::OpenDrm - no drm
 * devices found" und "unable to init windowing system", kodi.bin endet mit
 * 255. Ursache: es gibt kein /dev/dri - der KMS-Treiber des Pi ist nicht
 * geladen (in der config.txt fehlt dtoverlay=vc4-kms-v3d). Die Oberflaeche
 * sagte dazu eine Woche lang nur "gestoppt".
 *
 * Das Plugin SCHALTET DEN TREIBER NICHT SELBST EIN: das ist ein Eingriff in
 * die Bootkonfiguration mit Neustart, der das ganze Geraet betrifft, und die
 * Entscheidung des Hausherrn. Es sagt nur, was fehlt.
 *
 * Rueckgabe: array('stand' => 1|0|-1, 'geraete' => array(...))
 *   1  mindestens ein /dev/dri/card*
 *   0  kein /dev/dri oder keine Karte darin
 *  -1  nicht feststellbar (kein Linux unter den Fuessen)
 */
if (!function_exists('ko_bildausgabe')) {
    function ko_bildausgabe()
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_dir('/dev')) {
            return array('stand' => -1, 'geraete' => array());
        }
        $karten = is_dir('/dev/dri') ? (glob('/dev/dri/card*') ?: array()) : array();
        return array('stand' => $karten ? 1 : 0, 'geraete' => $karten);
    }
}

/**
 * Warum steht der Kodi-Dienst? systemd weiss es, und fragen darf jeder.
 *
 * `systemctl show` braucht keine Rechte. Bis 1.2.6 kannte die Oberflaeche nur
 * "laeuft" und "gestoppt" - ein Kodi, das dreimal abgestuerzt war, sah genauso
 * aus wie eines, das jemand angehalten hat.
 *
 * Rueckgabe: null (nicht feststellbar) oder array mit
 *   aktiv     active|inactive|failed|activating|...
 *   ergebnis  success|exit-code|signal|core-dump|start-limit-hit|...
 *   status    Rueckgabewert des letzten Laufs (ExecMainStatus)
 *   seit      Zeitpunkt des letzten Zustandswechsels, wie systemd ihn schreibt
 */
if (!function_exists('ko_dienst_lage')) {
    function ko_dienst_lage($neu = false)
    {
        static $l = null;
        static $gefragt = false;
        if ($gefragt && !$neu) { return $l; }
        $gefragt = true;
        $l = null;
        if (DIRECTORY_SEPARATOR !== '/') { return $l; }
        $roh = (string) @shell_exec('systemctl show kodi_ng -p ActiveState -p Result '
            . '-p ExecMainStatus -p StateChangeTimestamp --no-pager 2>/dev/null');
        $w = array();
        foreach (preg_split('/\R/', $roh) as $z) {
            if (strpos($z, '=') === false) { continue; }
            list($k, $v) = explode('=', $z, 2);
            $w[trim($k)] = trim($v);
        }
        if (!isset($w['ActiveState']) || $w['ActiveState'] === '') { return $l; }
        $l = array(
            'aktiv'    => $w['ActiveState'],
            'ergebnis' => isset($w['Result']) ? $w['Result'] : '',
            'status'   => isset($w['ExecMainStatus']) ? $w['ExecMainStatus'] : '',
            'seit'     => isset($w['StateChangeTimestamp']) ? $w['StateChangeTimestamp'] : '',
        );
        return $l;
    }
}

/**
 * Der Zustand des Kodi-Dienstes als EIN Wort - fuer Kachel und Meldungen.
 *
 * Vier Zustaende, nicht zwei: laeuft, abgestuerzt, nicht installiert,
 * gestoppt - und ein Fragezeichen, wenn der Helfer schweigt. Bis 1.2.6 sah
 * "abgestuerzt" aus wie "gestoppt".
 */
if (!function_exists('ko_dienst_text')) {
    function ko_dienst_text($st, $dl)
    {
        if (!$st) { return '?'; }
        if (!empty($st['kodistarted'])) { return ko_t('ALLG.LAEUFT'); }
        $kp = ko_kodi_paket();
        if ($kp['exec'] !== '' && !$kp['datei']) { return ko_t('ALLG.NICHT_INSTALLIERT'); }
        if (is_array($dl) && ($dl['aktiv'] === 'failed'
            || ($dl['ergebnis'] !== '' && $dl['ergebnis'] !== 'success'))) {
            return sprintf(ko_t('ALLG.ABGESTUERZT'), $dl['status'] !== '' ? $dl['status'] : '?');
        }
        return ko_t('ALLG.GESTOPPT');
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
            // 1.2.22 (Entwurf 3.6): esallinterfaces - true, false oder '' (nicht gesetzt bzw. alter Helfer).
            'netz'      => isset($j['netz']) ? (string) $j['netz'] : '',
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
            return 'http://' . ko_wirt_form($host) . ':' . $port . '/';
        }

        // Kodi laeuft auf diesem LoxBerry - siehe ko_sicht_wirt().
        $host = ko_sicht_wirt();
        if ($host === '') { return ''; }
        return 'http://' . $host . ':' . $port . '/';
    }
}

/**
 * Unter welchem Wirt erreicht der MINISERVER Kodis TCP-Schnittstelle (9090)?
 *
 * EINE Stelle fuer die Anzeige im Reiter "Einbindung in Loxone" und fuer die
 * Vorlage der Steuerbefehle. Steht kodi_host auf der Rueckschleife, laeuft
 * Kodi auf diesem LoxBerry, und genommen wird der Wirt, unter dem der
 * Anwender die Seite sieht (Positivliste, IPv6 in Klammern). Ist keiner zu
 * ermitteln, bleibt es beim Rechnernamen "loxberry" - die Oberflaeche sagt
 * dazu, dass er zu pruefen ist.
 */
if (!function_exists('ko_steuer_wirt')) {
    function ko_steuer_wirt()
    {
        $cfg = ko_config();
        $host = trim((string) $cfg['kodi_host']);
        $eigen = array('127.0.0.1', 'localhost', '::1', '[::1]', '0.0.0.0');
        if ($host !== '' && !in_array(strtolower($host), $eigen, true) && ko_wirt_gueltig(ko_wirt_form($host))) {
            return ko_wirt_form($host);
        }
        $w = ko_sicht_wirt();
        return $w !== '' ? $w : 'loxberry';
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
 * Die Abodatei fuer das MQTT-Gateway nachfuehren (KODI-c1, Verbesserungsbau
 * 01.10.2026).
 *
 * Das Gateway V1 liest config/plugins/<ordner>/mqtt_subscriptions.cfg selbst
 * und abonniert daraus (Regeln/07, am laufenden Gateway belegt an Midea2Lox
 * 4.5.4). Mitgeliefert wird die Datei mit kodi/#; das trifft nur das
 * Vorgabepraefix. Deshalb fuehrt das Plugin sie selbst auf <praefix>/# nach
 * (Bauform Einspeisebremse 0.9.20): beim Speichern im Reiter MQTT, nach dem
 * Zurueckspielen und bei jedem Lauf des Cron - der Installer kopiert bei
 * einem Update erst die mitgelieferte kodi/#, bevor postupgrade.sh die alte
 * Datei zurueckstellt.
 *
 * Geschrieben wird nur, wenn die Datei abweicht, und dann mit Protokollzeile.
 * Nicht geschrieben wird im Archivmodus (dort laege sie im Paket) und bei
 * einem unzulaessigen Praefix (Befund K3: das Plugin sendet dann nicht, und
 * "kodi+1/#" waere ein Platzhalter-Abo).
 *
 * $schreiben = false: nur nachsehen (Oberflaeche, Reiter Test).
 * Rueckgabe: array(stand, grund, datei)
 *   stand  1 = traegt <praefix>/#, 0 = traegt es nicht, -1 = nicht zu sagen
 *   grund  steht | geschrieben | fehlt | abweichend | schreibfehler | archiv | praefix
 */
if (!function_exists('ko_abodatei_nachfuehren')) {
    function ko_abodatei_nachfuehren($schreiben = true)
    {
        $p = ko_paths();
        $datei = $p['configdir'] . '/mqtt_subscriptions.cfg';
        if ($p['home'] === '') { return array(-1, 'archiv', $datei); }
        $cfg = ko_config();
        $praefix = $cfg['mqtt_topic'] !== '' ? (string) $cfg['mqtt_topic'] : 'kodi';
        if (ko_wert_pruefen('mqtt_topic', $praefix) !== $praefix) { return array(-1, 'praefix', $datei); }
        $soll = $praefix . "/#\n";
        clearstatcache(true, $datei);
        if (is_file($datei) && ko_lesen($datei) === $soll) { return array(1, 'steht', $datei); }
        if (!$schreiben) { return array(0, is_file($datei) ? 'abweichend' : 'fehlt', $datei); }

        if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0775, true); }
        // Atomar und gezaehlt wie ko_json_schreiben(); das Gateway laeuft als
        // loxberry und liest, 0644 genuegt (kein Geheimnis darin).
        $tmp = $datei . '.tmp.' . getmypid();
        $ok = false;
        $fh = @fopen($tmp, 'c');
        if ($fh !== false) {
            @chmod($tmp, 0644);
            $ok = ftruncate($fh, 0) && fwrite($fh, $soll) === strlen($soll);
            $ok = fflush($fh) && $ok;
            $ok = fclose($fh) && $ok;
            if ($ok) {
                clearstatcache(true, $tmp);
                $ok = (@file_get_contents($tmp) === $soll) && @rename($tmp, $datei);
            }
            if (!$ok) { @unlink($tmp); }
        }
        if ($ok) {
            ko_log('Abodatei ' . $datei . ' auf ' . $praefix . '/# gesetzt.');
            return array(1, 'geschrieben', $datei);
        }
        /* Gebremst: der Cron ruft jede Minute (Regeln/03, hoechstens einmal
         * je Stunde). Gesucht wird im Protokoll selbst, wie beim
         * Formularmerkmal. */
        $zuletzt = 0;
        foreach (ko_log_ende($p['log'], 50) as $z) {
            if (strpos($z, 'FEHLER: die Abodatei liess sich nicht') !== false
                && preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\]/', $z, $m)) {
                $zuletzt = (int) strtotime($m[1]);
                break;
            }
        }
        $jetzt = time();
        if ($zuletzt <= 0 || ($jetzt - $zuletzt) >= 3600 || $zuletzt > $jetzt) {
            ko_log('FEHLER: die Abodatei liess sich nicht nach ' . $datei . ' schreiben (' . $praefix
                 . '/#). Unter Gateway V1 ist das Abo dann von Hand einzutragen.');
        }
        return array(0, 'schreibfehler', $datei);
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
 * Retained oder nicht - je THEMA, aus der Themenliste.
 *
 * Hausstandard seit 03.09.2026: Zustaende retained, das Lebenszeichen NIE.
 * Bis 1.2.6 entschied ko_mqtt_publish() das am AUFRUF und schickte alles
 * retained - auch zeitstempel und herzschlag. Ein zurueckbehaltenes
 * Lebenszeichen zeigt nach dem Tod des Senders fuer immer "lebt".
 *
 * Ein Thema, das nicht in der Liste steht, geht NICHT retained hinaus: es darf
 * nicht auf Dauer im Broker stehen bleiben.
 */
if (!function_exists('ko_thema_retain')) {
    function ko_thema_retain($name)
    {
        foreach (ko_themen() as $t) {
            if ($t['name'] === $name) { return !empty($t['retain']); }
        }
        return false;
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
 * $trocken = true geht DENSELBEN Weg bis vor den Socket: Port ermitteln,
 * Zeilen bauen, retain oder publish entscheiden - nur das Senden unterbleibt.
 * Bis 1.2.6 verliess der Trockenlauf das Skript VOR dieser Funktion und
 * konnte "kein UDP-Eingang" gar nicht zeigen.
 *
 * $abraeumen: Themen, deren zurueckbehaltener Altwert in diesem Lauf mit einer
 * leeren retain-Nutzlast geloescht wird (Auswahl im Statussender ueber
 * ko_mqtt_altlast()). Die Loeschzeile steht UNMITTELBAR vor dem gueltigen
 * Wert desselben Themas: eine leere retained Nachricht geht auch an die
 * Abonnenten und kaeme am Miniserver als leerer Wert an (Regeln/07) - der
 * Wert dahinter stellt ihn sofort wieder her. Themen ohne Wert in diesem Lauf
 * werden vorneweg geloescht. Bis 1.2.9 gingen die Loeschzeilen als Block vor
 * ALLEN Werten hinaus (Fall R6).
 *
 * $fluechtig: Themen, die in DIESEM Aufruf ohne retain hinausgehen, auch
 * wenn die Tabelle sie retained fuehrt - oder true fuer alle. Gebraucht fuer
 * Platzhalter ("-" = nicht feststellbar, eine Aussage des Senders ueber sich)
 * und fuer das Test-Ereignis im Reiter Test (Regeln/07, 19.09.2026: eine
 * Aussage des Dienstes ueber sich ist nie retained; bei einem Abruffehler
 * bleibt der letzte Geraetestand im Broker, Bauart BatterieBMS 0.9.28).
 *
 * Rueckgabe: array(uebergeben, meldung, versucht, zeilen)
 *   versucht   Zahl der Werte, die nicht null waren (ohne Loeschzeilen)
 *   uebergeben Zahl der Wertzeilen, die der Kern angenommen hat (im
 *              Trockenlauf 0)
 *   zeilen     die gebauten Zeilen samt Loeschzeilen - fuer den Trockenlauf
 *              und die Pruefung
 * Ob eine Loeschung ankam, sagt der Rueckgabewert NICHT - das kann nur der
 * Broker (ko_mqtt_behalten_liste()).
 */
if (!function_exists('ko_mqtt_publish')) {
    function ko_mqtt_publish($paare, $trocken = false, $abraeumen = array(), $fluechtig = array())
    {
        $cfg = ko_config();
        $prefix = $cfg['mqtt_topic'] !== '' ? $cfg['mqtt_topic'] : 'kodi';
        /* DAS GESPEICHERTE PRAEFIX WIRD VOR DEM SENDEN GEPRUEFT (Befund K3,
         * 29.09.2026) - mit derselben Pruefung wie im Formular. Bis 1.2.6 nahm
         * die Oberflaeche Praefixe wie "kodi+1" oder "wohn#zimmer" an, und sie
         * ueberstehen jedes Update. Am Gateway trennt ein Leerzeichen Thema und
         * Wert, ein Zeilenumbruch schleust eine zweite Zeile ein. Ungueltig
         * heisst: nichts senden und es sagen, nie still ein anderes Praefix.
         * Protokolliert wird beim Aufrufer, gebremst (siehe unten). */
        if (ko_wert_pruefen('mqtt_topic', $prefix) !== $prefix) {
            $ko_werte_n = 0;
            foreach ($paare as $ko_v) { if ($ko_v !== null) { $ko_werte_n++; } }
            return array(0, 'Themenpraefix unzulaessig: "' . addcslashes($prefix, "\0..\37\177\"\\")
                . '" - im Reiter MQTT berichtigen', $ko_werte_n, array());
        }
        $zeilen = array();
        $loesch = array();   // Stellen der Loeschzeilen in $zeilen
        foreach ($abraeumen as $a) {
            if (!isset($paare[$a])) {
                // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
                // Form, die das Gateway als Loeschung liest (Regeln/07).
                $loesch[count($zeilen)] = true;
                $zeilen[] = 'retain ' . $prefix . '/' . $a . ' ';
            }
        }
        foreach ($paare as $k => $v) {
            // null wird uebersprungen, '' und '0' NICHT: eine leere Zeichenkette
            // ist ein Wert, null ist keiner.
            if ($v === null) { continue; }
            $wert = ko_mqtt_wert_saeubern($v);
            /* Ein leerer Wert geht nie retained hinaus - eine leere Nutzlast
             * LOESCHT ein zurueckbehaltenes Thema (Regeln/07). ko_mqtt_wert_
             * saeubern() macht aus leer zwar "-", die Zeile hier haelt es
             * trotzdem fuer sich fest: eine Absicherung, die von der anderen
             * abhaengt, ist keine. */
            $befehl = (ko_thema_retain((string) $k) && $wert !== '') ? 'retain' : 'publish';
            if ($fluechtig === true || (is_array($fluechtig) && in_array((string) $k, $fluechtig, true))) {
                $befehl = 'publish';
            }
            if (in_array((string) $k, $abraeumen, true)) {
                $loesch[count($zeilen)] = true;
                $zeilen[] = 'retain ' . $prefix . '/' . $k . ' ';
            }
            $zeilen[] = $befehl . ' ' . $prefix . '/' . $k . ' ' . $wert;
        }
        $versucht = count($zeilen) - count($loesch);

        /* Diese Funktion PROTOKOLLIERT NICHT SELBST. Das tut der Aufrufer, und
         * zwar gebremst: bis 1.2.7 schrieb sie bei fehlendem UDP-Eingang aus
         * dem minuetlichen Cron jede Minute eine Zeile - gemessen am
         * Pruefstand: +4 Zeilen in drei Laeufen, trotz Bremse im Sender. */
        $udp = ko_mqtt_port();
        if (!$udp) {
            return array(0, 'kein UDP-Eingang', $versucht, $zeilen);
        }
        if ($trocken) { return array(0, '', $versucht, $zeilen); }

        $fehler = 0;
        $meldung = '';
        $fp = @stream_socket_client('udp://127.0.0.1:' . $udp, $fehler, $meldung, 2);
        if ($fp === false) {
            return array(0, 'UDP-Relay 127.0.0.1:' . $udp . ': ' . (string) $meldung, $versucht, $zeilen);
        }
        stream_set_timeout($fp, 2);
        /* UDP ist verbindungslos. stream_socket_client() gelingt auch dann,
         * wenn am Zielport niemand lauscht, und fwrite() meldet nur, dass der
         * Kern das Paket angenommen hat - nicht, dass es jemand bekommen hat.
         * Gemessen am 17.09.2026: vier Zeilen "uebergeben", im Broker kam in
         * 100 Sekunden keine an, weil der UDP-Eingang des Gateways zwei
         * Megabyte hinterherhing. Der Zaehler zaehlt also UEBERGEBENE Werte;
         * ob sie ankommen, beantwortet allein der MQTT Finder. */
        $n = 0;
        foreach ($zeilen as $i => $msg) {
            // Loeschzeilen zaehlen nicht mit: $n und $versucht zaehlen Werte.
            if (@fwrite($fp, $msg) !== false && !isset($loesch[$i])) { $n++; }
        }
        fclose($fp);
        return array($n, $n === $versucht ? '' : ($versucht - $n) . ' Zeilen nicht uebergeben',
                     $versucht, $zeilen);
    }
}

/**
 * Die Themen, deren zurueckbehaltener ALTWERT abgeraeumt wird: jedes Thema
 * des Plugins oder des Addons, das heute NICHT retained hinausgeht (beim
 * Addon seit 1.2.12 event, das bis 1.2.11 retained ging).
 *
 * Eine Umstellung von retain auf publish loescht nichts - der alte Wert steht
 * im Broker weiter und wird nach jedem Neustart von Broker oder Gateway
 * wieder ausgeliefert. zeitstempel und herzschlag gingen bis 1.2.6 retained
 * hinaus, status/ok von 1.2.7 bis 1.2.9, erreichbar bis 1.2.9. Bis 1.2.9
 * raeumte der Sender nur die beiden ersten ab (Fall R5). Geloescht wird mit
 * einer LEEREN Nutzlast und retain (Regeln/07, mqttgateway.pl "Delete ...
 * because of empty message"; am Broker gemessen 14.09.2026) - an
 * ko_mqtt_wert_saeubern() vorbei, das leer zu "-" machen wuerde.
 */
if (!function_exists('ko_mqtt_altlast_liste')) {
    function ko_mqtt_altlast_liste()
    {
        $l = array();
        foreach (ko_themen() as $t) {
            if (empty($t['retain'])) { $l[] = $t['name']; }
        }
        return $l;
    }
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung, ein SUBSCRIBE mit allen Filtern.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok' heisst: der Broker hat das Abonnement bestaetigt (oder einen Wert
 * geschickt); was dann nicht unter 'belegt' steht, ist leer. 'unbekannt': er
 * war nicht zu fragen (keine Wurzel, keine Verbindung, Anmeldung abgewiesen,
 * keine Antwort).
 *
 * Warum ueberhaupt fragen: das Abraeumen laeuft ueber den UDP-Eingang des
 * Gateways, und dort meldet fwrite() auch fuer ein verworfenes Datagramm
 * Erfolg (Regeln/07, "Ein Absender merkt nichts davon", Nachtraege vom
 * 19.09.2026 - der Anlass war genau diese Linie). Belegt ist das Abraeumen
 * erst, wenn der Broker selbst sagt, dass nichts mehr dasteht.
 *
 * MQTT 3.1.1 von Hand, nur CONNECT, SUBSCRIBE (QoS 0) und DISCONNECT - ohne
 * fremde Bibliothek; uebernommen aus tb_mqtt_behalten_liste() in
 * Spotpreis-Tibber 0.9.19. Die Anmeldung nimmt Brokeruser/Brokerpass aus der
 * general.json (Regeln/07, Abschnitt 2); das Kennwort steht nur im
 * CONNECT-Paket, nie in einem Protokoll und nie auf einer Kommandozeile.
 */
if (!function_exists('ko_mqtt_behalten_liste')) {
    function ko_mqtt_behalten_liste(array $themen)
    {
        $aus = array('lage' => 'unbekannt', 'belegt' => array());
        $soll = array();
        foreach ($themen as $t) {
            if ((string) $t !== '') { $soll[(string) $t] = true; }
        }
        if (!$soll) {
            $aus['lage'] = 'ok';
            return $aus;
        }
        $p = ko_paths();
        if ($p['home'] === '') { return $aus; }
        $gen = ko_json_lesen($p['home'] . '/config/system/general.json');
        $m = array();
        if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
        elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
        if (!$m) { return $aus; }
        $hol = function ($gross, $klein) use ($m) {
            if (isset($m[$gross]) && is_scalar($m[$gross])) { return (string) $m[$gross]; }
            return (isset($m[$klein]) && is_scalar($m[$klein])) ? (string) $m[$klein] : '';
        };
        $host = trim($hol('Brokerhost', 'brokerhost'));
        if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
        $port = (int) $hol('Brokerport', 'brokerport');
        if ($port <= 0 || $port > 65535) { $port = 1883; }
        $benutzer = $hol('Brokeruser', 'brokeruser');
        $kennwort = $hol('Brokerpass', 'brokerpass');

        $errno = 0;
        $errstr = '';
        // K9 (1.2.22): ein IPv6-Literal braucht die Klammerform - "tcp://::1:1883" scheiterte unter 7.4/8.4/8.5
        // mit Fehler 10049 (Probe k9.php), dann galt der Broker als "nicht zu fragen".
        $s = @stream_socket_client('tcp://' . ko_wirt_form($host) . ':' . $port, $errno, $errstr, 2);
        if (!$s) { return $aus; }
        stream_set_timeout($s, 1);

        $zk = function ($t) { return pack('n', strlen($t)) . $t; };
        $laenge = function ($n) {
            $o = '';
            do {
                $b = $n % 128;
                $n = intdiv($n, 128);
                if ($n > 0) { $b |= 128; }
                $o .= chr($b);
            } while ($n > 0);
            return $o;
        };
        /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
        $lies = function ($n) use ($s) {
            $d = '';
            while (strlen($d) < $n) {
                $t = @fread($s, $n - strlen($d));
                if ($t === false || $t === '') {
                    $meta = stream_get_meta_data($s);
                    if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                    continue;
                }
                $d .= $t;
            }
            return $d;
        };
        /* Ein Paket: array(kopfbyte, rumpf) oder null. */
        $paket = function () use ($lies) {
            $k = $lies(1);
            if ($k === null) { return null; }
            $n = 0;
            $mult = 1;
            for ($i = 0; $i < 4; $i++) {
                $b = $lies(1);
                if ($b === null) { return null; }
                $n += (ord($b) & 127) * $mult;
                $mult *= 128;
                if (!(ord($b) & 128)) { break; }
            }
            $r = ($n > 0) ? $lies($n) : '';
            return ($r === null) ? null : array(ord($k), $r);
        };

        $flags = 0x02;                                  // saubere Sitzung
        $nutz = $zk('korueck' . getmypid());
        if ($benutzer !== '') {
            $flags |= 0x80;
            // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
            if ($kennwort !== '') { $flags |= 0x40; }
        }
        $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
        if ($benutzer !== '') {
            $nutz .= $zk($benutzer);
            if ($kennwort !== '') { $nutz .= $zk($kennwort); }
        }
        if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
            $ack = $paket();
            if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
                $sub = pack('n', 1);
                foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
                @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
                $bestaetigt = false;
                $ende = microtime(true) + 3.0;
                while (microtime(true) < $ende) {
                    $pk = $paket();
                    if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                    $art = $pk[0] >> 4;
                    if ($art === 9) {
                        /* Je Filter ein Rueckgabebyte hinter der Paketkennung, in der
                           Reihenfolge des SUBSCRIBE; ab 0x80 heisst abgelehnt (etwa
                           durch eine ACL). Danach schickt der Broker nichts - ungeprueft
                           hiesse das "nichts belegt", und der Merker laege auf einer
                           Antwort, die keine war (in WSL gemessen, Pruefung-KODI-NG-1.2.11,
                           Faelle S3, S4, S7, S9, S11). Bauart bw_mqtt_behalten_liste(),
                           Beschattungswaechter 0.9.21. */
                        $rc = (string) substr($pk[1], 2);
                        if (strlen($rc) !== count($soll)) { break; }
                        $abgelehnt = false;
                        for ($i = 0; $i < strlen($rc); $i++) {
                            if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                        }
                        if ($abgelehnt) { break; }
                        $bestaetigt = true;
                        // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                        $ende = min($ende, microtime(true) + 1.0);
                    } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                        $tl = unpack('n', substr($pk[1], 0, 2));
                        $t = substr($pk[1], 2, $tl[1]);
                        $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                        $wert = (string) substr($pk[1], $versatz);
                        // Am empfangenen Paket: nur mit gesetztem Retain-Merkmal.
                        if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                            $aus['belegt'][$t] = true;
                            if (count($aus['belegt']) === count($soll)) { break; }
                        }
                    }
                }
                if ($bestaetigt || $aus['belegt']) { $aus['lage'] = 'ok'; }
            }
            @fwrite($s, chr(0xE0) . chr(0));
        }
        fclose($s);
        return $aus;
    }
}

/**
 * Welche Altwerte muessen in diesem Lauf noch abgeraeumt werden?
 *
 * Rueckgabe array('lage' => 'erledigt'|'belegt'|'unbekannt',
 *                 'themen' => array(<thema ohne praefix>, ...)).
 *
 * Je Lauf, bis der Merker liegt: den Broker nach allen Themen aus
 * ko_mqtt_altlast_liste() fragen; keines belegt -> Merker schreiben, nichts
 * abraeumen ('erledigt'); einige belegt -> genau diese ('belegt'), kein
 * Merker; nicht zu fragen -> alle ('unbekannt'), kein Merker. Der Merker
 * entsteht NUR aus der Antwort des Brokers, nie aus dem Senden (Fall R10).
 * Er traegt die Kennung "leer-bestaetigt <praefix>: <Themenliste>": ein
 * anderes Praefix oder eine andere Liste gilt nicht, und der Merker
 * retain_altlast_geloescht von 1.2.7 bis 1.2.9 (in zustand.json) hat einen
 * anderen Ort und gilt deshalb ebenfalls nicht (Faelle R13, R14).
 * purge_installation raeumt ihn bei jedem Upgrade mit ab; dann wird einmal
 * nachgefragt. Bauart tb_mqtt_altlast(), Spotpreis-Tibber 0.9.19.
 */
if (!function_exists('ko_mqtt_altlast')) {
    function ko_mqtt_altlast($praefix, $zusatz = array())
    {
        $praefix = (string) $praefix;
        $liste = ko_mqtt_altlast_liste();
        /* $zusatz: retained Themen, die in dieser Lage nicht mehr gesendet
         * werden und deshalb wie ein Altwert abgeraeumt werden (Befund M1,
         * 29.09.2026: wiedergabe und titel bei ausgeschaltetem JSON-RPC). Sie
         * stehen in der Kennung des Merkers - ein anderer Satz fragt neu. */
        foreach ((array) $zusatz as $ko_z) {
            if (!in_array((string) $ko_z, $liste, true)) { $liste[] = (string) $ko_z; }
        }
        $p = ko_paths();
        $merker = $p['data'] . '/retain_altlast_bestaetigt';
        $kennung = 'leer-bestaetigt ' . $praefix . ': ' . implode(' ', $liste);
        if (is_file($merker) && trim(ko_lesen($merker)) === $kennung) {
            return array('lage' => 'erledigt', 'themen' => array());
        }
        $voll = array();
        foreach ($liste as $t) { $voll[] = $praefix . '/' . $t; }
        $f = ko_mqtt_behalten_liste($voll);
        if ($f['lage'] === 'ok' && !$f['belegt']) {
            if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
            if (@file_put_contents($merker, $kennung . "\n") !== false) {
                ko_log('Statussender: unter ' . $praefix . '/ steht keines der ' . count($liste)
                    . ' frueher zurueckbehaltenen Themen mehr im Broker (' . implode(', ', $liste)
                    . '; vom Broker bestaetigt).');
            }
            return array('lage' => 'erledigt', 'themen' => array());
        }
        if ($f['lage'] === 'ok') {
            $l = strlen($praefix) + 1;
            $t = array();
            foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, $l); }
            return array('lage' => 'belegt', 'themen' => $t);
        }
        return array('lage' => 'unbekannt', 'themen' => $liste);
    }
}

/**
 * Die Themen, die die Deinstallation leert: jedes, das eine veroeffentlichte
 * Fassung je retained gesendet hat - die heute retained Themen der Tabelle
 * (des Plugins UND des Addons, das seine Themen ebenfalls retained sendet)
 * und die Altwerte aus ko_mqtt_altlast_liste(). Das ist die ganze Tabelle.
 */
if (!function_exists('ko_mqtt_leer_themen')) {
    function ko_mqtt_leer_themen()
    {
        $l = array();
        foreach (ko_themen() as $t) {
            if (!empty($t['retain'])) { $l[] = $t['name']; }
        }
        foreach (ko_mqtt_altlast_liste() as $n) {
            if (!in_array($n, $l, true)) { $l[] = $n; }
        }
        return $l;
    }
}

/**
 * Aus der Deinstallation: die zurueckbehaltenen Themen der Linie leeren
 * (kodi_ng_status.php --mqtt-leeren).
 *
 * Der Weg ist derselbe wie beim Senden - der UDP-Eingang des Gateways,
 * "retain <thema> " mit leerer Nutzlast. VOR der ersten Runde und nach jeder
 * wird der Broker gefragt (ko_mqtt_behalten_liste()); hinaus geht nur, was
 * dort noch steht, hoechstens $runden Runden. Steht nichts da, geht nichts
 * hinaus. Ist der Broker nicht zu fragen, gehen alle Themen in jeder Runde
 * hinaus, und die Ausgabe sagt, dass nicht nachgelesen wurde - der Eingang
 * verwirft unter Last Datagramme (Regeln/07), ein blosses Senden ist kein
 * Beleg. Bauart tb_mqtt_leeren(), Spotpreis-Tibber 0.9.19.
 *
 * Bis 1.2.9 raeumte die Deinstallation nichts ab (in WSL gemessen,
 * Pruefung-KODI-NG-1.2.10, Faelle U1 bis U4). Schreibt weder Protokoll noch
 * Datei; Ausgabe im Format der Hakenskripte. Rueckgabe 0 geleert oder nicht
 * nachpruefbar, 1 es steht noch etwas bzw. der Eingang war nicht erreichbar,
 * 2 nicht moeglich.
 */
if (!function_exists('ko_mqtt_leeren')) {
    /* K14 (1.2.22): nach dem Lauf fuer das Praefix der Konfiguration auch das des Kodi-Addons, wenn es
     * abweicht (ko_mqtt_leeren_addon()). Rueckgabe: die groessere der beiden. */
    function ko_mqtt_leeren($runden = 3, $pause_us = 1000000)
    {
        $rc = ko_mqtt_leeren_haupt($runden, $pause_us);
        return max($rc, ko_mqtt_leeren_addon($runden, $pause_us));
    }
}

/**
 * K14 (1.2.22, Pruefbericht K14): hat das Kodi-Addon ein ANDERES, gueltiges Praefix (Warnung
 * ADDON.THEMA_ABWEICHUNG im Reiter MQTT), bleiben dessen retained Themen nach der Deinstallation stehen -
 * bis 1.2.21 leerte ko_mqtt_leeren() nur mqtt_topic aus kodi.json. Ein zweiter ko_mqtt_leeren_lauf() nur mit
 * den Addon-Themen (quelle addon: die fuenf retained Titel und screensaver, dazu event als Altlast), eigene
 * Ausgabezeile. Nicht lesbar: <INFO>, nicht geprueft. Rueckgabe wie ko_mqtt_leeren().
 */
if (!function_exists('ko_mqtt_leeren_addon')) {
    function ko_mqtt_leeren_addon($runden = 3, $pause_us = 1000000)
    {
        $prefix = ko_praefix();
        $a = ko_addon_lesen();
        if (!is_array($a)) {
            echo "<INFO> MQTT: das Praefix des Kodi-Addons war nicht lesbar - nicht geprueft.\n";
            return 0;
        }
        $ap = isset($a['mqtt_topic']) ? trim((string) $a['mqtt_topic']) : '';
        if ($ap === '' || $ap === $prefix) { return 0; }
        if (ko_wert_pruefen('mqtt_topic', $ap) !== $ap) {
            echo "<INFO> MQTT: das Praefix des Kodi-Addons ist unzulaessig - seine Themen wurden nicht geleert.\n";
            return 0;
        }
        $themen = array();
        foreach (ko_themen() as $t) {
            if ($t['quelle'] === 'addon') { $themen[] = $t['name']; }
        }
        $e = ko_mqtt_leeren_lauf($ap, $themen, $runden, $pause_us);
        $wo = 'unter dem abweichenden Praefix des Kodi-Addons ' . $ap . '/';
        switch ($e['lage']) {
            case 'nichts':
                echo "<OK> MQTT: der Broker bestaetigt: keines der " . $e['n'] . " Addon-Themen " . $wo . " steht zurueckbehalten.\n";
                return 0;
            case 'geleert':
                echo "<OK> MQTT: " . $e['zu_leeren'] . " Addon-Themen " . $wo . " geleert; der Broker bestaetigt es.\n";
                return 0;
            case 'offen':
                echo "<WARNING> MQTT: " . count($e['offen']) . " Addon-Themen " . $wo . " stehen noch zurueckbehalten. Von Hand: mosquitto_pub -r -n -t <thema>\n";
                return 1;
            case 'ungeprueft':
                echo "<INFO> MQTT: " . $e['zu_leeren'] . " Addon-Themen " . $wo . " mit leerer Nutzlast gesendet - nicht nachgelesen.\n";
                return 0;
            case 'udp_zu':
                echo "<WARNING> MQTT: der UDP-Eingang war nicht erreichbar - Addon-Themen " . $wo . " nicht geleert.\n";
                return 1;
        }
        echo "<INFO> MQTT: Addon-Themen " . $wo . " nicht geleert (" . $e['lage'] . ").\n";
        return 2;
    }
}

/** Der Lauf fuer das Praefix der Konfiguration - bis 1.2.21 der Rumpf von ko_mqtt_leeren(), unveraendert. */
if (!function_exists('ko_mqtt_leeren_haupt')) {
    function ko_mqtt_leeren_haupt($runden = 3, $pause_us = 1000000)
    {
        $cfg = ko_config();
        $prefix = $cfg['mqtt_topic'] !== '' ? $cfg['mqtt_topic'] : 'kodi';
        /* Der Kern steht seit dem Befund M2/M3 (29.09.2026) in
         * ko_mqtt_leeren_lauf(), damit ihn auch die Oberflaeche rufen kann.
         * Die Ausgabe hier ist Wort fuer Wort die bisherige. */
        $e = ko_mqtt_leeren_lauf($prefix, ko_mqtt_leer_themen(), $runden, $pause_us);
        $n = $e['n'];
        $udp = $e['udp'];
        if ($e['lage'] === 'praefix') {
            echo "<WARNING> MQTT: das Themenpraefix ist leer oder enthaelt einen Platzhalter oder "
               . "Leerraum - zurueckbehaltene Themen wurden nicht geleert.\n";
            return 2;
        }
        if ($e['lage'] === 'kein_udp') {
            echo "<INFO> MQTT: in general.json steht kein UDP-Eingang des Gateways - zurueckbehaltene "
               . "Themen unter " . $prefix . "/ wurden nicht geleert.\n";
            return 2;
        }
        if ($e['lage'] === 'nichts') {
            echo "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen unter " . $prefix
               . "/ steht zurueckbehalten - nichts zu leeren.\n";
            return 0;
        }
        if ($e['lage'] === 'udp_zu') {
            echo "<WARNING> MQTT: der UDP-Eingang 127.0.0.1:" . $udp . " war nicht erreichbar - "
               . "zurueckbehaltene Themen unter " . $prefix . "/ wurden nicht geleert.\n";
            return 1;
        }
        $offen = $e['offen'];
        echo "<INFO> MQTT: " . $e['zu_leeren'] . " von " . $n . " Themen unter " . $prefix . "/ mit leerer "
           . "Nutzlast an den UDP-Eingang " . $udp . " des Gateways gesendet (" . $e['datagramme']
           . " Datagramme).\n";
        if ($e['lage'] === 'geleert') {
            echo "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen steht mehr "
               . "zurueckbehalten.\n";
            return 0;
        }
        if ($e['lage'] === 'offen') {
            echo "<WARNING> MQTT: " . count($offen) . " Themen stehen noch zurueckbehalten im Broker ("
               . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
               . "). Von Hand: mosquitto_pub -r -n -t <thema>\n";
            return 1;
        }
        echo "<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang "
           . "verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit "
           . "mosquitto_pub -r -n -t <thema> von Hand loeschen.\n";
        return 0;
    }
}

/**
 * Der Kern von ko_mqtt_leeren(), ohne Ausgabe - fuer die Deinstallation UND
 * die Oberflaeche (Befunde M2, M3, 29.09.2026: Statussender aus,
 * Praefixwechsel). Praefix und Themen gibt der Aufrufer vor; der Ablauf ist
 * der bisherige: Broker fragen, nur Belegtes senden, nach jeder Runde
 * nachfragen, hoechstens $runden Runden.
 *
 * Rueckgabe: array mit
 *   lage        praefix | kein_udp | nichts | udp_zu | geleert | offen | ungeprueft
 *   praefix, udp, n (Zahl der Themen), zu_leeren, datagramme, offen (Liste)
 */
if (!function_exists('ko_mqtt_leeren_lauf')) {
    function ko_mqtt_leeren_lauf($prefix, $themen, $runden = 3, $pause_us = 1000000)
    {
        $prefix = (string) $prefix;
        $e = array('lage' => '', 'praefix' => $prefix, 'udp' => 0, 'n' => 0,
                   'zu_leeren' => 0, 'datagramme' => 0, 'offen' => array());
        if (trim($prefix, '/') === '' || preg_match('/[#+\s]/', $prefix)) {
            $e['lage'] = 'praefix';
            return $e;
        }
        $udp = ko_mqtt_port();
        $e['udp'] = $udp;
        if (!$udp) {
            $e['lage'] = 'kein_udp';
            return $e;
        }
        $alle = array();
        foreach ($themen as $t) { $alle[] = $prefix . '/' . $t; }
        $e['n'] = count($alle);
        $f = ko_mqtt_behalten_liste($alle);
        $nachgelesen = ($f['lage'] === 'ok');
        $offen = $nachgelesen ? array_keys($f['belegt']) : $alle;
        if ($nachgelesen && !$offen) {
            $e['lage'] = 'nichts';
            return $e;
        }
        $eno = 0;
        $etxt = '';
        $fp = @stream_socket_client('udp://127.0.0.1:' . $udp, $eno, $etxt, 2);
        if ($fp === false) {
            $e['lage'] = 'udp_zu';
            return $e;
        }
        $e['zu_leeren'] = count($offen);
        $datagramme = 0;
        for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
            if ($r > 1) { usleep((int) $pause_us); }
            foreach ($offen as $t) {
                // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
                // Form, die das Gateway als Loeschung liest.
                @fwrite($fp, 'retain ' . $t . ' ');
                $datagramme++;
            }
            usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
            $f = ko_mqtt_behalten_liste($offen);
            if ($f['lage'] === 'ok') {
                $nachgelesen = true;
                $offen = array_keys($f['belegt']);
            } else {
                $nachgelesen = false;
            }
        }
        fclose($fp);
        $e['datagramme'] = $datagramme;
        $e['offen'] = $offen;
        $e['lage'] = $nachgelesen ? ($offen ? 'offen' : 'geleert') : 'ungeprueft';
        return $e;
    }
}

/**
 * Fuer die Oberflaeche (Befunde M2, M3): die zurueckbehaltenen Themen DES
 * PLUGINS unter $prefix leeren - wie die Deinstallation, aber ohne die Themen
 * des Addons (das sendet selbst weiter) - und das Ergebnis als Satz. Eine
 * Zeile ins Protokoll. Rueckgabe: array(ok, text); ok = false, wenn sicher
 * etwas stehen blieb oder gar nicht geleert werden konnte.
 */
if (!function_exists('ko_mqtt_leeren_meldung')) {
    function ko_mqtt_leeren_meldung($prefix)
    {
        $plugin = array();
        foreach (ko_themen() as $t) {
            if ($t['quelle'] === 'plugin') { $plugin[] = $t['name']; }
        }
        $themen = array_values(array_intersect(ko_mqtt_leer_themen(), $plugin));
        $e = ko_mqtt_leeren_lauf($prefix, $themen);
        $roh = addcslashes((string) $prefix, "\0..\37\177");
        ko_log('MQTT: zurueckbehaltene Themen des Plugins unter ' . $roh . '/ abraeumen - Ergebnis '
            . $e['lage'] . ' (' . $e['zu_leeren'] . ' von ' . $e['n'] . ' Themen gesendet, '
            . count($e['offen']) . ' stehen noch).');
        $p = '<span class="sm-mono">' . ko_e($roh) . '/</span>';
        switch ($e['lage']) {
            case 'nichts':
                return array(true, sprintf(ko_t('MQTT.M_LEEREN_NICHTS'), $p));
            case 'geleert':
                return array(true, sprintf(ko_t('MQTT.M_LEEREN_GELEERT'), $e['zu_leeren'], $p));
            case 'ungeprueft':
                return array(true, sprintf(ko_t('MQTT.M_LEEREN_UNGEPRUEFT'), $e['zu_leeren'], $p));
            case 'kein_udp':
                return array(true, sprintf(ko_t('MQTT.M_LEEREN_KEIN_UDP'), $p));
            case 'offen':
                return array(false, sprintf(ko_t('MQTT.M_LEEREN_OFFEN'), count($e['offen']), $p,
                    ko_e(implode(', ', array_slice($e['offen'], 0, 5)))));
            case 'udp_zu':
                return array(false, sprintf(ko_t('MQTT.M_LEEREN_UDP_ZU'), $p, (int) $e['udp']));
        }
        return array(false, sprintf(ko_t('MQTT.M_LEEREN_PRAEFIX'), $p));
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
 * retain: true = der Zustand bleibt im Broker stehen (Regeln/07, Hausstandard
 *         seit 03.09.2026). Nur fuer die Themen des PLUGINS wirksam - die des
 *         Addons entscheidet das Addon selbst; dort steht der Wert nur, damit
 *         die Tabelle ihn nennen kann.
 * leben:  true = Teil des Lebenszeichens. Geht bei JEDEM Cron-Durchgang
 *         hinaus und ist nie retained; die Pruefzeile im Reiter Test haelt
 *         beides gegeneinander.
 *
 * zeitstempel und herzschlag heissen seit 1.2.0 so und behalten ihren Namen
 * (an ihnen haengen Eingaenge in fremden Anlagen). Sie sind das "ts" und der
 * "zaehler" des Hausstandards. status/ok ist seit 1.2.7 NEU daneben: 1, wenn
 * der letzte Lauf wirklich gemessen hat - ein Zeitstempel allein sagt nur,
 * DASS der Sender lief, nicht dass er etwas wusste.
 */
if (!function_exists('ko_themen')) {
    function ko_themen()
    {
        return array(
            array('name' => 'dienst',      'quelle' => 'plugin', 'zahl' => true,  'retain' => true,  'leben' => false,
                  'min' => '0', 'max' => '1',          'schl' => 'T_DIENST',      'wschl' => 'V_01'),
            array('name' => 'autostart',   'quelle' => 'plugin', 'zahl' => true,  'retain' => true,  'leben' => false,
                  'min' => '0', 'max' => '1',          'schl' => 'T_AUTOSTART',   'wschl' => 'V_01'),
            /* erreichbar ist NIE retained (Regeln/07, entschieden 19.09.2026): den
             * Wert setzt der Dienst aus dem Erfolg seines EIGENEN JSON-RPC-Pings
             * - ein Ausfallmerker der Geraeteschnittstelle, keine Aussage des
             * Geraets. Stirbt der Sender, bliebe die letzte 1 stehen. Bis 1.2.9
             * retained (Klasse-E-Liste 19.09.2026). */
            array('name' => 'erreichbar',  'quelle' => 'plugin', 'zahl' => true,  'retain' => false, 'leben' => false,
                  'min' => '0', 'max' => '1',          'schl' => 'T_ERREICHBAR',  'wschl' => 'V_01'),
            array('name' => 'zeitstempel', 'quelle' => 'plugin', 'zahl' => true,  'retain' => false, 'leben' => true,
                  'min' => '0', 'max' => '2147483647', 'schl' => 'T_ZEIT',        'wschl' => 'V_ZEIT'),
            array('name' => 'herzschlag',  'quelle' => 'plugin', 'zahl' => true,  'retain' => false, 'leben' => true,
                  'min' => '0', 'max' => '2147483647', 'schl' => 'T_HERZ',        'wschl' => 'V_HERZ'),
            /* status/ok geht bei jedem Durchgang hinaus und ist NIE retained
             * (Regeln/07, entschieden 18./19.09.2026): es sagt, ob der eigene
             * Helfer geantwortet hat - eine Aussage des Dienstes ueber sich
             * selbst. Zurueckbehalten bliebe nach dem Tod des Senders die
             * letzte 1 stehen, und nach einem Neustart von Broker oder Gateway
             * laese Loxone "in Ordnung" von einem Sender, der nicht mehr
             * laeuft. 1.2.7 bis 1.2.9 sandten es retained, begruendet mit
             * "eine zurueckbehaltene 0 sagt das Richtige" - die 1 sagt es
             * nicht. Damit gehoert es zum Lebenszeichen (leben=true), und die
             * Pruefzeile "Lebenszeichen" im Reiter Test haelt es mit. */
            array('name' => 'status/ok',   'quelle' => 'plugin', 'zahl' => true,  'retain' => false, 'leben' => true,
                  'min' => '0', 'max' => '1',          'schl' => 'T_OK',          'wschl' => 'V_OK'),
            /* Lebenszeichen nach Hausform (1.2.22, Entwurf Gruppe 10, 2.2, E7):
             * status/zaehler = herzschlag % 1000 (0-999, laeuft um) und
             * status/ts = zeitstempel, nie retained, im selben Lauf. An ihm
             * haengt die Ausfallerkennung des Musterprojekts (Max 999 statt
             * 2147483647). herzschlag und zeitstempel bleiben fuer bestehende
             * Anlagen; ihr Wegfall erst nach der Hauswelle (Sammelstelle). */
            array('name' => 'status/zaehler', 'quelle' => 'plugin', 'zahl' => true, 'retain' => false, 'leben' => true,
                  'min' => '0', 'max' => '999',        'schl' => 'T_ZAEHLER',     'wschl' => 'V_ZAEHLER'),
            array('name' => 'status/ts',   'quelle' => 'plugin', 'zahl' => true,  'retain' => false, 'leben' => true,
                  'min' => '0', 'max' => '4102444800', 'schl' => 'T_TS',          'wschl' => 'V_ZEIT'),
            array('name' => 'wiedergabe',  'quelle' => 'plugin', 'zahl' => false, 'retain' => true,  'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_WIEDERGABE',  'wschl' => 'V_WIEDERGABE'),
            array('name' => 'titel',       'quelle' => 'plugin', 'zahl' => false, 'retain' => true,  'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_TITEL',       'wschl' => 'V_TEXT'),
            /* event ist ein Ereignis, kein Zustand (Entscheidung 3 vom
             * 29.09.2026): bis 1.2.11 ging es retained hinaus, und nach jedem
             * Neustart von Broker oder Gateway kam etwa "movie_started" erneut
             * als frisches Ereignis am Miniserver an. Seither fluechtig; der
             * Altwert wird ueber ko_mqtt_altlast_liste() abgeraeumt. */
            array('name' => 'event',       'quelle' => 'addon',  'zahl' => false, 'retain' => false, 'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_EVENT',       'wschl' => 'V_EVENT'),
            array('name' => 'movie_title', 'quelle' => 'addon',  'zahl' => false, 'retain' => true,  'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_MOVIETITLE',  'wschl' => 'V_TEXT'),
            array('name' => 'music_title', 'quelle' => 'addon',  'zahl' => false, 'retain' => true,  'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_MUSICTITLE',  'wschl' => 'V_TEXT'),
            array('name' => 'episode_title', 'quelle' => 'addon', 'zahl' => false, 'retain' => true, 'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_EPISODETITLE', 'wschl' => 'V_TEXT'),
            /* Es gibt sie wirklich: playing_type() im Addon liefert 'unknown',
             * wenn Kodi etwas abspielt und selbst nicht sagt was. Dann geht
             * unknown_title hinaus. Die Zeile steht hier, weil ein Thema, das
             * ankommt und in keiner Tabelle steht, den Anwender ratlos laesst. */
            array('name' => 'unknown_title', 'quelle' => 'addon', 'zahl' => false, 'retain' => true, 'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_UNKNOWNTITLE', 'wschl' => 'V_TEXT'),
            array('name' => 'screensaver', 'quelle' => 'addon',  'zahl' => false, 'retain' => true,  'leben' => false,
                  'min' => '',  'max' => '',           'schl' => 'T_SCREENSAVER', 'wschl' => 'V_ONOFF'),
        );
    }
}

/* ko_thema_in_vorlage() ist mit 1.2.22 entfallen: es entschied, welche Themen in die Eingangsvorlage
 * (VirtualInHttp) kamen - die Vorlage selbst ist entfallen (Entscheidung 1, Entwurf Gruppe 10, 2.6). Die
 * Namen nennt die Namenstabelle ko_mqtt_namen(). */

/** Wie viele Themen sendet das Plugin mit der jetzigen Einstellung?
 *  Die Zahl wird GEZAEHLT, nicht in einen Text geschrieben - "die sieben
 *  Themen" stand bis 1.2.6 in vier Saetzen, gesendet wurden ab Werk vier. */
if (!function_exists('ko_themen_plugin_zahl')) {
    function ko_themen_plugin_zahl($cfg = null)
    {
        if ($cfg === null) { $cfg = ko_config(); }
        $n = 0;
        foreach (ko_themen() as $t) {
            if ($t['quelle'] !== 'plugin') { continue; }
            if (in_array($t['name'], array('erreichbar', 'wiedergabe', 'titel'), true)
                && (string) $cfg['rpc_ein'] !== '1') { continue; }
            $n++;
        }
        return $n;
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

/** Eine Adresse abrufen und die Kopfzeilen der Antwort dazu liefern.
 *  Rueckgabe: array(Inhalt oder false, Kopfzeilen als Feld).
 *
 *  Ueber fopen() und stream_get_meta_data() statt ueber die alte
 *  Kopfzeilen-Variable von PHP (Befund K7, 29.09.2026): 8.5 meldet sie schon
 *  beim Uebersetzen als ueberholt ("Deprecated", gemessen mit php -l), PHP 9
 *  soll sie abschaffen. wrapper_data gibt es unter 7.4 wie unter 8.5;
 *  Zeitschranke und ignore_errors wirken ueber denselben Kontext wie bei
 *  file_get_contents(). Bauart eb_http_abruf(), Einspeisebremse 0.9.26. */
if (!function_exists('ko_http_abruf')) {
    function ko_http_abruf($adresse, $ctx)
    {
        $fp = @fopen($adresse, 'r', false, $ctx);
        if ($fp === false) { return array(false, array()); }
        $meta = @stream_get_meta_data($fp);
        $inhalt = @stream_get_contents($fp);
        @fclose($fp);
        $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
            ? $meta['wrapper_data'] : array();
        return array($inhalt, $kopf);
    }
}

/** Ein JSON-RPC-Aufruf an Kodi. Rueckgabe: array(ok, ergebnis|fehlertext). */
if (!function_exists('ko_rpc')) {
    function ko_rpc($methode, $params = null, $zeit = 4)
    {
        $cfg = ko_config();
        $host = $cfg['kodi_host'] !== '' ? ko_wirt_form($cfg['kodi_host']) : '127.0.0.1';
        // Dieselbe Beurteilung wie beim Speichern: eine von Hand bearbeitete
        // kodi.json kommt am Formular vorbei.
        if (!ko_wirt_gueltig($host)) { return array(false, 'Adresse unzulaessig'); }
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
            /* KEINER UMLEITUNG FOLGEN. file_get_contents folgt ab Werk bis zu
             * zwanzigmal und schickt den Authorization-Kopf jedes Mal mit
             * (Regeln/03, "Beide Abrufwege"). Kodi leitet /jsonrpc nie um;
             * wer umleitet, ist nicht Kodi. */
            'follow_location' => 0,
            'max_redirects'   => 1,
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
        /* 'timeout' gilt nur fuer das LESEN. Fuer den Verbindungsaufbau gilt
         * default_socket_timeout, ab Werk 60 Sekunden (Regeln/03, gemessen
         * 8134 ms bei toter Gegenstelle). Deshalb fuer genau diesen Aufruf
         * gesetzt und danach zurueckgestellt - nicht dauerhaft verstellt. */
        $vorher_zeit = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string) (int) $zeit);
        set_error_handler(function () { return true; });
        list($antwort, $ko_kopfzeilen) = ko_http_abruf($adresse, $ctx);
        restore_error_handler();
        @ini_set('default_socket_timeout', (string) $vorher_zeit);

        if ($antwort === false) {
            return array(false, 'keine Antwort von ' . $host . ':' . $port);
        }
        $code = 0;
        $server = '';
        if (is_array($ko_kopfzeilen)) {
            foreach ($ko_kopfzeilen as $z) {
                if (!is_string($z)) { continue; }
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
                if (preg_match('#^Server:\s*(.+)$#i', $z, $m)) { $server = trim($m[1]); }
            }
        }
        /* WER HAT GEANTWORTET? Gemessen am 17.09.2026: auf dem LoxBerry des
         * Hauses lauscht auf 8080 nicht Kodi, sondern FOSHKplugin
         * ("Server: FOSHKplugin/v0.10 ... Python/3.13.5", HTTP 501). Ein
         * nacktes "HTTP 501" schickte den Anwender zu Kodi, das damit nichts
         * zu tun hat (Regeln/04, "Meldungen sagen, wer geantwortet hat"). */
        $wer = $server !== '' ? ' - geantwortet hat "' . substr($server, 0, 80) . '"' : '';
        if ($code === 401) {
            return array(false, 'HTTP 401 - Kodi verlangt Benutzer und Passwort' . $wer);
        }
        if ($code >= 300 && $code < 400) {
            return array(false, 'HTTP ' . $code . ' - Umleitung, der nicht gefolgt wird; Kodi leitet nicht um' . $wer);
        }
        if ($code >= 400) {
            return array(false, 'HTTP ' . $code . $wer);
        }
        $d = json_decode($antwort, true);
        if (!is_array($d)) {
            return array(false, 'Antwort ist kein JSON' . $wer . ': ' . substr(trim($antwort), 0, 120));
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
 *                  'titel' => string, 'titel_ok' => bool, 'meldung' => string)
 * "-" heisst NICHT FESTSTELLBAR und ist von "stop" verschieden: stop ist eine
 * Aussage ueber Kodi, "-" eine ueber uns.
 */
if (!function_exists('ko_kodi_zustand')) {
    function ko_kodi_zustand($zeit = 4)
    {
        /* titel_ok: der Titel wurde FESTGESTELLT (Befund M4, 29.09.2026) - auch
         * ein leerer, wenn nichts laeuft. Scheitert Player.GetItem, ist titel
         * leer und titel_ok false; der Sender schickt ihn dann fluechtig. */
        $leer = array('erreichbar' => 0, 'wiedergabe' => '-', 'titel' => '', 'titel_ok' => false,
                      'meldung' => '');
        list($ok, $r) = ko_rpc('JSONRPC.Ping', null, $zeit);
        if (!$ok) { $leer['meldung'] = (string) $r; return $leer; }
        $z = $leer;
        $z['erreichbar'] = 1;

        list($ok, $spieler) = ko_rpc('Player.GetActivePlayers', null, $zeit);
        if (!$ok) { $z['meldung'] = (string) $spieler; return $z; }
        if (!is_array($spieler) || !$spieler) {
            $z['wiedergabe'] = 'stop';
            $z['titel_ok'] = true;
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
            $z['titel_ok'] = true;
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
            /* K10 (1.2.22): 127.0.0.1 statt der LAN-Adresse. Das Addon laeuft immer
             * im oertlichen Kodi (postroot.sh spielt es nach /home/kodi ein), der
             * UDP-Eingang des Gateways ist oertlich erreichbar, und eine LAN-Adresse
             * veraltet bei einem DHCP-Wechsel; ueber IPv6 oder localhost aufgerufen
             * war sie leer. ko_lokale_ip() bleibt fuer ko_sicht_wirt(). Bestehende
             * Anlagen zeigen im Reiter MQTT einmal "abweichend", bis der Knopf
             * gedrueckt ist. */
            'mqtt_address' => '127.0.0.1',
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
            /* Ein gespeicherter Wert, den das Zurueckspielen abweisen wuerde
             * (Befund U3, 29.09.2026: Altwerte bis 1.2.6 wie "kodi+1"), kommt
             * als _hinweis in die Datei, nicht als Datenzeile - sonst wies das
             * Plugin seine EIGENE Sicherung ab, und mit ihr Passwort, Adresse
             * und alle uebrigen Werte. Umgeschrieben wird nichts: beim
             * Zurueckspielen bleibt der Schluessel auf dem Wert des
             * Zielgeraets, und die Oberflaeche nennt den Wert (ko_cfg_altwerte). */
            if (ko_wert_zurueckspielbar($k, $w) === null) {
                $t .= '# _hinweis ' . $k . ': der gespeicherte Wert "'
                    . ($k === 'kodi_pass' ? '***' : addcslashes($w, "\0..\37\177"))
                    . '" ist nicht mehr zulaessig und wurde NICHT gesichert - bitte in der '
                    . 'Oberflaeche berichtigen.' . "\n";
                continue;
            }
            $t .= $k . ' ' . ($w === '' ? '-' : ko_sicherung_wert($w)) . "\n";
        }

        /* Der Autostart nur, wenn der Helfer ihn GENANNT hat.
         *
         * Bis 1.2.6 stand hier "sonst 0". Schwieg der Helfer, schrieb die
         * Sicherung "autostart 0" - eine Aussage ueber etwas, das nicht
         * gemessen war -, und das Zurueckspielen schaltete den Autostart auf
         * dem Zielgeraet dann AKTIV AB. Fuer die Addon-Felder darunter war
         * derselbe Fall schon richtig geloest; jetzt gilt es fuer beide. */
        if (isset($st['kodiautostart'])) {
            $t .= 'autostart ' . ((int) $st['kodiautostart'] ? '1' : '0') . "\n";
        } else {
            $t .= "# Der Autostart war nicht feststellbar (der Helfer hat nicht\n"
                . "# geantwortet) und fehlt deshalb. Beim Zurueckspielen bleibt er\n"
                . "# auf dem Zielgeraet, wie er ist.\n";
        }

        $plugin_felder = array_keys(ko_addon_soll());
        foreach (ko_addon_schluessel() as $k) {
            if ($addon === null) { continue; }
            $w = isset($addon[$k]) ? (string) $addon[$k] : '';
            /* Ein Wert in einem Feld DES ANWENDERS, den das Plugin beim
             * Zurueckspielen abweisen wuerde, kommt als KOMMENTAR hinein.
             * Kodis Einstellungsdialog laesst etwa udp_port = 0 zu; stand das
             * als Datenzeile in der Datei, wies das Plugin seine EIGENE
             * Sicherung ab - und mit ihr Adresse, Thema und Passwort, die
             * damit nichts zu tun haben. Verloren geht nichts, der Kommentar
             * nennt den Wert.
             *
             * Die vier Felder DES PLUGINS bleiben Datenzeilen: ein ungueltiges
             * Thema dort ist ein Fehler, den die Rundreise-Pruefzeile zeigen
             * soll, und kein Wert, den man still beiseitelegt. */
            if ($w !== '' && !in_array($k, $plugin_felder, true) && ko_addon_wert_pruefen($k, $w) === null) {
                $t .= '# addon_' . $k . ' steht in Kodi auf "' . str_replace(array("\r", "\n"), ' ', $w)
                    . '" - das nimmt das Plugin nicht an; beim Zurueckspielen bleibt das Feld unberuehrt.' . "\n";
                continue;
            }
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

/** Gespeicherte Werte, die das Zurueckspielen abweisen wuerde (Befund U3).
 *  Rueckgabe: array(schluessel => wert). Die Oberflaeche nennt sie im Reiter
 *  Einstellungen am Knopf "Einstellungen sichern"; die Sicherung fuehrt sie
 *  nur als _hinweis. Geaendert wird nichts. */
if (!function_exists('ko_cfg_altwerte')) {
    function ko_cfg_altwerte()
    {
        $cfg = ko_config();
        $l = array();
        foreach (ko_vorgaben() as $k => $v) {
            $w = isset($cfg[$k]) ? (string) $cfg[$k] : (string) $v;
            if (ko_wert_zurueckspielbar($k, $w) === null) { $l[$k] = $w; }
        }
        return $l;
    }
}

/** X-3 (Verbesserungsbau 01.10.2026): Wuerde die EIGENE Sicherung beim
 *  Zurueckspielen abgewiesen? Sie wird gebaut (oder als $text uebergeben)
 *  und durch DIESELBE ko_sicherung_einlesen() geschickt wie beim
 *  Zurueckspielen. Rueckgabe: die NAMEN der beanstandeten Schluessel, nie
 *  Werte; leer = sie liesse sich zurueckspielen. Anders als
 *  ko_cfg_altwerte() (Werte aus kodi.json, die die Sicherung nur als Hinweis
 *  fuehrt) trifft das auch die Addon-Felder des Plugins und die Lizenzzeilen,
 *  die als Datenzeilen in der Datei stehen.
 *  Der Name traegt bewusst kein "sicherung": das Kettenwerkzeug
 *  sicherung_pruefen.py nimmt die erste Funktion *_sicherung* als Ausfuhr. */
if (!function_exists('ko_rueckspiel_befund')) {
    function ko_rueckspiel_befund($text = null)
    {
        $t = $text === null ? ko_sicherung_text() : (string) $text;
        $namen = array();
        list($erg) = ko_sicherung_einlesen($t, $namen);
        if ($erg !== null) { return array(); }
        $l = array();
        foreach ($namen as $n) { $l[] = preg_replace('/[^a-z0-9_.-]/', '?', strtolower((string) $n)); }
        $l = array_values(array_unique($l));
        return $l ? $l : array('?');
    }
}

/** X-3, angeglichen an die uebrigen Linien (Verbesserungsbau 01.10.2026;
 *  Entscheidung 13): bestuende ein gespeicherter Wert das Zurueckspielen
 *  nicht, nennt der Kopf der Datei ihn - mit dem NAMEN, nie dem Wert.
 *  Geliefert wird trotzdem vollstaendig. Die Zeile ist ein Kommentar; das
 *  Zurueckspielen ueberliest sie. Gezaehlt werden die Werte, die die
 *  Sicherung nur als _hinweis fuehrt (U3, ko_cfg_altwerte()), und alles,
 *  woran die eigene Datei beim Zurueckspielen scheitern wuerde
 *  (ko_rueckspiel_befund()). */
if (!function_exists('ko_warnkopf_setzen')) {
    function ko_warnkopf_setzen($txt)
    {
        $namen = array_values(array_unique(array_merge(array_keys(ko_cfg_altwerte()),
            ko_rueckspiel_befund($txt))));
        if (!$namen) { return (string) $txt; }
        return '# _warnung ' . str_replace(array("\r", "\n"), ' ',
            sprintf(ko_t('SICH.WARNUNG_KOPF'), implode(', ', $namen))) . "\n" . $txt;
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
    function ko_sicherung_einlesen($roh, &$namen = null)
    {
        $mangel = array();
        /* X-3 (Verbesserungsbau 01.10.2026): die NAMEN der beanstandeten
         * Schluessel, nie ihre Werte - fuer ko_rueckspiel_befund(). */
        $namen = array();
        $cfg = array();
        $addon = array();
        $lizenz = array();
        $autostart = null;
        $gefunden = 0;
        $gesehen = array();

        $vorgaben = ko_vorgaben();
        $addon_bekannt = ko_addon_schluessel();
        $lizenz_bekannt = array('lizenz_serie', 'lizenz_mpeg2', 'lizenz_vc1');

        foreach (preg_split('/\R/', (string) $roh) as $z) {
            $t = trim($z);
            if ($t === '' || $t[0] === '#') { continue; }
            $f = preg_split('/\s+/', $t);
            if (count($f) !== 2) {
                $mangel[] = sprintf(ko_t('SICH.M_ZEILE'), ko_e(substr($t, 0, 80)));
                $namen[] = substr(strtolower($f[0]), 0, 60);
                continue;
            }
            $k = strtolower($f[0]);
            /* Ein Schluessel, der zweimal dasteht, ist eine Beanstandung
             * (Befund U5, 29.09.2026) - bis 1.2.11 gewann still der letzte.
             * Geprueft am Rohtext, vor jeder Wertpruefung. */
            if (isset($gesehen[$k])) {
                $mangel[] = sprintf(ko_t('SICH.M_DOPPELT'), ko_e(substr($k, 0, 60)));
                $namen[] = substr($k, 0, 60);
                continue;
            }
            $gesehen[$k] = true;
            $w = ko_sicherung_rohwert($f[1]);
            if ($w === null) {
                $mangel[] = sprintf(ko_t('SICH.M_WERT'), ko_e($k));
                $namen[] = $k;
                continue;
            }

            if (array_key_exists($k, $vorgaben)) {
                // Strenger als das Formular: Rand und Steuerzeichen werden
                // abgewiesen, nicht gekuerzt (Befund U2).
                $gut = ko_wert_zurueckspielbar($k, $w);
                if ($gut === null) {
                    $mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($k), ko_e(substr($w, 0, 40)));
                    $namen[] = $k;
                    continue;
                }
                $cfg[$k] = $gut;
                $gefunden++;
            } elseif ($k === 'autostart') {
                if (!in_array($w, array('0', '1'), true)) {
                    $mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($k), ko_e(substr($w, 0, 40)));
                    $namen[] = $k;
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
                    $namen[] = $k;
                    continue;
                }
                $addon[$feld] = $gut;
                $gefunden++;
            } elseif (in_array($k, $lizenz_bekannt, true)) {
                // Lizenzschluessel und Seriennummer: enge Positivliste. Diese
                // Werte gehen ueber den Helfer in die BOOTKONFIGURATION.
                if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $w)) {
                    $mangel[] = sprintf(ko_t('SICH.M_UNZULAESSIG'), ko_e($k), ko_e(substr($w, 0, 40)));
                    $namen[] = $k;
                    continue;
                }
                $lizenz[$k] = $w;
                $gefunden++;
            } else {
                // Unbekannte Schluessel sind eine Beanstandung, kein stiller
                // Verlust - sonst schluckt dieses Plugin die Sicherung eines
                // anderen und meldet Erfolg.
                $mangel[] = sprintf(ko_t('SICH.M_SCHLUESSEL'), ko_e(substr($k, 0, 60)));
                $namen[] = substr($k, 0, 60);
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
            /* Das Schreiben PRUEFEN (Befund K2, 29.09.2026). Bis 1.2.11 wurde
             * auch eine leere Nebendatei umbenannt: die Seite trug ein
             * Merkmal, das nirgends stand, das naechste Absenden wurde
             * abgewiesen, und die Fehlerzeile unten entstand nie (in WSL
             * gemessen mit ulimit -f 0). */
            $ok = ftruncate($fh, 0) && fwrite($fh, $k) === strlen($k);
            $ok = fflush($fh) && $ok;
            $ok = fclose($fh) && $ok;
            if ($ok && @rename($tmp, $datei)) { $abgelegt = true; }
            else { @unlink($tmp); }
        }
        /* Laesst sich das Merkmal nicht ablegen, wuerfelt JEDER Aufruf ein
         * neues - und dann scheitert hash_equals immer, jedes Formular endet
         * in "kam nicht von dieser Seite", und die Oberflaeche ist tot, ohne
         * dass irgendwo steht warum. Das gehoert ins Protokoll - gebremst,
         * hoechstens einmal je Stunde (Regeln/03). Gesucht wird in den
         * letzten Zeilen des Protokolls selbst: ein eigener Merker laege im
         * Ordner, der gerade nicht beschreibbar ist. */
        if (!$abgelegt) {
            $ko_zuletzt = 0;
            foreach (ko_log_ende(ko_paths()['log'], 50) as $ko_z) {
                if (strpos($ko_z, 'FEHLER: das Formularmerkmal liess sich nicht') !== false
                    && preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\]/', $ko_z, $ko_m)) {
                    $ko_zuletzt = (int) strtotime($ko_m[1]);
                    break;
                }
            }
            $ko_jetzt = time();
            if ($ko_zuletzt <= 0 || ($ko_jetzt - $ko_zuletzt) >= 3600 || $ko_zuletzt > $ko_jetzt) {
                ko_log('FEHLER: das Formularmerkmal liess sich nicht nach ' . $datei
                     . ' schreiben. Solange das so bleibt, weist die Oberflaeche JEDES '
                     . 'Formular ab. Rechte des Ordners pruefen.');
            }
        }
        return $k;
    }
}

/* ================================================================
   Einmalmeldung nach dem POST (Befund U1, 29.09.2026)
   ================================================================

   Regeln/04: jeder POST-Handler endet mit einer Umleitung (303). Bis 1.2.11
   antwortete jeder Handler mit 200 und der fertigen Seite; ein Neuladen ohne
   JavaScript oder "Formular erneut senden" fuehrte Kodi starten, anhalten,
   neu starten, Addon-Einstellungen setzen, Sender jetzt und Protokoll leeren
   erneut aus (gemessen mit curl). Das Ergebnis eines Handlers liegt fuer den
   folgenden GET in data/plugins/<ordner>/einmalmeldung.json, 0600, wird dort
   gelesen UND geloescht und gilt nur 120 s. Es traegt nur Meldungstexte, nie
   ein Kennwort - die Handler nennen das Passwort nur als ***. Bauart
   eb_einmal_schreiben()/eb_einmal_lesen(), Einspeisebremse 0.9.28. */
if (!function_exists('ko_einmal_schreiben')) {
    function ko_einmal_schreiben($daten)
    {
        $daten['zeit'] = time();
        return ko_json_schreiben(ko_paths()['data'] . '/einmalmeldung.json', $daten, 0600);
    }
}

if (!function_exists('ko_einmal_lesen')) {
    function ko_einmal_lesen()
    {
        $f = ko_paths()['data'] . '/einmalmeldung.json';
        if (!is_file($f)) { return null; }
        $d = json_decode(ko_lesen($f), true);
        @unlink($f);
        if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) { return null; }
        $text = function ($s) use ($d) { return isset($d[$s]) && is_string($d[$s]) ? $d[$s] : ''; };
        /* X-2 (Verbesserungsbau 01.10.2026): die eingetippten Werte eines
         * beanstandeten Formulars. Durch kommen nur die bekannten Felder des
         * genannten Formulars - nie Passwort oder Lizenzschluessel, auch
         * wenn sie in der Datei stuenden. */
        $ein = null;
        $formulare = array(
            'settings' => array('kodi_host', 'kodi_port', 'kodi_user', 'rpc_ein', 'kodiautostart'),
            'mqtt'     => array('mqtt_topic', 'sender_takt', 'sender_ein'),
        );
        $markierbar = array('kodi_host', 'kodi_port', 'kodi_user', 'kodi_pass', 'licmpeg2', 'licvc1',
                            'mqtt_topic', 'sender_takt');
        if (isset($d['eingaben']) && is_array($d['eingaben']) && isset($d['eingaben']['formular'])
            && is_string($d['eingaben']['formular']) && isset($formulare[$d['eingaben']['formular']])) {
            $form = $d['eingaben']['formular'];
            $felder = array();
            if (isset($d['eingaben']['felder']) && is_array($d['eingaben']['felder'])) {
                foreach ($d['eingaben']['felder'] as $k => $v) {
                    if (in_array((string) $k, $formulare[$form], true) && is_string($v)) { $felder[(string) $k] = $v; }
                }
            }
            $falsch = array();
            if (isset($d['eingaben']['falsch']) && is_array($d['eingaben']['falsch'])) {
                foreach ($d['eingaben']['falsch'] as $k) {
                    if (is_string($k) && in_array($k, $markierbar, true)) { $falsch[] = $k; }
                }
            }
            $ein = array('formular' => $form, 'felder' => $felder, 'falsch' => $falsch);
        }
        return array(
            'saved'          => !empty($d['saved']),
            'note'           => $text('note'),
            'err'            => $text('err'),
            'beanstandungen' => isset($d['beanstandungen']) && is_array($d['beanstandungen'])
                                ? array_values(array_filter($d['beanstandungen'], 'is_string')) : array(),
            'raw'            => $text('raw'),
            'rawtitel'       => $text('rawtitel'),
            'eingaben'       => $ein,
        );
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
        /* Datei allein genuegt nicht: run-parts fuehrt nur AUSFUEHRBARE Dateien
         * aus. postinstall.sh prueft das seit jeher (-f UND -x), die
         * Selbstpruefung kannte bis 1.2.6 nur die Datei - zwei Pruefungen,
         * zwei Wahrheiten. Zustand 2 = Datei ohne Ausfuehrungsrecht.
         *
         * Unter Windows (Pruefstand) kennt is_executable() nur Endungen; dort
         * wird das Recht nicht beurteilt, statt ein Kreuz zu erfinden. */
        if (is_file($p['cron'])) {
            if (DIRECTORY_SEPARATOR === '/' && !is_executable($p['cron'])) {
                return array(2, $p['cron'], $reste);
            }
            return array(1, $p['cron'], $reste);
        }
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
        /* Kein Rueckfall auf den festen Namen templates/plugins/kodi_ng: bei
         * einer Zweitinstallation (kodi_ng01) ist das die Sprachdatei eines
         * ANDEREN Plugins. Bis 1.2.9 stand er hier (Fall P3). */
        // Entpacktes Archiv: die Sprachdateien liegen neben bin/. Weiter
        // hinauf wird NICHT gesucht: dirname(dirname(__DIR__)) und hoeher
        // liegen ausserhalb des Pakets, aus einem Archiv nahe der
        // Laufwerkswurzel ab / (bis 1.2.9 hier; Fall P2).
        $kandidaten[] = dirname(__DIR__) . '/templates/lang';
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

/* ko_vorlage() ist mit 1.2.22 ENTFALLEN (Entscheidung 1 vom 23.10.2026, Entwurf Gruppe 10, 2.6; Pruefbericht
 * E4): sie erzeugte die Eingaenge ueber den VirtualInHttp-Kunstgriff (Address http://localhost, PollingTime
 * 604800) - das verstoesst gegen "Gateway-Werte = Virtueller Eingang". Die Namen nennt jetzt die
 * Namenstabelle (ko_mqtt_namen(), Reiter Einbindung mit Kopierknopf). */

/**
 * Die Steuerbefehle als VirtualOut.
 *
 * 1.2.22: bleibt BYTE-GLEICH fuer bestehende Anlagen und Fremdsysteme (Entwurf Gruppe 10, 2.6/3.6, E13) - im
 * Reiter Einbindung zugeklappt; das Musterprojekt sendet ueber MQTT (ko_vorlage_mqtt_out()).
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
        // $c[0] Titel (unveraendert seit 1.2.0 - ein geaenderter Titel legt
        //       beim erneuten Import neue Ausgaenge NEBEN die alten),
        // $c[1] Sprachschluessel der Beschriftung (Abschnitt VOBEF),
        // $c[2] Befehl; traegt die Maskierung des Originals (&quot;) bereits
        //       in sich.
        return array(
            array('Input.Back', 'V_BACK', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Back&quot;, &quot;id&quot;: 1}'),
            array('Input.ContextMenu', 'V_CONTEXT', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.ContextMenu&quot;, &quot;id&quot;: 1}'),
            array('Input.Down', 'V_DOWN', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Down&quot;, &quot;id&quot;: 1}'),
            array('Input.Home', 'V_HOME', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Home&quot;, &quot;id&quot;: 1}'),
            array('Input.Info', 'V_INFO', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Info&quot;, &quot;id&quot;: 1}'),
            array('Input.Left', 'V_LEFT', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Left&quot;, &quot;id&quot;: 1}'),
            array('Input.Right', 'V_RIGHT', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Right&quot;, &quot;id&quot;: 1}'),
            array('Input.Select', 'V_SELECT', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Select&quot;, &quot;id&quot;: 1}'),
            array('Input.ShowOSD', 'V_OSD', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.ShowOSD&quot;, &quot;id&quot;: 1}'),
            array('Input.Up', 'V_UP', '{&quot;jsonrpc&quot;: &quot;2.0&quot;, &quot;method&quot;: &quot;Input.Up&quot;, &quot;id&quot;: 1}'),
            array('Player.Seek Forward', 'V_SEEK_VOR', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.Seek&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;value&quot;:&quot;smallforward&quot;},&quot;id&quot;:1}'),
            array('Player.Seek Backwards', 'V_SEEK_ZUR', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.Seek&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;value&quot;:&quot;smallbackward&quot;},&quot;id&quot;:1}'),
            array('Player.Stop', 'V_STOP', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.Stop&quot;,&quot;params&quot;:{&quot;playerid&quot;:1},&quot;id&quot;:1}'),
            array('Player.PlayPause Play', 'V_PLAY', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.PlayPause&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;play&quot;:true},&quot;id&quot;:1}'),
            array('Player.PlayPause Pause', 'V_PAUSE', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.PlayPause&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;play&quot;:false},&quot;id&quot;:1}'),
            array('Player.PlayPause Toggle', 'V_PLAYPAUSE', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.PlayPause&quot;,&quot;params&quot;:{&quot;playerid&quot;:1},&quot;id&quot;:1}'),
            array('Player.GoTo Next', 'V_NEXT', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.GoTo&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;to&quot;:&quot;next&quot;},&quot;id&quot;:1}'),
            array('Player.GoTo Previous', 'V_PREV', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Player.GoTo&quot;,&quot;params&quot;:{&quot;playerid&quot;:1,&quot;to&quot;:&quot;previous&quot;},&quot;id&quot;:1}'),
            array('Input.executeaction PageDown', 'V_PAGEDOWN', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Input.ExecuteAction&quot;,&quot;params&quot;:{&quot;action&quot;:&quot;pagedown&quot;},&quot;id&quot;:1}'),
            array('Input.executeaction PageUp', 'V_PAGEUP', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Input.ExecuteAction&quot;,&quot;params&quot;:{&quot;action&quot;:&quot;pageup&quot;},&quot;id&quot;:1}'),
            array('Application.SetVolume VolumeUp', 'V_VOL_AUF', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetVolume&quot;,&quot;params&quot;:{&quot;volume&quot;:&quot;increment&quot;},&quot;id&quot;:1}'),
            array('Application.SetVolume VolumeDown', 'V_VOL_AB', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetVolume&quot;,&quot;params&quot;:{&quot;volume&quot;:&quot;decrement&quot;},&quot;id&quot;:1}'),
            array('Application.Quit', 'V_QUIT', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.Quit&quot;,&quot;id&quot;:1}'),
            array('Application.SetMute Toggle', 'V_MUTE_UM', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetMute&quot;,&quot;id&quot;:1}'),
            array('Application.SetMute Mute', 'V_MUTE_AN', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetMute&quot;,&quot;params&quot;:{&quot;mute&quot;:true},&quot;id&quot;:1}'),
            array('Application.SetMute Unmute', 'V_MUTE_AUS', '{&quot;jsonrpc&quot;:&quot;2.0&quot;,&quot;method&quot;:&quot;Application.SetMute&quot;,&quot;params&quot;:{&quot;mute&quot;:false},&quot;id&quot;:1}'),
        );
    }
}

if (!function_exists('ko_vorlage_vo')) {
    function ko_vorlage_vo($host = null)
    {
        /* Dieselbe Adresse wie im Reiter - aus ko_steuer_wirt(). Bis 1.2.6
         * baute diese Stelle sie selbst mit preg_replace('/:.*$/') aus dem
         * Host-Kopf: aus "[fd00::1]:80" wurde "tcp://[fd00:9090", aus einem
         * leeren Kopf "tcp://:9090", und die Seite zeigte daneben eine
         * andere Adresse als die Datei enthielt. */
        if ($host === null) { $host = ko_steuer_wirt(); }
        $crlf = "\r\n";
        $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
        $o .= '<VirtualOut HintText="" Title="Kodi steuern (LoxBerry-Plugin)" '
            . 'Comment="Erzeugt vom LoxBerry-Plugin Kodi NG (' . date('d.m.Y')
            . '). JSON-RPC an Kodis TCP-Schnittstelle. Loxone Config legt beim '
            . 'Import neu an und überschreibt nichts - zweimal eingelesen ergibt '
            . 'doppelte Bausteine." Address="tcp://'
            . htmlspecialchars($host, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . ':9090" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
        $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
        foreach (ko_vo_befehle() as $c) {
            $o .= "\t" . '<VirtualOutCmd Title="'
                . htmlspecialchars($c[0], ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '" Comment="'
                /* Der Comment ist der ANZEIGENAME in Loxone Config, nicht die
                 * Dokumentation (Regeln/07). Bis 1.2.5 stand hier "" - dann
                 * zeigte Config den Titel, also 'Input.ContextMenu'. */
                . htmlspecialchars(ko_t('VOBEF.' . $c[1]), ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '" CmdOnMethod="GET" CmdOffMethod="GET" ';
            $o .= 'CmdOn="' . $c[2] . '" ';
            $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
            $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
        }
        $o .= '</VirtualOut>' . $crlf;
        return array('VQ_kodi_ng_steuern.xml', $o);
    }
}

/* ================================================================
   Gruppe 10 (1.2.22): Befehle ueber MQTT, Namenstabelle, Vorlage MQTT-Befehle
   ================================================================

   Entwurf Gruppe 10 (Musterprojekt/entwurf_gruppe10_ko.txt) Abschnitte 2.4, 3.2-3.9, Entscheidungen E1-E9 vom
   23.10.2026. Die 26 Steuerbefehle aus ko_vo_befehle() gehen zusaetzlich ueber die gemeinsame Befehlsbruecke
   (bin/befehlsbruecke.php, Stammfassung, byte-gleich): Loxone -> UDP-Eingang des MQTT-Gateways
   "publish <praefix>/befehl/<name> 1" -> Broker -> Bruecke -> ko_bb_tun() -> ko_rpc() (HTTP an Kodi, Kennwort nur
   in der Kopfzeile, Frist 3 s). Kein HTTP auf das Plugin selbst, kein Kindprozess, keine Auftragsdatei - Kodi NG
   hat keinen eigenen Dienst, und ein JSON-RPC-Aufruf ist kurz. Die TCP-Vorlage ko_vorlage_vo() bleibt byte-gleich
   (E13). */

/**
 * Je Befehl aus ko_vo_befehle() (Schluessel = Sprachschluessel $c[1], DIESELBE Reihenfolge): Brueckenname,
 * JSON-RPC-Methode, Parameter, Spieler noetig, eingreifend. EINE Tabelle neben ko_vo_befehle(); die Pruefzeile
 * "Befehlstabellen" im Reiter Test haelt beide gegeneinander.
 *
 * Spieler-Befehle holen den laufenden Spieler ueber Player.GetActivePlayers (eigener Fund F1: die TCP-Vorlage setzt
 * playerid 1 fest - nach Kodis Doku ist Musik playerid 0). Seek als Objekt {"step": ...} (F2, JSON-RPC v10 /
 * Kodi 18), SetMute mit Pflichtfeld "mute" (F3). F1-F3 sind aus der Doku geschlossen, nicht an einem Kodi gemessen.
 * Eingreifend ist nur "beenden" (E2): Kodi bleibt danach aus bis zum Start im Plugin oder zum Neustart.
 */
if (!function_exists('ko_mqtt_befehle')) {
    function ko_mqtt_befehle()
    {
        return array(
            'V_BACK'      => array('taste/zurueck', 'Input.Back', null, false, false),
            'V_CONTEXT'   => array('taste/kontextmenue', 'Input.ContextMenu', null, false, false),
            'V_DOWN'      => array('taste/ab', 'Input.Down', null, false, false),
            'V_HOME'      => array('taste/start', 'Input.Home', null, false, false),
            'V_INFO'      => array('taste/info', 'Input.Info', null, false, false),
            'V_LEFT'      => array('taste/links', 'Input.Left', null, false, false),
            'V_RIGHT'     => array('taste/rechts', 'Input.Right', null, false, false),
            'V_SELECT'    => array('taste/ok', 'Input.Select', null, false, false),
            'V_OSD'       => array('taste/leiste', 'Input.ShowOSD', null, false, false),
            'V_UP'        => array('taste/auf', 'Input.Up', null, false, false),
            'V_SEEK_VOR'  => array('wiedergabe/vorspulen', 'Player.Seek', array('value' => array('step' => 'smallforward')), true, false),
            'V_SEEK_ZUR'  => array('wiedergabe/zurueckspulen', 'Player.Seek', array('value' => array('step' => 'smallbackward')), true, false),
            'V_STOP'      => array('wiedergabe/stopp', 'Player.Stop', array(), true, false),
            'V_PLAY'      => array('wiedergabe/abspielen', 'Player.PlayPause', array('play' => true), true, false),
            'V_PAUSE'     => array('wiedergabe/pause', 'Player.PlayPause', array('play' => false), true, false),
            'V_PLAYPAUSE' => array('wiedergabe/umschalten', 'Player.PlayPause', array('play' => 'toggle'), true, false),
            'V_NEXT'      => array('wiedergabe/weiter', 'Player.GoTo', array('to' => 'next'), true, false),
            'V_PREV'      => array('wiedergabe/zurueck', 'Player.GoTo', array('to' => 'previous'), true, false),
            'V_PAGEDOWN'  => array('taste/seite_ab', 'Input.ExecuteAction', array('action' => 'pagedown'), false, false),
            'V_PAGEUP'    => array('taste/seite_auf', 'Input.ExecuteAction', array('action' => 'pageup'), false, false),
            'V_VOL_AUF'   => array('ton/lauter', 'Application.SetVolume', array('volume' => 'increment'), false, false),
            'V_VOL_AB'    => array('ton/leiser', 'Application.SetVolume', array('volume' => 'decrement'), false, false),
            'V_QUIT'      => array('beenden', 'Application.Quit', null, false, true),
            'V_MUTE_UM'   => array('ton/stumm_umschalten', 'Application.SetMute', array('mute' => 'toggle'), false, false),
            'V_MUTE_AN'   => array('ton/stumm_an', 'Application.SetMute', array('mute' => true), false, false),
            'V_MUTE_AUS'  => array('ton/stumm_aus', 'Application.SetMute', array('mute' => false), false, false),
        );
    }
}

/** Das Praefix der Linie (leer = Vorgabe kodi). */
if (!function_exists('ko_praefix')) {
    function ko_praefix($cfg = null)
    {
        if (!is_array($cfg)) { $cfg = ko_config(); }
        return (isset($cfg['mqtt_topic']) && (string) $cfg['mqtt_topic'] !== '') ? (string) $cfg['mqtt_topic'] : 'kodi';
    }
}

/** Der Name, den das MQTT-Gateway einem Thema gibt - EINE Stelle (Entwurf 2.4; bis 1.2.21 zweimal: ko_vorlage()
 *  und index.php): <praefix>/<thema>, jeder Schraegstrich wird Unterstrich. */
if (!function_exists('ko_vi_name')) {
    function ko_vi_name($thema, $cfg = null)
    {
        return str_replace('/', '_', ko_praefix($cfg) . '/' . (string) $thema);
    }
}

/** Gilt das Praefix auch fuer Befehle? Die Bruecke laesst je Ebene nur [A-Za-z0-9_-] zu (eigener Fund F4: "kodi.tv"
 *  ist als Werte-Praefix erlaubt, fuer Befehle nicht). Mit geladener Bruecke deren Pruefung, sonst dieselbe Regel. */
if (!function_exists('ko_praefix_befehle_gueltig')) {
    function ko_praefix_befehle_gueltig($p)
    {
        if (function_exists('bb_praefix_gueltig')) { return bb_praefix_gueltig($p); }
        return is_string($p) && strlen($p) <= 100
            && (bool) preg_match('#^[A-Za-z0-9_\-]{1,40}(/[A-Za-z0-9_\-]{1,40}){0,4}\z#', $p);
    }
}

/**
 * V2-Hakenliste des Musterprojekts (Praefix kodi, 10 Haken; Entwurf 2.4, E9): Thema relativ zum Praefix, Art und
 * Kennzeichen N (Noncached: kommt mit gleichem Wert wieder - status/ok jede Minute, erreichbar alle 5 Minuten, event
 * kann sich wiederholen). Resetaftersend: keiner.
 */
if (!function_exists('ko_mqtt_hakenliste')) {
    function ko_mqtt_hakenliste()
    {
        return array(
            array('status/ok', 'zahl', 'N'), array('status/zaehler', 'zahl', ''), array('dienst', 'zahl', ''),
            array('autostart', 'zahl', ''), array('erreichbar', 'zahl', 'N'),
            array('event', 'text', 'N'), array('movie_title', 'text', ''), array('episode_title', 'text', ''),
            array('music_title', 'text', ''), array('screensaver', 'text', ''),
        );
    }
}

/**
 * Die Namenstabelle - EINE Quelle fuer den Reiter Einbindung (mit Kopierknopf), den Reiter MQTT und die Pruefzeile.
 * Aus ko_themen() plus status/befehle der Bruecke. Je Zeile: thema (relativ), voll, name (Gateway), art (zahl|text),
 * quelle (plugin|addon|bruecke), retained, v2 ('N' oder ''), muster (steht so im Musterprojekt), schl (THEMA.*).
 */
if (!function_exists('ko_mqtt_namen')) {
    function ko_mqtt_namen($cfg = null)
    {
        if (!is_array($cfg)) { $cfg = ko_config(); }
        $muster = array();
        foreach (ko_mqtt_hakenliste() as $h) { $muster[$h[0]] = $h[2]; }
        $zeilen = array();
        foreach (ko_themen() as $t) {
            $zeilen[] = array($t['name'], !empty($t['zahl']) ? 'zahl' : 'text', $t['quelle'], !empty($t['retain']),
                              $t['schl'], $t['wschl']);
        }
        $zeilen[] = array('status/befehle', 'zahl', 'bruecke', false, 'T_BEFEHLE', 'V_BEFEHLE');
        $aus = array();
        foreach ($zeilen as $z) {
            $aus[] = array('thema' => $z[0], 'voll' => ko_praefix($cfg) . '/' . $z[0], 'name' => ko_vi_name($z[0], $cfg),
                           'art' => $z[1], 'quelle' => $z[2], 'retained' => $z[3],
                           'v2' => isset($muster[$z[0]]) ? $muster[$z[0]] : '', 'muster' => isset($muster[$z[0]]),
                           'schl' => $z[4], 'wschl' => $z[5]);
        }
        return $aus;
    }
}

/* ------------------------------------------------------------------
 * Befehlsbruecke: Einstellungen, Protokoll, tun()
 * ------------------------------------------------------------------ */

/**
 * Einstellungen fuer die Zuordnung. ko_config(TRUE) - eigener Fund F6: ko_config() haelt die Konfiguration statisch;
 * im Dauerprozess der Bruecke saehe sie sonst nie ein neues Kennwort, Praefix oder einen Haken. mqtt_ein: immer
 * (Kodi NG sendet ohnehin nur ueber das Gateway; status/befehle geht auch bei ausgeschaltetem Statussender).
 */
if (!function_exists('ko_bb_einstellungen')) {
    function ko_bb_einstellungen()
    {
        $c = ko_config(true);
        return array('praefix' => ko_praefix($c),
                     'annehmen' => isset($c['befehle_mqtt_ein']) && (string) $c['befehle_mqtt_ein'] === '1',
                     'eingreifend' => isset($c['befehle_mqtt_steuern']) && (string) $c['befehle_mqtt_steuern'] === '1',
                     'mqtt_ein' => true);
    }
}

/** Protokoll der Befehlsbruecke: die Protokolldatei des Plugins (ko_log), mit Stufe. */
if (!function_exists('ko_bb_protokoll')) {
    function ko_bb_protokoll($stufe, $text)
    {
        ko_log('[' . (string) $stufe . '] ' . (string) $text);
    }
}

/**
 * Der Grund fuer die Quittung aus dem Fehlertext von ko_rpc() - OHNE Adresse und ohne Antworttext (die Quittung
 * steht im Broker; Probe K des Entwurfs: der Rohtext trug Wirt:Port hinein). Den Volltext schreibt ko_bb_tun()
 * gebremst ins Protokoll.
 */
if (!function_exists('ko_bb_grund')) {
    function ko_bb_grund($text)
    {
        $t = (string) $text;
        if (strpos($t, 'Adresse unzulaessig') === 0 || strpos($t, 'Port unzulaessig') === 0) { return 'ADRESSE'; }
        if (strpos($t, 'keine Antwort') === 0) { return 'KODI_ANTWORTET_NICHT'; }
        if (preg_match('/^HTTP 401\b/', $t)) { return 'KODI_ANMELDUNG'; }
        if (preg_match('/^HTTP (\d{3})\b/', $t, $m)) { return 'KODI_HTTP_' . $m[1]; }
        return 'KODI_FEHLER';
    }
}

/**
 * tun() der Befehlsbruecke fuer einen Schluessel aus ko_mqtt_befehle() (Entwurf 3.4). Reihenfolge: Upgrade-Marke
 * (ohne Altersgrenze, wie preupgrade/postinstall entscheiden) -> AKTUALISIERUNG; Konfiguration frisch (F6); bei
 * Spieler-Befehlen Player.GetActivePlayers -> keiner -> KEIN_SPIELER; dann der Befehl. Frist je Aufruf 3 s.
 * Unabhaengig vom Abfrage-Haken rpc_ein (der schaltet nur die Abfrage des Statussenders). Rueckgabe array(bool, grund).
 */
if (!function_exists('ko_bb_tun')) {
    function ko_bb_tun($schl)
    {
        static $zuletzt = array();
        $t = ko_mqtt_befehle();
        if (!isset($t[$schl])) { return array(false, 'UNBEKANNT'); }
        list(, $methode, $params, $spieler) = $t[$schl];
        $p = ko_paths();
        if ($p['home'] !== '' && is_file($p['home'] . '/data/plugins/' . $p['plugin'] . '.upgrade_laeuft')) {
            return array(false, 'AKTUALISIERUNG');
        }
        ko_config(true);
        $fehl = function ($text) use (&$zuletzt, $schl) {
            $g = ko_bb_grund($text);
            $jetzt = time();
            if (!isset($zuletzt[$g]) || $jetzt - $zuletzt[$g] >= 60 || $zuletzt[$g] > $jetzt) {
                $zuletzt[$g] = $jetzt;
                ko_log('[WARN] Befehl ' . $schl . ' ueber MQTT: ' . $g . ' - ' . substr((string) $text, 0, 300));
            }
            return array(false, $g);
        };
        if ($spieler) {
            list($ok, $r) = ko_rpc('Player.GetActivePlayers', null, 3);
            if (!$ok) { return $fehl($r); }
            if (!is_array($r) || !$r || !isset($r[0]['playerid']) || !is_numeric($r[0]['playerid'])) {
                return array(false, 'KEIN_SPIELER');
            }
            $params = array('playerid' => (int) $r[0]['playerid']) + (array) $params;
        }
        list($ok, $r) = ko_rpc($methode, $params, 3);
        if (!$ok) { return $fehl($r); }
        return array(true, '');
    }
}

/* ------------------------------------------------------------------
 * Die Bruecke aus der Oberflaeche: laden, Liste, schalten
 * ------------------------------------------------------------------ */

/** Eine Datei unter bin/ (installiert bin/plugins/<ordner>, im Archiv <paket>/bin); '' = fehlt. Zweiter Kandidat ist
 *  der Ordner DIESER Bibliothek - sie liegt immer neben der Bruecke (auch wenn LBHOMEDIR/LBPPLUGINDIR auf eine andere
 *  Anlage zeigen, wie in den Pruefwerkzeugen). */
if (!function_exists('ko_bin_datei')) {
    function ko_bin_datei($name)
    {
        foreach (array(ko_paths()['bin'] . '/' . $name, __DIR__ . '/' . $name) as $f) {
            if (is_file($f)) { return $f; }
        }
        return '';
    }
}

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN: kein Hauptteil). */
if (!function_exists('ko_bruecke_laden')) {
    function ko_bruecke_laden()
    {
        if (function_exists('bb_waechter')) { return true; }
        $f = ko_bin_datei('befehlsbruecke.php');
        if ($f === '') { return false; }
        if (!defined('BB_NUR_LADEN')) { define('BB_NUR_LADEN', true); }
        require_once $f;
        return function_exists('bb_waechter');
    }
}

/** Die geladene und gepruefte Zuordnung: array(zuordnung|null, fehlertext). */
if (!function_exists('ko_bb_zuordnung')) {
    function ko_bb_zuordnung()
    {
        if (!ko_bruecke_laden()) { return array(null, 'befehlsbruecke.php fehlt'); }
        list($z, $f) = bb_zuordnung_laden(ko_bin_datei('befehle_zuordnung.php') !== ''
            ? ko_bin_datei('befehle_zuordnung.php') : ko_paths()['bin'] . '/befehle_zuordnung.php');
        if ($z === null) { return array(null, $f); }
        list($fehl) = bb_zuordnung_pruefen($z);
        if ($fehl) { return array(null, 'Zuordnung defekt: ' . $fehl[0]); }
        return array($z, '');
    }
}

/**
 * Direktstart bzw. -stopp der Bruecke nach dem Speichern im Reiter MQTT (Bauart Matter2Lox 0.9.44
 * mt_bruecke_schalten()): befehlsbruecke.sh start startet nur nach --soll, nie als root, nie waehrend eines Updates,
 * nie doppelt; der Waechter in cron.01min startet hoechstens alle 600 s neu. $neustart: Praefix gewechselt.
 * Rueckgabe array(''|'ein'|'aus'|'start_fehl'|'stop_fehl', Ausgabe).
 */
if (!function_exists('ko_bruecke_schalten')) {
    function ko_bruecke_schalten($neustart = false)
    {
        if (DIRECTORY_SEPARATOR === '\\' || ko_paths()['home'] === '' || !ko_bruecke_laden()) { return array('', ''); }
        $p = bb_pfade();
        if (!$p) { return array('', ''); }
        $e = ko_bb_einstellungen();
        $laeuft = (bool) bb_dienste();
        if ($e['annehmen']) {
            if ($laeuft && !$neustart) { return array('', ''); }
            if ($laeuft) { bb_sh($p, 'stop'); }
            list($rc, $aus) = bb_sh($p, 'start');
            return array(($rc === 0 && bb_dienste()) ? 'ein' : 'start_fehl', $aus);
        }
        if (!$laeuft) { return array('', ''); }
        list($rc, $aus) = bb_sh($p, 'stop');
        return array(($rc === 0 && !bb_dienste()) ? 'aus' : 'stop_fehl', $aus);
    }
}

/** Ein Satz zum Ergebnis von ko_bruecke_schalten() ('' = nichts zu sagen), maskiert. */
if (!function_exists('ko_bruecke_satz')) {
    function ko_bruecke_satz(array $r)
    {
        $a = $r[1] !== '' ? $r[1] : '-';
        switch ($r[0]) {
            case 'ein': return ko_e(sprintf(ko_t('BEFEHL.M_GESTARTET'), $a));
            case 'aus': return ko_e(sprintf(ko_t('BEFEHL.M_ANGEHALTEN'), $a));
            case 'start_fehl': return ko_e(sprintf(ko_t('BEFEHL.M_START_FEHL'), $a));
            case 'stop_fehl': return ko_e(sprintf(ko_t('BEFEHL.M_STOP_FEHL'), $a));
        }
        return '';
    }
}

/* ------------------------------------------------------------------
 * Befehlstabelle und Vorlage MQTT-Befehle (Entwurf 3.7)
 * ------------------------------------------------------------------ */

/**
 * Die 26 MQTT-Befehle in der Reihenfolge von ko_vo_befehle(): schl, titel (Anzeigename [VOBEF], "Kodi: ..."),
 * name (Brueckenname), thema (<praefix>/befehl/<name>), eingreifend. EINE Quelle fuer die Tabelle im Reiter
 * Einbindung, die Vorlage und die Bausteinliste.
 */
if (!function_exists('ko_mqtt_befehl_zeilen')) {
    function ko_mqtt_befehl_zeilen($cfg = null)
    {
        $m = ko_mqtt_befehle();
        $aus = array();
        foreach (ko_vo_befehle() as $c) {
            if (!isset($m[$c[1]])) { continue; }
            $aus[] = array('schl' => $c[1], 'titel' => (string) ko_t('VOBEF.' . $c[1]), 'name' => $m[$c[1]][0],
                           'thema' => ko_praefix($cfg) . '/befehl/' . $m[$c[1]][0], 'eingreifend' => (bool) $m[$c[1]][4]);
        }
        return $aus;
    }
}

/** Der Wirt, unter dem der Miniserver den LoxBerry erreicht (UDP-Eingang des Gateways): wie ko_steuer_wirt() der Wirt
 *  der laufenden Anfrage, sonst "loxberry" - aber nie kodi_host (der kann ein anderes Geraet sein). */
if (!function_exists('ko_udp_wirt')) {
    function ko_udp_wirt()
    {
        $w = ko_sicht_wirt();
        return $w !== '' ? $w : 'loxberry';
    }
}

/**
 * Vorlage "MQTT-Befehle" (Entwurf 3.7): VirtualOut "Kodi Befehle (MQTT)" an /dev/udp/<LoxBerry>/<UDP-Eingang des
 * Gateways>, CloseAfterSend, je Befehl ein VirtualOutCmd: Titel = Anzeigename "Kodi: ..." (wie das Musterprojekt;
 * bb_vorlage() der Stammfassung nennt "Kodi ... (MQTT)"), CmdOn "publish <praefix>/befehl/<name> 1", nie retain.
 * Kein Titel gleicht einem Gateway-Namen (Doppelpunkt und Leerzeichen). Rueckgabe array(Dateiname, Inhalt).
 */
if (!function_exists('ko_vorlage_mqtt_out')) {
    function ko_vorlage_mqtt_out($host = null)
    {
        if ($host === null) { $host = ko_udp_wirt(); }
        $port = ko_mqtt_port();
        $x = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
        $crlf = "\r\n";
        $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
        $o .= '<VirtualOut HintText="" Title="Kodi Befehle (MQTT)" Comment="'
            . $x('Erzeugt vom LoxBerry-Plugin Kodi NG (' . date('d.m.Y') . '). UDP-Eingang des MQTT-Gateways auf dem '
                . 'LoxBerry; wirkt nur mit dem Haken "Befehle ueber MQTT annehmen", "Kodi: beenden" zusaetzlich mit '
                . '"Eingreifende Befehle ueber MQTT zulassen". Loxone Config legt beim Import neu an und ueberschreibt '
                . 'nichts.')
            . '" Address="' . $x('/dev/udp/' . $host . '/' . ($port > 0 ? $port : 11884))
            . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
        $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
        foreach (ko_mqtt_befehl_zeilen() as $b) {
            $o .= "\t" . '<VirtualOutCmd Title="' . $x($b['titel']) . '" Comment="'
                . $x('Taster' . ($b['eingreifend'] ? ', eingreifend' : '') . ', nie retain') . '" CmdOnMethod="GET" '
                . 'CmdOffMethod="GET" CmdOn="' . $x('publish ' . $b['thema'] . ' 1') . '" CmdOnHTTP="" CmdOnPost="" '
                . 'CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" '
                . 'HintText=""/>' . $crlf;
        }
        $o .= '</VirtualOut>' . $crlf;
        return array('VQ_kodi_ng_mqtt.xml', $o);
    }
}

/* ------------------------------------------------------------------
 * Pruefzeilen der Umstellung (Reiter Test). Alle lesen nur.
 * Rueckgabe je array(stand, text): 1 in Ordnung, 0 Fehler, -1 Hinweis (grau).
 * ------------------------------------------------------------------ */

/** Laeuft die Befehlsbruecke? Haken aus ist ein Hinweis, kein Kreuz. Nennt status/befehle und die letzte Quittung. */
if (!function_exists('ko_pruef_bruecke')) {
    function ko_pruef_bruecke($cfg)
    {
        list($z, $fehler) = ko_bb_zuordnung();
        if ($z === null) {
            return array(0, sprintf(ko_t('TEST.A_BRUECKE_DEFEKT'), ko_e($fehler)));
        }
        if ((string) $cfg['befehle_mqtt_ein'] !== '1') {
            return array(-1, ko_t('TEST.A_BRUECKE_AUS'));
        }
        $pr = ko_praefix($cfg);
        if (!ko_praefix_befehle_gueltig($pr)) {
            return array(0, sprintf(ko_t('TEST.A_BRUECKE_PRAEFIX'), ko_e($pr)));
        }
        if (DIRECTORY_SEPARATOR === '\\' || ko_paths()['home'] === '' || !bb_pfade()) {
            return array(-1, ko_t('TEST.A_BRUECKE_ARCHIV'));
        }
        $zu = bb_zustand_lesen();
        $alter = ($zu && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
        if (!bb_dienste()) { return array(0, ko_t('TEST.A_BRUECKE_STEHT')); }
        if ($alter === null || $alter > BB_WACHE_GRENZE) {
            return array(0, sprintf(ko_t('TEST.A_BRUECKE_HAENGT'), (int) $alter));
        }
        if (empty($zu['abo'])) {
            return array(0, sprintf(ko_t('TEST.A_BRUECKE_OHNE_ABO'), ko_e(isset($zu['fehler']) ? (string) $zu['fehler'] : '')));
        }
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        $letzte = (!empty($zu['letzter']) && is_array($zu['letzter']))
            ? (string) $zu['letzter']['name'] . ': ' . (string) $zu['letzter']['ergebnis'] . ' (vor '
              . max(0, time() - (int) $zu['letzter']['zeit']) . ' s)' : '-';
        return array(1, sprintf(ko_t('TEST.A_BRUECKE_LAEUFT'),
            ko_e(date('d.m.Y H:i', isset($zu['start']) ? (int) $zu['start'] : time())),
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0,
            isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0, ko_e($letzte),
            '<span class="sm-mono">' . ko_e($pr . '/status/befehle') . '</span>'));
    }
}

/** "befehlsbruecke.php --pruefen" (mosquitto-clients, Zuordnung) als EINE Zeile. */
if (!function_exists('ko_pruef_bruecke_einrichtung')) {
    function ko_pruef_bruecke_einrichtung($cfg)
    {
        if (!ko_bruecke_laden()) { return array(0, sprintf(ko_t('TEST.A_BRUECKE_DEFEKT'), 'befehlsbruecke.php')); }
        ob_start();
        bb_pruefen();
        $aus = array_values(array_filter(explode("\n", (string) ob_get_clean()), 'strlen'));
        if (!$aus) { return array(-1, ko_t('TEST.A_BRUECKE_UNKLAR')); }
        $fehl = array_values(preg_grep('/^\[FEHL\]/', $aus));
        if ($fehl) {
            // Fehlende mosquitto-clients bei ausgeschaltetem Haken: ein Hinweis, kein Kreuz.
            return array((string) $cfg['befehle_mqtt_ein'] === '1' ? 0 : -1,
                ko_e(implode(' | ', $fehl)) . ' ' . ko_t('TEST.R_BRUECKE_EINRICHTUNG'));
        }
        return array(1, ko_e(implode(' | ', array($aus[0], end($aus)))));
    }
}

/**
 * Befehlstabellen (Entwurf 3.3): ko_mqtt_befehle() deckt die 26 Schluessel von ko_vo_befehle() in DERSELBEN
 * Reihenfolge, jeder Brueckenname einmal, genau "beenden" eingreifend; und jeder Titel der Zuordnung ist
 * "Kodi <Anzeigename ohne 'Kodi: '> (MQTT)" aus der DEUTSCHEN Sprachdatei. $zuordnung: fuer die Gegenprobe.
 */
if (!function_exists('ko_pruef_befehlstabellen')) {
    function ko_pruef_befehlstabellen($zuordnung = null)
    {
        $vo = array();
        foreach (ko_vo_befehle() as $c) { $vo[] = $c[1]; }
        $m = ko_mqtt_befehle();
        if (array_keys($m) !== $vo) { return array(0, ko_t('TEST.A_BEFTAB_REIHE')); }
        $namen = array();
        $ein = array();
        foreach ($m as $k => $e) {
            $namen[] = $e[0];
            if ($e[4]) { $ein[] = $e[0]; }
        }
        if (count(array_unique($namen)) !== count($namen) || $ein !== array('beenden')) {
            return array(0, ko_t('TEST.A_BEFTAB_NAMEN'));
        }
        if ($zuordnung === null) {
            list($zuordnung, $f) = ko_bb_zuordnung();
            if ($zuordnung === null) { return array(0, sprintf(ko_t('TEST.A_BRUECKE_DEFEKT'), ko_e($f))); }
        }
        $verz = ko_langdir();
        $de = ($verz !== '' && is_file($verz . '/language_de.ini'))
            ? @parse_ini_file($verz . '/language_de.ini', true, INI_SCANNER_RAW) : false;
        if (!is_array($de) || !isset($de['VOBEF'])) { return array(-1, ko_t('TEST.A_BEFTAB_KEIN_DE')); }
        $falsch = array();
        foreach ($m as $k => $e) {
            $soll = isset($de['VOBEF'][$k]) ? 'Kodi ' . preg_replace('/^Kodi: /', '', $de['VOBEF'][$k]) . ' (MQTT)' : '?';
            $ist = isset($zuordnung['befehle'][$e[0]]['titel']) ? $zuordnung['befehle'][$e[0]]['titel'] : '';
            if ($ist !== $soll) { $falsch[] = $e[0]; }
        }
        if (count($zuordnung['befehle']) !== count($m)) { $falsch[] = '(' . count($zuordnung['befehle']) . ')'; }
        if ($falsch) { return array(0, sprintf(ko_t('TEST.A_BEFTAB_TITEL'), ko_e(implode(', ', $falsch)))); }
        return array(1, sprintf(ko_t('TEST.A_BEFTAB_OK'), count($m)));
    }
}

/**
 * Erreicht die Bruecke Kodi? (eigener Fund F5) JSONRPC.Ping ueber denselben Weg wie die Befehle (ko_rpc, HTTP auf
 * kodi_port, Frist 3 s), mit "wer hat geantwortet". Nur mit "Befehle ueber MQTT annehmen"; 300 s zwischengespeichert
 * wie die Zeile "Antwortet Kodi?".
 */
if (!function_exists('ko_pruef_bruecke_kodi')) {
    function ko_pruef_bruecke_kodi($cfg, $erzeuger = null)
    {
        if ((string) $cfg['befehle_mqtt_ein'] !== '1') { return array(-1, ko_t('TEST.A_BRUECKE_KODI_AUS')); }
        if (ko_paths()['home'] === '') { return array(-1, ko_t('TEST.A_BRUECKE_ARCHIV')); }
        $r = $erzeuger !== null ? call_user_func($erzeuger) : ko_rpc('JSONRPC.Ping', null, 3);
        if (!empty($r[0])) {
            return array(1, sprintf(ko_t('TEST.A_BRUECKE_KODI_OK'), ko_e((string) $cfg['kodi_port'])));
        }
        return array(0, sprintf(ko_t('TEST.A_BRUECKE_KODI_FEHL'), ko_e(ko_bb_grund($r[1])), ko_e((string) $r[1])));
    }
}

/**
 * Namenstabelle (Entwurf 2.4): jeder Name ist der Gateway-Name seines Themas (Gegenseite: strtr ohne ko_vi_name()),
 * und jedes Thema ist eines aus ko_themen() oder status/befehle. $namen: Liste array(voll, name) fuer die Gegenprobe.
 */
if (!function_exists('ko_pruef_namen')) {
    function ko_pruef_namen($namen = null)
    {
        $cfg = ko_config();
        if ($namen === null) {
            $namen = array();
            foreach (ko_mqtt_namen($cfg) as $z) { $namen[] = array($z['voll'], $z['name']); }
        }
        $themen = array('status/befehle');
        foreach (ko_themen() as $t) { $themen[] = $t['name']; }
        $vor = ko_praefix($cfg) . '/';
        $falsch = array();
        foreach ($namen as $z) {
            $thema = strpos($z[0], $vor) === 0 ? substr($z[0], strlen($vor)) : '';
            if (!in_array($thema, $themen, true) || $z[1] !== strtr($z[0], array('/' => '_'))) { $falsch[] = $z[1]; }
        }
        $muster = 0;
        foreach (ko_mqtt_hakenliste() as $h) { if (in_array($h[0], $themen, true)) { $muster++; } }
        if ($muster !== count(ko_mqtt_hakenliste())) { $falsch[] = 'Hakenliste'; }
        return $falsch ? array(0, sprintf(ko_t('TEST.A_NAMEN_FEHL'), ko_e(implode(', ', $falsch))))
                       : array(1, sprintf(ko_t('TEST.A_NAMEN_OK'), count($namen), count(ko_mqtt_hakenliste())));
    }
}

/** Kodis Fernsteuerung aus dem Heimnetz (esallinterfaces, Entwurf 3.6): Haken gegen advancedsettings.xml. */
if (!function_exists('ko_pruef_fernsteuerung')) {
    function ko_pruef_fernsteuerung($cfg, $adv = false)
    {
        $soll = (string) $cfg['fernsteuerung_netz_ein'] === '1';
        if ($adv === false) { $adv = ko_adv_lesen(); }
        if ($adv === null || !isset($adv['netz']) || $adv['netz'] === '') {
            return array(-1, sprintf(ko_t('TEST.A_NETZ_UNKLAR'), $soll ? ko_t('ALLG.EIN') : ko_t('ALLG.AUS')));
        }
        $ist = $adv['netz'] === 'true';
        if ($ist === $soll) {
            return array(1, $ist ? ko_t('TEST.A_NETZ_OFFEN') : ko_t('TEST.A_NETZ_ZU'));
        }
        return array(0, sprintf(ko_t('TEST.A_NETZ_ABWEICHUNG'), $ist ? ko_t('ALLG.EIN') : ko_t('ALLG.AUS'),
            $soll ? ko_t('ALLG.EIN') : ko_t('ALLG.AUS')));
    }
}

/** Fassung des mitgelieferten Kodi-Addons (K12: 3.2.2) aus addon.xml neben default.py. */
if (!function_exists('ko_addon_fassung')) {
    function ko_addon_fassung($default_py)
    {
        $x = ko_lesen(dirname((string) $default_py) . '/addon.xml');
        if ($x !== '' && preg_match('/<addon\s[^>]*\bversion="([0-9.]+)"/', $x, $m)) { return $m[1]; }
        return '';
    }
}
