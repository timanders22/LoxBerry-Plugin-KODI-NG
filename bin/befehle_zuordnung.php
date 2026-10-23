<?php
/**
 * Kodi NG - Zuordnung der Befehlsbruecke (bin/befehle_zuordnung.php, linieneigen; seit 1.2.22).
 *
 * Die Bruecke (bin/befehlsbruecke.php, Stammfassung, byte-gleich) laedt diese Datei und bekommt die Tabelle der
 * Befehle zurueck. Grundlage: Entwurf Gruppe 10, Abschnitte 3.2-3.4, Entscheidungen E1-E3 und E5 vom 23.10.2026.
 *
 * - 26 Befehle 1:1 zu ko_vo_befehle() (E1), alle Art puls (Wert 1): ein Taster je Befehl wie bisher; puls wird im
 *   Lesepuffer nie zusammengelegt, und "pausieren" (Klingel) und "abspielen" bleiben getrennte Namen.
 * - Eingreifend nur "beenden" (E2): zweiter Haken befehle_mqtt_steuern.
 * - Sperre 0 s fuer die 25 Taster, 60 s (Vorgabe der Bruecke) fuer beenden (E3).
 * - tun(): ko_bb_tun() in ko_lib.php -> ko_rpc() (HTTP an Kodi, Kennwort in der Kopfzeile, Frist 3 s; E5). Die
 *   Quittung steht nach Kodis Antwort ("ausgefuehrt").
 * Die Titel stehen hier FEST (Form der Stammfassung "<Plugin> <Bedeutung> (MQTT)"); die Pruefzeile
 * "Befehlstabellen" im Reiter Test haelt sie gegen [VOBEF] der deutschen Sprachdatei.
 */
if (!function_exists('ko_bb_tun')) {
    if (is_file(__DIR__ . '/ko_lib.php')) { require_once __DIR__ . '/ko_lib.php'; }
}
$ko_bb_titel = array(
    'V_BACK' => 'Kodi zurück (MQTT)', 'V_CONTEXT' => 'Kodi Kontextmenü (MQTT)',
    'V_DOWN' => 'Kodi Navigation ab (MQTT)', 'V_HOME' => 'Kodi Startbildschirm (MQTT)',
    'V_INFO' => 'Kodi Info (MQTT)', 'V_LEFT' => 'Kodi Navigation links (MQTT)',
    'V_RIGHT' => 'Kodi Navigation rechts (MQTT)', 'V_SELECT' => 'Kodi auswählen (OK) (MQTT)',
    'V_OSD' => 'Kodi Wiedergabeleiste zeigen (MQTT)', 'V_UP' => 'Kodi Navigation auf (MQTT)',
    'V_SEEK_VOR' => 'Kodi vorspulen (kleiner Schritt) (MQTT)',
    'V_SEEK_ZUR' => 'Kodi zurückspulen (kleiner Schritt) (MQTT)',
    'V_STOP' => 'Kodi Wiedergabe anhalten (MQTT)', 'V_PLAY' => 'Kodi Wiedergabe starten (MQTT)',
    'V_PAUSE' => 'Kodi Wiedergabe pausieren (MQTT)', 'V_PLAYPAUSE' => 'Kodi Wiedergabe/Pause umschalten (MQTT)',
    'V_NEXT' => 'Kodi nächster Titel (MQTT)', 'V_PREV' => 'Kodi vorheriger Titel (MQTT)',
    'V_PAGEDOWN' => 'Kodi Seite abwärts (MQTT)', 'V_PAGEUP' => 'Kodi Seite aufwärts (MQTT)',
    'V_VOL_AUF' => 'Kodi lauter (MQTT)', 'V_VOL_AB' => 'Kodi leiser (MQTT)', 'V_QUIT' => 'Kodi beenden (MQTT)',
    'V_MUTE_UM' => 'Kodi Stummschaltung umschalten (MQTT)', 'V_MUTE_AN' => 'Kodi stumm schalten (MQTT)',
    'V_MUTE_AUS' => 'Kodi Stummschaltung aufheben (MQTT)',
);
$ko_bb_taste = 'Taster - jeder Druck zaehlt. Kodi nimmt Wiederholungen ohne Schaden an (Pause bleibt Pause, '
    . 'stumm bleibt stumm); eine Brueckensperre von 60 s verschluckte den zweiten Druck derselben Taste.';
$ko_bb_befehle = array();
if (function_exists('ko_mqtt_befehle')) {
    foreach (ko_mqtt_befehle() as $ko_schl => $ko_m) {
        $ko_e = array('art' => 'puls', 'eingreifend' => (bool) $ko_m[4],
                      'titel' => isset($ko_bb_titel[$ko_schl]) ? $ko_bb_titel[$ko_schl] : '',
                      'kachel' => 'VOBEF.' . $ko_schl, 'quittung' => 'ausgefuehrt',
                      'tun' => function ($w, $t) use ($ko_schl) { return ko_bb_tun($ko_schl); });
        if ($ko_schl !== 'V_QUIT') {
            $ko_e['sperre_s'] = 0;
            $ko_e['begruendung'] = $ko_bb_taste;
        }
        $ko_bb_befehle[$ko_m[0]] = $ko_e;
    }
}
return array(
    'fassung' => 1,
    'linie'   => 'kodi_ng',
    'plugin'  => 'Kodi NG',
    'geladen' => function_exists('ko_bb_tun') && function_exists('ko_config')
                 && function_exists('ko_bb_einstellungen') && function_exists('ko_bb_protokoll'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () { return ko_bb_einstellungen(); },
    'protokoll' => function ($stufe, $text) { ko_bb_protokoll($stufe, $text); },
    'befehle' => $ko_bb_befehle,
);
