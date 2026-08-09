#!/bin/sh
# Kodi - postinstall (laeuft als Benutzer loxberry)
#
# Bis 1.1.0 standen hier 67 Zeilen auskommentierter Vorlagentext, der aus
# dem Plugin "LoxBerry Backup" stammte - samt einer Meldung ueber dessen
# Zeitplansystem. Mit Kodi hatte davon nichts zu tun.
#
# Die eigentliche Einrichtung braucht Rootrechte und steht deshalb in
# postroot.sh: Benutzer kodi anlegen, systemd-Unit, udev-Regeln, config.txt.
# Hier bleibt nur, was ohne Rootrechte geht.

ARGV3=$3   # Installationsordner des Plugins
ARGV5=$5   # Wurzelverzeichnis des LoxBerry

BASE="${ARGV5:-$LBHOMEDIR}"
PDIR="${ARGV3:-kodi}"

mkdir -p "$BASE/log/plugins/$PDIR" "$BASE/config/plugins/$PDIR" \
         "$BASE/data/plugins/$PDIR" 2>/dev/null

# Das mitgelieferte Hilfswerkzeug ausfuehrbar machen.
chmod 755 "$BASE/bin/plugins/$PDIR/kodi-rpc" 2>/dev/null
chmod 755 "$BASE/bin/plugins/$PDIR/elevatedhelper.pl" 2>/dev/null

echo "<INFO> Naechster Schritt: Plugin-Oberflaeche oeffnen."
echo "<INFO> Dort laesst sich der Kodi-Dienst starten und der Autostart"
echo "<INFO> einschalten; die Loxone-Vorlage steht im Reiter Einbindung."
exit 0
