#!/bin/bash
# Kodi - preupgrade (laeuft als Benutzer loxberry)
#
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV1=$1   # Temporaerer Ordner waehrend der Installation
ARGV3=$3   # Installationsordner des Plugins
ARGV5=$5   # Wurzelverzeichnis des LoxBerry
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

BASE="${ARGV5:-$LBHOMEDIR}"
PDIR="${ARGV3:-kodi}"

# Geschweifte Klammern statt Rueckstrich.
#
# Bis 1.0.0 stand hier /tmp/$ARGV1\_upgrade. In bash beendet der Rueckstrich
# den Variablennamen, es funktionierte also - aber es ist genau die Sorte
# Schreibweise, die kippt, sobald jemand die Zeile in eine andere Shell
# uebernimmt. ${ARGV1}_upgrade ist eindeutig.
#
# Wichtiger: die Sicherung liegt jetzt NICHT mehr unter /tmp. Das ist auf
# dem LoxBerry eine Ramdisk. Dieses Plugin setzt REBOOT=true - zwischen
# preupgrade und postupgrade kann also planmaessig ein Neustart liegen, und
# danach waere die Ramdisk leer. Bestand hat nur, was auf der Karte liegt.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"

echo "<INFO> Sichere die Konfiguration nach $SICHER"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER/config" 2>/dev/null
chmod 0700 "$SICHER" 2>/dev/null

# Existenz PRUEFEN, bevor kopiert wird: ohne diese Bedingung meldete cp
# "No such file or directory" ins Installationsprotokoll, sobald es noch
# gar keine Konfiguration gab.
if [ -d "$BASE/config/plugins/$PDIR" ] \
   && [ -n "$(ls -A "$BASE/config/plugins/$PDIR" 2>/dev/null)" ]; then
    cp -a "$BASE/config/plugins/$PDIR/." "$SICHER/config/" 2>/dev/null
    echo "<OK> Konfiguration gesichert."
else
    echo "<INFO> Keine Konfiguration vorhanden - offenbar eine Erstinstallation."
fi

exit 0
