#!/bin/bash
# Kodi - postupgrade (laeuft als Benutzer loxberry)

ARGV1=$1
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

BASE="${ARGV5:-$LBHOMEDIR}"
# Der Rueckfall hiess bis 1.1.9 "kodi" - der Ordnername VOR der
# Umbenennung auf kodi_ng. Griff er, sicherte bzw. suchte dieses
# Skript in einem Verzeichnis, das es nicht mehr gibt.
PDIR="${ARGV3:-kodi_ng}"
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"

mkdir -p "$BASE/config/plugins/$PDIR" 2>/dev/null

# Wer von 1.0.0 oder frueher kommt, hat die Sicherung noch in der Ramdisk.
if [ ! -d "$SICHER/config" ] && [ -d "/tmp/${ARGV1}_upgrade/config" ]; then
    SICHER="/tmp/${ARGV1}_upgrade"
    echo "<INFO> Sicherung am alten Ort gefunden ($SICHER)."
fi

if [ -d "$SICHER/config" ] && [ -n "$(ls -A "$SICHER/config" 2>/dev/null)" ]; then
    cp -a "$SICHER/config/." "$BASE/config/plugins/$PDIR/" 2>/dev/null
    echo "<OK> Konfiguration zurueckgestellt."
else
    echo "<INFO> Keine gesicherte Konfiguration gefunden - nichts zurueckzustellen."
fi

chown -R loxberry:loxberry "$BASE/config/plugins/$PDIR" 2>/dev/null

rm -rf "$BASE/data/plugins/$PDIR.upgrade_sicherung" 2>/dev/null
rm -rf "/tmp/${ARGV1}_upgrade" 2>/dev/null

echo "<OK> Update abgeschlossen."
echo "<INFO> Kodi laeuft ab 1.1.0 als systemd-Dienst statt ueber /etc/init.d/kodi."
echo "<INFO> Zustand pruefen mit: systemctl status kodi_ng"
exit 0
