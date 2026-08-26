<?php
/**
 * Kodi NG - die Selbstpruefung und die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne Fernbedienung, ob die
 * Einrichtung traegt. Was sich nur mit Geraet pruefen liesse, wird als solches
 * benannt statt geraten - ein Strich ist kein Haken. Jedes Kreuz nennt die
 * Abhilfe mit; eine Pruefzeile, die nur "nein" sagt, hilft niemandem.
 *
 * Bis 1.1.9 hatte der Reiter Test sechs Knoepfe und KEINE einzige Pruefzeile.
 */

/** Eine Zeile der Selbstpruefung.
 *  stand:  1 = Haken, 0 = Kreuz, -1 = Strich ("nicht feststellbar" oder
 *  "trifft nicht zu"). Der Strich ist ein eigener Zustand: "ich kann es nicht
 *  messen" darf nicht aussehen wie "in Ordnung". */
function ko_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

/**
 * Ein teurer Messwert mit Ablaufdatum.
 *
 * Alle Reiter werden bei jedem Seitenaufbau mitgerendert. Eine Pruefzeile, die
 * das Netz befragt, liefe damit bei JEDEM Klick - im Fehlerfall wartet der
 * Anwender die volle Zeitschranke vor einer leeren Seite. Deshalb landet das
 * Ergebnis fuer 300 Sekunden in einer Datei.
 */
function ko_zwischenspeicher($name, $dauer, $erzeuger)
{
    $p = ko_paths();
    $datei = $p['data'] . '/cache_' . preg_replace('/[^a-z0-9_]/', '', $name) . '.json';
    $d = ko_json_lesen($datei);
    if (isset($d['ts']) && (time() - (int) $d['ts']) < $dauer && array_key_exists('wert', $d)) {
        return array($d['wert'], (int) $d['ts']);
    }
    $wert = $erzeuger();
    ko_json_schreiben($datei, array('ts' => time(), 'wert' => $wert), 0644);
    return array($wert, time());
}

/* ================================================================
   Proben, die die eigene Datei lesen
   ================================================================

   Sie melden die Zahl der ANGESEHENEN Stellen. Eine Null ist dann kein "in
   Ordnung", sondern der Hinweis, dass nichts gemessen wurde. */

/**
 * Passen Reiterliste, Reiterleiste und Bereiche zusammen?
 *
 * Verglichen werden die MENGEN, nicht die Anzahlen. Die Zaehlung faende den
 * Fall "einer fehlt"; sie faende NICHT den Fall, der genauso stumm ist: ein
 * Bereich mit einem anderen Namen. Wer eine id umbenennt und die Leiste
 * vergisst, hat weiter fuenf Bereiche und fuenf Zuweisungen - die Zahlen
 * stimmen, der Reiter fuehrt ins Leere.
 *
 * Die Liste kommt als ARGUMENT, nicht aus einem zweiten Suchlauf: sie steht
 * zur Laufzeit ohnehin da, und sie ein zweites Mal aus dem Quelltext zu lesen
 * waere eine zweite Wahrheit.
 */
function ko_reiterprobe(array $reiter, $datei)
{
    $s = ko_lesen($datei);
    // Der leere Inhalt ist ein EIGENER Ausgang. Wer eine Datei liest, um
    // darin etwas nicht zu finden, prueft zuerst, dass er ueberhaupt etwas
    // gelesen hat - sonst ist die Zeile gruen, weil sie nichts gelesen hat.
    if ($s === '') {
        return array(-1, sprintf(ko_t('TEST.A_REITER_UNLESBAR'), ko_e(basename($datei))));
    }
    $bereiche = array();
    if (preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $s, $y)) {
        $bereiche = $y[1];
    }
    $leiste = array();
    if (preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $s, $y)) {
        $leiste = array_values(array_unique($y[1]));
    }
    $fehlt = array_values(array_diff($reiter, $bereiche));
    $ueber = array_values(array_diff($bereiche, $reiter));
    $ohne_leiste = array_values(array_diff($reiter, $leiste));
    if ($fehlt) {
        return array(0, sprintf(ko_t('TEST.A_REITER_KEIN_BEREICH'), ko_e(implode(', ', $fehlt))));
    }
    if ($ueber) {
        return array(0, sprintf(ko_t('TEST.A_REITER_UNERREICHBAR'), ko_e(implode(', ', $ueber))));
    }
    if ($ohne_leiste) {
        return array(0, sprintf(ko_t('TEST.A_REITER_KEIN_LINK'), ko_e(implode(', ', $ohne_leiste))));
    }
    // Und jeder Bereich muss sm-active SERVERSEITIG bekommen koennen - sonst
    // ist die Seite ohne JavaScript vollstaendig leer, nicht etwa
    // untereinander aufgeklappt.
    $ohne_aktiv = array();
    foreach ($reiter as $id) {
        if (!preg_match('/id="' . preg_quote($id, '/') . '"/', $s)
            || substr_count($s, 'ko_aktiv(\'' . $id . '\')') < 2) {
            $ohne_aktiv[] = $id;
        }
    }
    if ($ohne_aktiv) {
        return array(0, sprintf(ko_t('TEST.A_REITER_OHNE_AKTIV'), ko_e(implode(', ', $ohne_aktiv))));
    }
    return array(1, sprintf(ko_t('TEST.A_REITER_OK'), count($reiter)));
}

/**
 * Tragen ALLE Formulare das Merkmal gegen fremde Absender?
 *
 * Der Wachposten am Eingang nuetzt nichts, wenn ein Formular das Merkmal nicht
 * mitschickt - dann tut es einfach nichts mehr, und der Anwender sucht den
 * Fehler bei sich.
 */
function ko_formularprobe($datei)
{
    $s = ko_lesen($datei);
    if ($s === '') {
        return array(-1, sprintf(ko_t('TEST.A_REITER_UNLESBAR'), ko_e(basename($datei))));
    }
    $gesamt = 0;
    $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk = substr($s, $f[1], ($ende === false ? 600 : $ende - $f[1]));
            if (strpos($blk, 'name="fmt"') === false && strpos($blk, 'ko_fmt()') === false) {
                $ohne++;
            }
        }
    }
    // Die leere Menge zuerst: "alle 0 von 0 sind in Ordnung" ist kein Haken.
    if ($gesamt === 0) { return array(0, ko_t('TEST.A_FORM_KEINS')); }
    if ($ohne > 0) { return array(0, sprintf(ko_t('TEST.A_FORM_OHNE'), $ohne, $gesamt)); }
    return array(1, sprintf(ko_t('TEST.A_FORM_OK'), $gesamt));
}

/**
 * Stimmt die Themenliste mit dem ueberein, was wirklich gesendet wird?
 *
 * Beide Seiten stehen AUSGESCHRIEBEN - ko_themen() hier und die Wertetabelle
 * im Statussender bzw. die Liste THEMEN im Kodi-Addon. Ausgeschrieben heisst:
 * sie koennen auseinanderlaufen. Genau dafuer gibt es diese Zeile.
 *
 * Bis 1.1.9 nannte die Tabelle im Reiter "Einbindung in Loxone" zwei Themen,
 * die es gar nicht gab (status, titel), waehrend die vier, die das Addon
 * wirklich sendet, fehlten. Es hat niemand gemerkt.
 */
function ko_themenprobe($senderdatei, $addondatei)
{
    $soll_plugin = array();
    $soll_addon = array();
    foreach (ko_themen() as $t) {
        if ($t['quelle'] === 'plugin') { $soll_plugin[] = $t['name']; }
        else { $soll_addon[] = $t['name']; }
    }

    $s = ko_lesen($senderdatei);
    if ($s === '') {
        return array(-1, sprintf(ko_t('TEST.A_REITER_UNLESBAR'), ko_e(basename($senderdatei))));
    }
    // Der Block $ko_werte = array( … ); im Statussender.
    $ist_plugin = array();
    if (preg_match('/\$ko_werte\s*=\s*array\((.*?)\);/s', $s, $m)) {
        if (preg_match_all('/\'([a-z_]+)\'\s*=>/', $m[1], $y)) { $ist_plugin = $y[1]; }
    }

    $a = ko_lesen($addondatei);
    $ist_addon = array();
    if ($a !== '' && preg_match('/^THEMEN\s*=\s*\((.*?)\)/ms', $a, $m)) {
        if (preg_match_all('/\'([a-z_]+)\'/', $m[1], $y)) { $ist_addon = $y[1]; }
    }

    $angesehen = count($ist_plugin) + count($ist_addon);
    if ($angesehen === 0) {
        return array(0, ko_t('TEST.A_THEMEN_NICHTS'));
    }
    $fehlt = array_merge(
        array_values(array_diff($soll_plugin, $ist_plugin)),
        array_values(array_diff($soll_addon, $ist_addon)));
    $ueber = array_merge(
        array_values(array_diff($ist_plugin, $soll_plugin)),
        array_values(array_diff($ist_addon, $soll_addon)));
    if ($fehlt) {
        return array(0, sprintf(ko_t('TEST.A_THEMEN_FEHLT'), ko_e(implode(', ', $fehlt)), $angesehen));
    }
    if ($ueber) {
        return array(0, sprintf(ko_t('TEST.A_THEMEN_UEBER'), ko_e(implode(', ', $ueber)), $angesehen));
    }
    return array(1, sprintf(ko_t('TEST.A_THEMEN_OK'), count($soll_plugin), count($soll_addon)));
}

/**
 * Steht der V1-Satz irgendwo, wo ihn keine Verzweigung mehr einfaengt?
 *
 * DER ANLASS. Der Satz "Ohne diesen Eintrag kommt am Miniserver nichts an"
 * gilt nur fuer MQTT-Gateway V1. Die Oberflaeche verzweigt danach - aber ein
 * ZWEITER Text an anderer Stelle, der ihn unbedingt behauptet, macht die
 * Verzweigung wieder zunichte: unter V2 stehen dann beide Aussagen auf
 * derselben Seite. Genau so ist es dem Vorbild dieser Umsetzung (MG iSmart
 * 1.1.0) ergangen, wo neben dem verzweigten LOX.ABO_PFLICHT ein statisches
 * MQTTR.ABO_HINWEIS stand.
 *
 * WARUM DAS KEIN WERKZEUG FINDET. Werkzeuge/gateway_fassung_reihe.py fragt:
 * steht der Satz in einer Sprachdatei UND fehlt "Gatewayversion" im Plugin?
 * Sobald ein Plugin die Fassung ueberhaupt liest, ist es dort gruen - egal
 * wie oft der Satz danach noch unbedingt auftaucht. Diese Zeile schliesst
 * genau die Luecke.
 *
 * GEMESSEN WIRD DIE SPRACHDATEI, nicht der Quelltext: dort standen die
 * Doppelungen in beiden bekannten Faellen. Erlaubt ist der Satz in genau
 * einem Schluessel je Sprache - LOX.ABO_PFLICHT. In den Hilfetexten ist er
 * ueberhaupt nicht erlaubt: die Hilfeseite ist statisches HTML und kann nicht
 * verzweigen.
 */
function ko_abosatzprobe()
{
    // Der KERN des Satzes, damit eine Umformulierung nicht durchrutscht.
    // Das Muster steht hier und nicht in einer Sprachdatei: ein Suchmuster
    // als freier Text in einer .ini findet sich selbst.
    $kern = '/(ohne\s+(diesen|den)\s+eintrag\s+kommt\s+am\s+miniserver\s+nichts\s+an'
          . '|without\s+th(is|e)\s+entry\s+nothing\s+arrives\s+at\s+the\s+miniserver)/i';
    $erlaubt = array('LOX.ABO_PFLICHT');

    $verz = ko_langdir();
    if ($verz === '') {
        return array(-1, ko_t('TEST.A_ABO_KEINE_DATEI'));
    }
    $angesehen = 0;
    $fund = array();
    foreach (array('language_de.ini', 'language_en.ini',
                   'help_de.ini', 'help_en.ini') as $datei) {
        $pfad = $verz . '/' . $datei;
        if (!is_file($pfad)) { continue; }
        $ini = @parse_ini_file($pfad, true, INI_SCANNER_RAW);
        if (!is_array($ini)) { continue; }
        foreach ($ini as $abschnitt => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $name => $wert) {
                $angesehen++;
                if (!preg_match($kern, (string) $wert)) { continue; }
                $voll = $abschnitt . '.' . $name;
                if (in_array($voll, $erlaubt, true)
                    && strpos($datei, 'language_') === 0) { continue; }
                $fund[] = $datei . ':' . $voll;
            }
        }
    }
    // Die leere Menge zuerst. "0 Fundstellen" ist nur dann ein Haken, wenn
    // ueberhaupt etwas angesehen wurde - eine Pruefung ohne Fundstellen ist
    // sonst kein Nachweis, sondern ein blinder Fleck.
    if ($angesehen === 0) { return array(0, ko_t('TEST.A_ABO_NICHTS')); }
    if ($fund) {
        return array(0, sprintf(ko_t('TEST.A_ABO_DOPPELT'),
            ko_e(implode(', ', $fund)), $angesehen));
    }
    return array(1, sprintf(ko_t('TEST.A_ABO_OK'), $angesehen));
}

/** Sind beide erzeugbaren Loxone-Vorlagen wohlgeformt? Eine kaputte Vorlage
 *  merkt der Anwender sonst erst in Loxone Config. */
function ko_vorlagenprobe()
{
    $vorher = libxml_use_internal_errors(true);
    $fehler = array();
    $anzahl = 0;
    foreach (array(ko_vorlage(), ko_vorlage_vo('pruefstand')) as $v) {
        $anzahl++;
        libxml_clear_errors();
        $x = simplexml_load_string($v[1]);
        if ($x === false) {
            $e = libxml_get_errors();
            $fehler[] = $v[0] . ': ' . (isset($e[0]) ? trim($e[0]->message) : 'nicht auswertbar');
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    if ($anzahl === 0) { return array(0, ko_t('TEST.A_VORLAGEN_KEINE')); }
    if ($fehler) { return array(0, ko_e(implode(' | ', $fehler))); }
    return array(1, sprintf(ko_t('TEST.A_VORLAGEN_OK'), $anzahl));
}

/**
 * Die Rundreise der Sicherung: schreiben, sofort wieder einlesen, vergleichen.
 *
 * Sie faengt genau die Klasse ab, an der eine fremde Linie schon einmal
 * gelitten hat: eine unvollstaendige Sicherung, weil die Vorgabenliste nicht
 * alle Schluessel fuehrte. Der Fehler faellt sonst erst auf, wenn jemand
 * zurueckspielt - also Monate spaeter und auf einem anderen Geraet.
 */
function ko_sicherungsprobe()
{
    $text = ko_sicherung_text();
    list($erg, $mangel) = ko_sicherung_einlesen($text);
    if ($erg === null) {
        return array(0, sprintf(ko_t('TEST.A_SICH_ABGELEHNT'),
            ko_e(implode(' | ', array_slice($mangel, 0, 3)))));
    }
    $cfg = ko_config();
    $ab = array();
    foreach (ko_vorgaben() as $k => $v) {
        $a = isset($cfg[$k]) ? (string) $cfg[$k] : (string) $v;
        $b = isset($erg['cfg'][$k]) ? (string) $erg['cfg'][$k] : '';
        if ($a !== $b) { $ab[] = $k; }
    }
    if ($ab) {
        return array(0, sprintf(ko_t('TEST.A_SICH_ABWEICHUNG'), ko_e(implode(', ', $ab))));
    }
    return array(1, sprintf(ko_t('TEST.A_SICH_OK'), count(ko_vorgaben()),
        substr_count($text, "\n")));
}

/* ================================================================
   Die Selbstpruefung
   ================================================================ */

function ko_pruefungen(array $reiter, $indexdatei)
{
    $p = ko_paths();
    $cfg = ko_config();
    $st = ko_status();
    $z = array();

    /* --- Der Helfer. Ohne ihn ist ueber Dienst und Autostart nichts zu
     *     sagen - und dann waere jede Zeile darunter eine Vermutung. */
    if (!$st) {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_HELFER'),
            sprintf(ko_t('TEST.A_HELFER_STUMM'), '<span class="sm-mono">sudo '
                . ko_e($p['bin']) . '/elevatedhelper.pl action=query</span>'));
    } else {
        $z[] = ko_pruefzeile(1, ko_t('TEST.F_HELFER'), ko_t('TEST.A_HELFER_OK'));
    }

    /* --- Der Kodi-Dienst. */
    if (!$st) {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_DIENST'), ko_t('TEST.A_DIENST_UNBEKANNT'));
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_AUTOSTART'), ko_t('TEST.A_DIENST_UNBEKANNT'));
    } else {
        $laeuft = !empty($st['kodistarted']);
        $z[] = ko_pruefzeile($laeuft ? 1 : 0, ko_t('TEST.F_DIENST'),
            $laeuft ? ko_t('TEST.A_DIENST_LAEUFT') : ko_t('TEST.A_DIENST_GESTOPPT'));
        $auto = !empty($st['kodiautostart']);
        // Autostart aus ist eine ENTSCHEIDUNG des Anwenders, kein Mangel -
        // deshalb ein Strich und kein Kreuz.
        $z[] = ko_pruefzeile($auto ? 1 : -1, ko_t('TEST.F_AUTOSTART'),
            $auto ? ko_t('TEST.A_AUTOSTART_EIN') : ko_t('TEST.A_AUTOSTART_AUS'));
    }

    /* --- Antwortet Kodi ueber JSON-RPC?
     *     Drei Ausgaenge, nicht zwei: geantwortet, abgelehnt, nicht
     *     feststellbar. Und die Antwort wird zwischengespeichert, sonst ruft
     *     sich der Webserver bei jedem Klick selbst durchs Netz. */
    if ((string) $cfg['rpc_ein'] !== '1') {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_KODI'), ko_t('TEST.A_KODI_AUS'));
    } else {
        list($k, $ts) = ko_zwischenspeicher('kodi_rpc', 300, function () {
            return ko_kodi_zustand(4);
        });
        $alter = time() - $ts;
        if (!is_array($k)) {
            $z[] = ko_pruefzeile(-1, ko_t('TEST.F_KODI'), ko_t('TEST.A_KODI_UNKLAR'));
        } elseif (!empty($k['erreichbar'])) {
            $z[] = ko_pruefzeile(1, ko_t('TEST.F_KODI'),
                sprintf(ko_t('TEST.A_KODI_OK'),
                    ko_e($cfg['kodi_host'] . ':' . $cfg['kodi_port']),
                    ko_e($k['wiedergabe']),
                    ko_e($k['titel'] !== '' ? $k['titel'] : '-'), (int) $alter));
        } else {
            $z[] = ko_pruefzeile(0, ko_t('TEST.F_KODI'),
                sprintf(ko_t('TEST.A_KODI_NEIN'),
                    ko_e($cfg['kodi_host'] . ':' . $cfg['kodi_port']),
                    ko_e($k['meldung'] !== '' ? $k['meldung'] : '-'), (int) $alter));
        }
    }

    /* --- Der eigene Cron-Eintrag.
     *     Ein Plugin, das einen Cron-Dienst ausliefert, prueft selbst, ob der
     *     Eintrag da ist UND ob er eine Datei ist. Ein Verzeichnis an dieser
     *     Stelle wird von LoxBerry nicht ausgefuehrt, und das Plugin stuende
     *     vollstaendig installiert da und taete nichts. */
    list($cstand, $cpfad, $creste) = ko_cron_lage();
    if ($cstand === -1) {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_CRON'), ko_t('TEST.A_CRON_UNBEKANNT'));
    } elseif ($cstand === 1) {
        $z[] = ko_pruefzeile(1, ko_t('TEST.F_CRON'),
            sprintf(ko_t('TEST.A_CRON_OK'), '<span class="sm-mono">' . ko_e($cpfad) . '</span>')
            . ($creste ? ' ' . sprintf(ko_t('TEST.A_CRON_RESTE'), ko_e(implode(', ', $creste))) : ''));
    } elseif ($cpfad !== '') {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_CRON'),
            sprintf(ko_t('TEST.A_CRON_VERZEICHNIS'), '<span class="sm-mono">' . ko_e($cpfad) . '</span>'));
    } else {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_CRON'),
            sprintf(ko_t('TEST.A_CRON_FEHLT'), '<span class="sm-mono">' . ko_e($p['cron']) . '</span>')
            . ($creste ? ' ' . sprintf(ko_t('TEST.A_CRON_RESTE'), ko_e(implode(', ', $creste))) : ''));
    }

    /* --- Der Statussender: ist er an, und hat er zugestellt?
     *     Ueber einen Sender, der gar nicht laufen soll, wird kein Herzschlag
     *     beurteilt - das gaebe ein Kreuz, das nichts bedeutet. */
    $zu = ko_json_lesen($p['zustand']);
    if ((string) $cfg['sender_ein'] !== '1') {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_SENDER'), ko_t('TEST.A_SENDER_AUS'));
    } elseif (!isset($zu['gesendet_ts'])) {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_SENDER'), ko_t('TEST.A_SENDER_NIE'));
    } else {
        $alter = time() - (int) $zu['gesendet_ts'];
        // Zwei Takte Luft: ein einzelner uebersprungener Lauf ist kein Defekt.
        $grenze = 2 * max(60, (int) $cfg['sender_takt']) + 60;
        $z[] = ko_pruefzeile($alter <= $grenze ? 1 : 0, ko_t('TEST.F_SENDER'),
            sprintf($alter <= $grenze ? ko_t('TEST.A_SENDER_OK') : ko_t('TEST.A_SENDER_ALT'),
                (int) $alter, (int) $grenze,
                isset($zu['herzschlag']) ? (int) $zu['herzschlag'] : 0));
    }

    /* --- Die Lage der Konfiguration. Vier Zustaende, vier Saetze - ein
     *     Zustand ohne Satz ist einer, den der Anwender nie erfaehrt. */
    $lage = ko_config_lage();
    if ($lage === 'kaputt') {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_KONFIG'),
            sprintf(ko_t('TEST.A_KONFIG_KAPUTT'),
                '<span class="sm-mono">' . ko_e(basename($p['config']) . '.kaputt') . '</span>'));
    } elseif ($lage === 'zweitschrift') {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_KONFIG'), ko_t('TEST.A_KONFIG_ZWEITSCHRIFT'));
    } elseif ($lage === 'leer') {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_KONFIG'), ko_t('TEST.A_KONFIG_LEER'));
    } else {
        $z[] = ko_pruefzeile(1, ko_t('TEST.F_KONFIG'),
            is_file($p['zweit'])
                ? sprintf(ko_t('TEST.A_KONFIG_OK'), date('d.m.Y H:i', (int) @filemtime($p['zweit'])))
                : ko_t('TEST.A_KONFIG_OHNE_ZWEITSCHRIFT'));
    }

    /* --- Vollstaendig? Und steht Fremdes darin?
     *     Fehlend heisst: es gilt die Vorgabe. Fremd heisst: der Eintrag wirkt
     *     nicht - und genau das ueberrascht. Geloescht wird Fremdes NICHT;
     *     niemand weiss, ob dort der Rest einer aelteren Fassung steht. */
    $cl = ko_cfg_lage();
    if (!empty($cl['kaputt'])) {
        /* Bei einer beschaedigten Datei ueber die Vollstaendigkeit zu urteilen
         * hiesse "es fehlen 8 von 8" - eine Zahl, die stimmt und das Falsche
         * sagt. Den wahren Zustand nennt die Zeile darueber. */
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_VOLLST'), ko_t('TEST.A_VOLLST_KAPUTT'));
    } elseif ($lage === 'leer') {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_VOLLST'), ko_t('TEST.A_VOLLST_LEER'));
    } elseif ($cl['fehlend']) {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_VOLLST'),
            sprintf(ko_t('TEST.A_VOLLST_FEHLT'), count($cl['fehlend']), $cl['anzahl'],
                ko_e(implode(', ', $cl['fehlend']))));
    } elseif ($cl['fremd']) {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_VOLLST'),
            sprintf(ko_t('TEST.A_VOLLST_FREMD'), ko_e(implode(', ', $cl['fremd']))));
    } else {
        $z[] = ko_pruefzeile(1, ko_t('TEST.F_VOLLST'),
            sprintf(ko_t('TEST.A_VOLLST_OK'), $cl['anzahl']));
    }

    /* --- Das MQTT-Gateway: Autostart, Fassung und UDP-Eingang aus EINEM
     *     Dateizugriff. Ein Broker, der laeuft, und ein Gateway, das nicht
     *     startet, sind verschiedene Dinge. */
    $gw = ko_mqtt_gateway_info();
    if ($gw === null) {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_GATEWAY'), ko_t('TEST.A_GATEWAY_UNBEKANNT'));
    } elseif (!$gw['autostart']) {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_GATEWAY'), ko_t('TEST.A_GATEWAY_AUS'));
    } elseif ((int) $gw['udpinport'] < 1) {
        $z[] = ko_pruefzeile(0, ko_t('TEST.F_GATEWAY'), ko_t('TEST.A_GATEWAY_KEIN_UDP'));
    } else {
        $z[] = ko_pruefzeile(1, ko_t('TEST.F_GATEWAY'),
            sprintf(ko_t('TEST.A_GATEWAY_OK'), (int) $gw['udpinport'],
                $gw['fassung'] > 0 ? 'V' . (int) $gw['fassung'] : ko_t('TEST.A_GATEWAY_FASSUNG_UNBEKANNT')));
    }

    /* --- Das Kodi-Addon: dieselben vier Werte auf beiden Seiten?
     *     Das MQTT-Thema stand bis 1.1.9 im Plugin und im Addon getrennt. Wer
     *     es im Plugin aenderte, bekam eine Themen-Tabelle auf den neuen
     *     Namen, waehrend das Addon weiter unter dem alten sendete. */
    $addon = ko_addon_lesen();
    if ($addon === null) {
        $z[] = ko_pruefzeile(-1, ko_t('TEST.F_ADDON'), ko_t('TEST.A_ADDON_KEINE_DATEI'));
    } else {
        $soll = ko_addon_soll();
        $ab = array();
        foreach ($soll as $k => $v) {
            $ist = isset($addon[$k]) ? (string) $addon[$k] : '';
            if ($v === '' ) { continue; }   // was wir selbst nicht wissen, vergleichen wir nicht
            if ($ist !== (string) $v) { $ab[] = $k . ': ' . ($ist === '' ? '-' : $ist) . ' statt ' . $v; }
        }
        if ($ab) {
            $z[] = ko_pruefzeile(0, ko_t('TEST.F_ADDON'),
                sprintf(ko_t('TEST.A_ADDON_ABWEICHUNG'), ko_e(implode('; ', $ab))));
        } else {
            $z[] = ko_pruefzeile(1, ko_t('TEST.F_ADDON'),
                sprintf(ko_t('TEST.A_ADDON_OK'), count($soll)));
        }
    }

    /* --- Die Proben ueber die eigenen Dateien. */
    list($s, $t) = ko_themenprobe($p['bin'] . '/kodi_ng_status.php', ko_addon_quelle());
    $z[] = ko_pruefzeile($s, ko_t('TEST.F_THEMEN'), $t);

    list($s, $t) = ko_abosatzprobe();
    $z[] = ko_pruefzeile($s, ko_t('TEST.F_ABOSATZ'), $t);

    list($s, $t) = ko_reiterprobe($reiter, $indexdatei);
    $z[] = ko_pruefzeile($s, ko_t('TEST.F_REITER'), $t);

    list($s, $t) = ko_formularprobe($indexdatei);
    $z[] = ko_pruefzeile($s, ko_t('TEST.F_FORMULARE'), $t);

    list($s, $t) = ko_vorlagenprobe();
    $z[] = ko_pruefzeile($s, ko_t('TEST.F_VORLAGEN'), $t);

    list($s, $t) = ko_sicherungsprobe();
    $z[] = ko_pruefzeile($s, ko_t('TEST.F_SICHERUNG'), $t);

    return $z;
}

/** Wo das mitgelieferte Addon liegt - fuer die Themenprobe. */
function ko_addon_quelle()
{
    $p = ko_paths();
    $kandidaten = array(
        $p['data'] . '/addons/service.callback.handler/default.py',
        dirname(dirname(__DIR__)) . '/data/addons/service.callback.handler/default.py',
        dirname(dirname(dirname(__DIR__))) . '/data/addons/service.callback.handler/default.py',
    );
    foreach ($kandidaten as $k) {
        if (is_file($k)) { return $k; }
    }
    return $kandidaten[0];
}

/* ================================================================
   Die Aktionen des Reiters Test
   ================================================================

   Rueckgabe: array(ueberschrift, text). Der Text wird ROH in einen
   <div class="sm-pre"> gelegt und ist deshalb schon hier maskiert. */

function ko_test_aktion($was)
{
    $p = ko_paths();
    $cfg = ko_config();

    switch ($was) {
        case 'rohdaten':
            $r = ko_helper('action=query');
            return array(ko_t('TEST.K_ROHDATEN'),
                ko_e($r !== '' ? $r : ko_t('TEST.A_KEINE_AUSGABE')));

        case 'dienstzustand':
            $r = (string) @shell_exec('systemctl status kodi_ng --no-pager 2>&1');
            return array(ko_t('TEST.K_DIENSTZUSTAND'),
                ko_e($r !== '' ? $r : ko_t('TEST.A_KEINE_AUSGABE')));

        case 'kodiping':
            /* Ein echter Aufruf, ohne Zwischenspeicher - der Knopf ist genau
             * dafuer da.
             *
             * DREI EBENEN, und eine Antwort beantwortet nur zwei davon:
             *   Netz       antwortet ueberhaupt jemand auf dem Port?
             *   Anmeldung  laesst Kodi uns herein?
             * "erreichbar" heisst BEIDES - ein Kodi, das mit HTTP 401
             * antwortet, ist da und nuetzt uns trotzdem nichts. Bis zur
             * zweiten Sicht stand hier "drei Ausgaenge" im Kommentar, und die
             * Anzeige kannte nur zwei; die Ablehnung steckte allein im
             * Meldungstext. Jetzt wird sie benannt. */
            $z = ko_kodi_zustand(6);
            $abgelehnt = (strpos($z['meldung'], '401') !== false);
            $t  = 'Adresse   : http://' . $cfg['kodi_host'] . ':' . $cfg['kodi_port'] . "/jsonrpc\n";
            $t .= 'erreichbar: ' . ($z['erreichbar'] ? 'ja'
                  : ($abgelehnt ? 'nein - Kodi antwortet, laesst uns aber nicht herein'
                                : 'nein')) . "\n";
            $t .= 'Wiedergabe: ' . $z['wiedergabe'] . "\n";
            $t .= 'Titel     : ' . ($z['titel'] !== '' ? $z['titel'] : '-') . "\n";
            if ($z['meldung'] !== '') { $t .= 'Meldung   : ' . $z['meldung'] . "\n"; }
            return array(ko_t('TEST.K_KODIPING'), ko_e($t));

        case 'addonzeigen':
            $roh = ko_helper('action=addonread');
            $j = json_decode($roh, true);
            if (!is_array($j) || empty($j['status']) || $j['status'] !== 'OK') {
                return array(ko_t('TEST.K_ADDONZEIGEN'),
                    ko_e(ko_t('TEST.A_ADDON_KEINE_DATEI') . "\n\n" . $roh));
            }
            $t = ko_t('TEST.A_ADDON_DATEI') . ' ' . (isset($j['datei']) ? $j['datei'] : '?') . "\n\n";
            $soll = ko_addon_soll();
            foreach (ko_addon_schluessel() as $k) {
                $ist = isset($j['werte'][$k]) ? (string) $j['werte'][$k] : '';
                $t .= sprintf("%-16s %-24s", $k, $ist === '' ? '-' : $ist);
                if (isset($soll[$k]) && $soll[$k] !== '') {
                    $t .= ($ist === (string) $soll[$k]) ? '   (= Plugin)' : '   (Plugin: ' . $soll[$k] . ')';
                }
                $t .= "\n";
            }
            // Schluessel, die dieses Plugin nicht kennt - genannt, nicht
            // geloescht: sie koennten zu einer neueren Fassung des Addons
            // gehoeren.
            $fremd = array_diff(array_keys(isset($j['werte']) ? (array) $j['werte'] : array()),
                                ko_addon_schluessel());
            if ($fremd) {
                $t .= "\n" . sprintf(ko_t('TEST.A_ADDON_FREMD'), implode(', ', $fremd)) . "\n";
            }
            return array(ko_t('TEST.K_ADDONZEIGEN'), ko_e($t));

        case 'trockenlauf':
            /* Der Trockenlauf laeuft durch DENSELBEN Code wie der echte Lauf -
             * er redet nur anders. Ein Trockenlauf, der eine eigene Rechnung
             * anstellt, prueft sich selbst und nicht den Dienst. */
            $skript = $p['bin'] . '/kodi_ng_status.php';
            if (!is_file($skript)) {
                return array(ko_t('TEST.K_TROCKEN'),
                    ko_e(sprintf(ko_t('TEST.A_SENDER_FEHLT'), $skript)));
            }
            $php = trim((string) @shell_exec('command -v php 2>/dev/null'));
            if ($php === '') {
                return array(ko_t('TEST.K_TROCKEN'), ko_e(ko_t('TEST.A_KEIN_PHP')));
            }
            $r = (string) @shell_exec(escapeshellcmd($php) . ' ' . escapeshellarg($skript)
                . ' --jetzt --trocken 2>&1');
            return array(ko_t('TEST.K_TROCKEN'),
                ko_e($r !== '' ? $r : ko_t('TEST.A_KEINE_AUSGABE')));

        case 'senderjetzt':
            /* Ist der Sender aus, tut das Skript nichts - und zwar zu Recht:
             * --jetzt uebergeht seit der zweiten Sicht nur den Takt, nicht
             * den Schalter. Ein Knopf, der daraufhin schweigt, schickt den
             * Anwender auf die Suche nach einem Fehler, den es nicht gibt. */
            if ((string) $cfg['sender_ein'] !== '1') {
                return array(ko_t('TEST.K_SENDERJETZT'), ko_e(ko_t('TEST.A_SENDER_AUS_KNOPF')));
            }
            $skript = $p['bin'] . '/kodi_ng_status.php';
            if (!is_file($skript)) {
                return array(ko_t('TEST.K_SENDERJETZT'),
                    ko_e(sprintf(ko_t('TEST.A_SENDER_FEHLT'), $skript)));
            }
            $php = trim((string) @shell_exec('command -v php 2>/dev/null'));
            if ($php === '') {
                return array(ko_t('TEST.K_SENDERJETZT'), ko_e(ko_t('TEST.A_KEIN_PHP')));
            }
            $r = (string) @shell_exec(escapeshellcmd($php) . ' ' . escapeshellarg($skript)
                . ' --jetzt 2>&1; echo "RC=$?"');
            /* Der RUECKGABEWERT wird mitgemessen, nicht nur der Text. Das
             * Skript schweigt im Erfolgsfall - wer nur den Text auswertet,
             * sieht ein gelungenes und ein stumm gescheitertes Ergebnis
             * gleich aussehen. */
            return array(ko_t('TEST.K_SENDERJETZT'),
                ko_e($r !== '' ? $r : ko_t('TEST.A_KEINE_AUSGABE')));
    }
    return array('', '');
}
