#!/bin/bash

# Kodi NG - preinstall (laeuft als Benutzer loxberry)
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu im Verbesserungsbau 01.10.2026 (X-1, Entscheidung 1 vom 29.09.2026),
# nach dem Muster des Abfahrts-Assistenten 1.6.19. Der Installer ruft dieses
# Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR
# dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# WARUM ES DAS BRAUCHT. Die Bibliothek heilt im Takt selbst: fehlt kodi.json
# und liegt die Zweitschrift config/plugins/<ordner>.backup.json, spielt
# ko_config() sie ein - und der Statussender ruft ko_config() bei jedem
# Minutentakt, auch bei ausgeschaltetem Sender. Die Cron-Datei liegt am Geraet
# vor postinstall.sh; postinstall.sh legte die Zweitschrift einer frueheren
# Installation zwar nach .alt (Befund I1), ein Cron-Lauf dazwischen hatte
# Kodi-Wirt, Kennwort und Schalter der frueheren Installation aber schon
# zurueckgeholt (in WSL gemessen, vb_kodi_bau_skripte/proben/x1_*.txt).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und
# Update-Sicherung werden von postupgrade.sh gebraucht.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# und eine liegengebliebene Update-Sicherung (data/plugins/<ordner>.upgrade_sicherung)
# gehen nach <name>.alt, gemeldet mit genau einer <WARNING>. Die
# Selbstheilung der Bibliothek liest .alt nie; die Deinstallation raeumt es
# ab. postinstall.sh behaelt denselben Block als Rueckfall.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
# Der Rueckfall hiess bis 1.1.9 "kodi" (siehe postinstall.sh).
PFOLDER="${ARGV3:-kodi_ng}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelpruefung wie in preupgrade.sh, postinstall.sh und postupgrade.sh:
# ohne config/plugins, data/plugins UND config/system/general.json wird
# nichts angefasst (Regeln/06).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postupgrade.sh spielt zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
BEISEITE=""
FEST=""
for ZIEL in "$BK" "$SICHER"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
# Die Zweitschrift traegt das Kodi-Kennwort im Klartext.
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    KO_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation (mit dem Kodi-Kennwort im Klartext) werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && KO_TEXT="$KO_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && KO_TEXT="$KO_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$KO_TEXT"
fi
exit 0
