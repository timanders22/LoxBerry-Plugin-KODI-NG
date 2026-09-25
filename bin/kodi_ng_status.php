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
 *     php kodi_ng_status.php --jetzt      Takt uebergehen, sofort alles senden
 *     php kodi_ng_status.php --trocken    alles messen, NICHTS senden
 *     php kodi_ng_status.php --mqtt-leeren  zurueckbehaltene Themen leeren
 *                                           (ruft die Deinstallation)
 *
 * --jetzt uebergeht den TAKT und NICHT den Schalter: wer den Sender
 * ausgeschaltet hat, will keine Themen im Broker haben. --trocken misst in
 * jeder Lage; es sendet ohnehin nichts.
 *
 * ZWEI GESCHWINDIGKEITEN (seit 1.2.7)
 *   jeder Cron-Durchgang   das Lebenszeichen: zeitstempel, herzschlag,
 *                          status/ok - nie retained (Regeln/07, Hausstandard
 *                          seit 26.08.2026: "ts geht bei jedem Durchgang
 *                          hinaus"). status/ok ging von 1.2.7 bis 1.2.9
 *                          entgegen diesem Satz retained hinaus (Tabelle in
 *                          ko_themen(); Klasse-E-Liste 19.09.2026).
 *   alle sender_takt s     zusaetzlich die Zustaende: dienst, autostart und
 *                          mit JSON-RPC wiedergabe, titel - retained; dazu
 *                          erreichbar - NIE retained, denn es ist der Erfolg
 *                          des eigenen JSON-RPC-Pings, eine Aussage des
 *                          Dienstes ueber sich selbst (Regeln/07, 19.09.2026)
 * Bis 1.2.6 ging alles zusammen im Takt hinaus, und alles retained.
 *
 * WANN EIN LAUF GELUNGEN IST (seit 1.2.7)
 * Bis 1.2.6 hiess es "gelungen", sobald EIN Wert hinausging. zeitstempel und
 * herzschlag sind nie leer - also war jeder Lauf gelungen, auch bei totem
 * Helfer, und der Reiter Test zeigte einen gruenen Haken mit steigendem
 * Herzschlag. Jetzt gilt: der Helfer hat geantwortet UND jede gebaute Zeile
 * wurde dem Kern uebergeben.
 *
 * $_GET wird unter der Kommandozeile nicht aus QUERY_STRING gefuellt - die
 * Schalter kommen deshalb aus $argv.
 *
 * IM NORMALFALL SCHWEIGT DIESES SKRIPT. Stoerungen schreibt es in die
 * Protokolldatei des Plugins - gebremst: dieselbe Meldung hoechstens einmal
 * je Stunde (Regeln/03). Bis 1.2.6 stand hier, die Fehlerausgabe lande im
 * Systemlogger; sie landete nirgends, denn ohne Mailserver wirft cron sie
 * weg, und der Takt griff im Fehlerfall nicht, sodass die Meldung jede Minute
 * kam und das Protokoll leerraeumte.
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
$ko_von_hand = $ko_jetzt || $ko_trocken;

$ko_p = ko_paths();

/* OHNE WURZEL ODER AUS EINEM AUSGEPACKTEN ARCHIV: NICHTS TUN.
 *
 * ko_paths() liefert dann home = '' (Archivmodus). Bis 1.2.9 lief dieser
 * Sender aus einem Archiv unterhalb der Anlage mit deren Konfiguration los,
 * sendete unter ihrem Praefix an ihren UDP-Eingang und schrieb ihre
 * Zustandsdatei; in einem fremden Baum ohne general.json schrieb er dort (in
 * WSL gemessen, Pruefung-KODI-NG-1.2.10, Faelle A1, A2, W1). Die Zeile steht
 * VOR allem, was liest oder schreibt. */
if ($ko_p['home'] === '') {
    if (!empty($ko_p['archiv'])) {
        fwrite(STDERR, "Kodi NG: Diese Datei liegt nicht in der Installation unter " . $ko_p['archiv'] . "\n"
            . "(ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt, wurde\n"
            . "nichts gesendet und nichts geschrieben. Abhilfe: den Sender aus\n"
            . $ko_p['archiv'] . "/bin/plugins/<ordner> aufrufen oder LBHOMEDIR und LBPPLUGINDIR setzen.\n");
    } else {
        fwrite(STDERR, "Kodi NG: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden. LBHOMEDIR ist nicht\n"
            . "gesetzt, und oberhalb von " . __DIR__ . " traegt kein Verzeichnis config/plugins,\n"
            . "data/plugins und config/system/general.json. Es wurde nichts gesendet und nichts geschrieben.\n");
    }
    exit(1);
}
/* --mqtt-leeren: die zurueckbehaltenen Themen leeren - fuer
 * uninstall/uninstall. Unabhaengig vom Schalter sender_ein: auch der Knopf
 * "Test-Ereignis" im Reiter Test sendet retained, bei ausgeschaltetem Sender.
 * Schreibt kein Protokoll und keinen Zustand (ko_mqtt_leeren()). */
if (in_array('--mqtt-leeren', $ko_argv, true)) {
    exit(ko_mqtt_leeren());
}

$ko_cfg = ko_config();

/* Ist der Sender ausgeschaltet, hat dieser Lauf nichts zu tun.
 *
 * Ab Werk ist er AUS. Ein Update darf einer bestehenden Anlage nicht ungefragt
 * Themen in den Broker legen; der Reiter Test sagt deutlich, dass er aus ist,
 * damit niemand vergeblich auf Werte wartet. */
if ((string) $ko_cfg['sender_ein'] !== '1' && !$ko_trocken) {
    if ($ko_jetzt) {
        echo "Kodi NG: der Statussender ist ausgeschaltet - es wurde nichts gesendet.\n";
    }
    exit(0);
}

/* Eine Sperre, und zwar EINE fuer alle Laeufe.
 *
 * Der Cron startet minuetlich. Ein Lauf mit vier JSON-RPC-Aufrufen und je vier
 * Sekunden Zeitschranke kann laenger dauern; ohne Sperre lauefen dann zwei
 * uebereinander und schreiben sich die Zustandsdatei kaputt. */
$ko_sperre = $ko_p['data'] . '/kodi_ng_status.lock';
if (!is_dir(dirname($ko_sperre))) { @mkdir(dirname($ko_sperre), 0775, true); }
$ko_lock = @fopen($ko_sperre, 'c');
if ($ko_lock === false) {
    ko_log('Statussender: Sperrdatei ' . $ko_sperre . ' liess sich nicht anlegen.');
    fwrite(STDERR, "Kodi NG: Sperrdatei " . $ko_sperre . " liess sich nicht anlegen.\n");
    exit(1);
}
if (!flock($ko_lock, LOCK_EX | LOCK_NB)) {
    /* Ein Lauf ist noch unterwegs. Aus dem Cron ist das keine Zeile wert
     * (Regeln/03: "wer nicht drankommt, geht kommentarlos"). Von Hand
     * gerufen wuerde ein wortloses Ende aber wie ein Defekt aussehen. */
    if ($ko_von_hand) {
        echo "Kodi NG: ein anderer Lauf des Statussenders ist gerade unterwegs - bitte gleich noch einmal.\n";
    }
    fclose($ko_lock);
    exit(0);
}

function ko_sender_ende($lock, $rc)
{
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($rc);
}

$ko_zustand = ko_json_lesen($ko_p['zustand']);
$ko_takt    = (int) $ko_cfg['sender_takt'];
if ($ko_takt < 60) { $ko_takt = 60; }
$ko_jetzt_ts = time();

/* DER TAKT HAENGT AM VERSUCH, NICHT AM ERFOLG.
 *
 * Bis 1.2.6 pruefte die Schranke gesendet_ts, und das wurde nur bei Erfolg
 * geschrieben. Scheiterte das Senden, griff die Schranke nie, und der volle
 * Lauf kam jede Minute statt alle sender_takt Sekunden - mit drei
 * Protokollzeilen je Lauf. Jetzt tragen zwei Stempel zwei Aufgaben:
 *   versuch_ts   wann der letzte volle Lauf VERSUCHT wurde  -> der Takt
 *   gesendet_ts  wann er zuletzt GELANG                     -> der Reiter Test
 * Aeltere Zustandsdateien kennen versuch_ts nicht; dann gilt gesendet_ts.
 *
 * Fuenf Sekunden Nachsicht: der Cron feuert zur vollen Minute, time() kann
 * eine Sekunde spaeter liegen. Ohne sie waere die Differenz beim faelligen
 * Lauf 299 < 300, und der Takt schwankte zwischen fuenf und sechs Minuten. */
$ko_letzter_versuch = isset($ko_zustand['versuch_ts']) ? (int) $ko_zustand['versuch_ts']
    : (isset($ko_zustand['gesendet_ts']) ? (int) $ko_zustand['gesendet_ts'] : 0);
$ko_faellig = $ko_von_hand || $ko_letzter_versuch <= 0
    || ($ko_jetzt_ts - $ko_letzter_versuch) >= ($ko_takt - 5)
    || $ko_letzter_versuch > $ko_jetzt_ts;   // Uhr zurueckgestellt: nicht ewig warten

/* ---------- messen ---------- */

$ko_st = ko_status();
$ko_helfer = (bool) $ko_st;
$ko_dienst    = isset($ko_st['kodistarted'])   ? (int) $ko_st['kodistarted']   : null;
$ko_autostart = isset($ko_st['kodiautostart']) ? (int) $ko_st['kodiautostart'] : null;

$ko_erreichbar = null;
$ko_wiedergabe = null;
$ko_titel      = null;
$ko_rpc_meldung = '';

if ($ko_faellig && (string) $ko_cfg['rpc_ein'] === '1') {
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
 * Uhr des LoxBerry gestellt, springt es. Der Zaehler zaehlt Zustellungen an
 * den Kern, nicht Schleifendurchlaeufe: er steigt erst NACH der Uebergabe.
 *
 * Die Zustaende gehen nur im Takt hinaus; ausserhalb stehen sie auf null und
 * werden uebersprungen. Die Liste steht trotzdem VOLLSTAENDIG hier - die
 * Pruefzeile "Themenliste" im Reiter Test liest genau diesen Block. */
$ko_werte = array(
    'dienst'      => $ko_faellig ? $ko_dienst : null,
    'autostart'   => $ko_faellig ? $ko_autostart : null,
    'erreichbar'  => $ko_erreichbar,
    'wiedergabe'  => $ko_wiedergabe,
    'titel'       => $ko_titel,
    'zeitstempel' => $ko_jetzt_ts,
    'herzschlag'  => $ko_zaehler + 1,
    'status/ok'   => $ko_helfer ? 1 : 0,
);

/* ALTWERTE ABRAEUMEN - bis der BROKER bestaetigt, dass nichts mehr dasteht.
 *
 * Themen, die heute nicht mehr retained hinausgehen (ko_mqtt_altlast_liste():
 * zeitstempel und herzschlag bis 1.2.6, status/ok von 1.2.7 bis 1.2.9,
 * erreichbar bis 1.2.9), stuenden sonst mit ihrem letzten Wert fuer immer im
 * Broker - ein spaeteres publish ersetzt einen zurueckbehaltenen Wert nicht.
 *
 * Bis 1.2.9 wurde einmal geloescht und danach ein Merker gesetzt, gestuetzt
 * auf den Erfolg von fwrite() am UDP-Eingang. Der meldet auch fuer ein
 * verworfenes Datagramm Erfolg; am Geraet belegt (Regeln/07, Nachtrag
 * 19.09.2026: genau diese Linie und Beschattungswaechter 0.9.19): Merker
 * gesetzt, Altwert stand weiter im Broker. Jetzt entscheidet der Broker
 * selbst (ko_mqtt_altlast(), eigenes MQTT-3.1.1-Abonnement mit den
 * Zugangsdaten aus general.json, Bauart Spotpreis-Tibber 0.9.19):
 *   erledigt   er hat bestaetigt, dass keines der Themen mehr dasteht -
 *              nur dann liegt der Merker, und dieser Lauf raeumt nichts ab;
 *   belegt     genau diese Themen stehen noch - sie werden abgeraeumt, kein
 *              Merker, der naechste Lauf fragt wieder;
 *   unbekannt  er war nicht zu fragen - dann KEIN Merker, und in JEDEM Lauf
 *              wird jedes Thema abgeraeumt, das in diesem Lauf einen Wert
 *              bekommt (gebremst im Protokoll).
 * Die leere retain-Nutzlast steht dabei UNMITTELBAR vor dem gueltigen Wert
 * desselben Themas (ko_mqtt_publish()). Ein Thema ohne Wert in diesem Lauf
 * wird nur geloescht, wenn der Broker es als belegt gemeldet hat und der
 * Lauf voll ist (erreichbar bei ausgeschaltetem JSON-RPC bekommt nie wieder
 * einen Wert); im Lebenszeichenlauf nie - dort kaeme am Miniserver ein leerer
 * Wert an, dem nichts folgt (Regeln/07). Bis 1.2.9 geschah das Loeschen im
 * ersten Lauf ueberhaupt, als Block vor allen Werten (Faelle R6, R15). */
$ko_abraeumen = array();
$ko_altlage = '';
if (!$ko_trocken) {
    $ko_alt = ko_mqtt_altlast($ko_cfg['mqtt_topic'] !== '' ? $ko_cfg['mqtt_topic'] : 'kodi');
    $ko_altlage = $ko_alt['lage'];
    foreach ($ko_alt['themen'] as $ko_th) {
        if (isset($ko_werte[$ko_th])) {
            $ko_abraeumen[] = $ko_th;
        } elseif ($ko_alt['lage'] === 'belegt' && $ko_faellig) {
            $ko_abraeumen[] = $ko_th;
        }
    }
}

/* PLATZHALTER GEHEN FLUECHTIG.
 *
 * "-" in wiedergabe heisst "nicht feststellbar" - eine Aussage des Senders
 * ueber seinen eigenen Abruf, nicht ueber Kodi. Ist Kodi nicht erreichbar,
 * gilt dasselbe fuer titel. Bis 1.2.9 gingen beide retained hinaus und
 * ueberschrieben im Broker den letzten Stand, den Kodi wirklich gemeldet
 * hatte (Regeln/07, 19.09.2026; Bauart BatterieBMS 0.9.28: bei einem
 * Abruffehler bleibt der letzte Geraetestand stehen). Jetzt bekommt Loxone
 * den Strich live, und im Broker bleibt der letzte Stand von Kodi
 * (Faelle R18, R20). */
$ko_fluechtig = array();
if ($ko_wiedergabe === '-' || ($ko_erreichbar !== null && $ko_erreichbar !== 1)) {
    $ko_fluechtig = array('wiedergabe', 'titel');
}

list($ko_n, $ko_meldung, $ko_versucht, $ko_zeilen)
    = ko_mqtt_publish($ko_werte, $ko_trocken, $ko_abraeumen, $ko_fluechtig);

if ($ko_trocken) {
    echo 'Trockenlauf - es wird NICHTS gesendet. '
       . ($ko_faellig ? 'Voller Lauf (Lebenszeichen und Zustaende).' : 'Nur das Lebenszeichen, der Takt ist nicht um.') . "\n";
    echo 'Helfer: ' . ($ko_helfer ? 'antwortet' : 'antwortet NICHT - dienst und autostart fehlen, status/ok = 0') . "\n";
    if ((string) $ko_cfg['sender_ein'] !== '1') {
        echo "Hinweis: der Sender ist ausgeschaltet; im Betrieb ginge nichts hinaus.\n";
    }
    foreach ($ko_werte as $k => $v) {
        if ($v === null) {
            echo $ko_cfg['mqtt_topic'] . '/' . $k . ' = (nicht feststellbar oder nicht faellig - wird nicht gesendet)' . "\n";
        }
    }
    foreach ($ko_zeilen as $zeile) { echo $zeile . "\n"; }
    if ($ko_meldung !== '') {
        echo 'Der echte Lauf scheiterte hier: ' . $ko_meldung . "\n";
    } else {
        echo 'Ziel: UDP-Eingang des Gateways 127.0.0.1:' . ko_mqtt_port() . "\n";
    }
    if ($ko_rpc_meldung !== '') { echo 'Kodi: ' . $ko_rpc_meldung . "\n"; }
    ko_sender_ende($ko_lock, 0);
}

$ko_vollstaendig = ($ko_versucht > 0 && $ko_n === $ko_versucht);
$ko_gelungen = $ko_vollstaendig && $ko_helfer;

$ko_neu = $ko_zustand;
/* Ist der Broker nicht zu fragen, wird in jedem Lauf abgeraeumt - das steht
 * einmal je Stunde im Protokoll, nicht jede Minute. */
if ($ko_altlage === 'unbekannt') {
    $ko_alog = isset($ko_zustand['altlast_log_ts']) ? (int) $ko_zustand['altlast_log_ts'] : 0;
    if (($ko_jetzt_ts - $ko_alog) >= 3600 || $ko_alog > $ko_jetzt_ts) {
        ko_log('Statussender: der Broker liess sich nicht befragen, ob unter '
            . ($ko_cfg['mqtt_topic'] !== '' ? $ko_cfg['mqtt_topic'] : 'kodi')
            . '/ noch frueher zurueckbehaltene Werte stehen (' . implode(', ', ko_mqtt_altlast_liste())
            . '). Sie werden deshalb unmittelbar vor jedem Senden geloescht, bis der Broker antwortet.');
        $ko_neu['altlast_log_ts'] = $ko_jetzt_ts;
    }
}
if ($ko_n > 0) {
    $ko_neu['herzschlag'] = $ko_zaehler + 1;
    $ko_neu['lebenszeichen_ts'] = $ko_jetzt_ts;
}
if ($ko_faellig) {
    $ko_neu['versuch_ts']  = $ko_jetzt_ts;
    $ko_neu['anzahl']      = $ko_n;
    $ko_neu['erwartet']    = $ko_versucht;
    $ko_neu['ok']          = $ko_gelungen ? 1 : 0;
    $ko_neu['dienst']      = $ko_dienst;
    $ko_neu['autostart']   = $ko_autostart;
    $ko_neu['erreichbar']  = $ko_erreichbar;
    $ko_neu['wiedergabe']  = $ko_wiedergabe;
    $ko_neu['titel']       = $ko_titel;
    $ko_neu['rpc_meldung'] = $ko_rpc_meldung;
    // "zuletzt gelungen" wird NUR bei Erfolg fortgeschrieben (Regeln/03).
    if ($ko_gelungen) { $ko_neu['gesendet_ts'] = $ko_jetzt_ts; }
}

/* Stoerung melden - gebremst. */
/* Der Fehlertext traegt KEINE Zahlen, die von Lauf zu Lauf wechseln: ein
 * voller Lauf baut fuenf Zeilen, ein Lebenszeichen-Lauf drei. Stand die Zahl
 * im Text, galt jeder Wechsel als neuer Fehler, und die Bremse griff nicht
 * (gemessen am Pruefstand). Die Zahlen stehen in anzahl/erwartet. */
$ko_fehler = '';
if (!$ko_helfer) {
    $ko_fehler = 'der Helfer (elevatedhelper.pl) hat nicht geantwortet';
} elseif (!$ko_vollstaendig) {
    $ko_fehler = 'nicht alles an das Gateway uebergeben'
        . ($ko_meldung !== '' ? ': ' . preg_replace('/^\d+ Zeilen nicht uebergeben$/', 'Zeilen nicht uebergeben', $ko_meldung) : '');
}
$ko_alt_fehler = isset($ko_zustand['fehler']) ? (string) $ko_zustand['fehler'] : '';
$ko_alt_log    = isset($ko_zustand['fehler_log_ts']) ? (int) $ko_zustand['fehler_log_ts'] : 0;
if ($ko_fehler !== '') {
    if ($ko_fehler !== $ko_alt_fehler || ($ko_jetzt_ts - $ko_alt_log) >= 3600 || $ko_von_hand) {
        ko_log('Statussender: ' . $ko_fehler . '.');
        $ko_neu['fehler_log_ts'] = $ko_jetzt_ts;
    }
    $ko_neu['fehler'] = $ko_fehler;
} elseif ($ko_alt_fehler !== '') {
    ko_log('Statussender: wieder in Ordnung (vorher: ' . $ko_alt_fehler . ').');
    $ko_neu['fehler'] = '';
    $ko_neu['fehler_log_ts'] = 0;
}

if ($ko_neu !== $ko_zustand && !ko_json_schreiben($ko_p['zustand'], $ko_neu, 0644)) {
    ko_log('Statussender: die Zustandsdatei ' . $ko_p['zustand'] . ' liess sich nicht schreiben.');
}

if ($ko_jetzt) {
    echo 'Kodi NG: ' . $ko_n . ' von ' . $ko_versucht . ' Zeilen an den UDP-Eingang uebergeben'
       . ($ko_helfer ? '' : ', der Helfer hat NICHT geantwortet')
       . ($ko_meldung !== '' ? ' - ' . $ko_meldung : '') . ".\n"
       . "Ob sie im Broker ankommen, zeigt der MQTT Finder; der Kern meldet nur die Uebergabe.\n";
}

ko_sender_ende($ko_lock, $ko_gelungen ? 0 : 1);
