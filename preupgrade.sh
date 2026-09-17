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

# DIE WURZEL WIRD GEPRUEFT, NICHT GEGLAUBT (fail closed wie uninstall).
#
# Bis 1.2.7 stand hier nur BASE="${ARGV5:-$LBHOMEDIR}". War beides leer,
# wurde BASE leer, die Pruefung unten fand unter "/config/plugins/kodi_ng"
# nichts, meldete "offenbar eine Erstinstallation" - und der Installer
# loeschte danach die Konfiguration, ohne dass es eine Sicherung gab.
#
# Jetzt zaehlt ein Verzeichnis nur als LoxBerry-Wurzel, wenn es
# config/plugins, data/plugins UND config/system/general.json traegt
# (Regeln/06: ohne general.json trifft eine Suche auf einem Pruefrechner das
# Laufwerk). Reihenfolge: fuenftes Argument, LBHOMEDIR, dann aufwaerts vom
# Ablageort dieses Skripts (der Installer ruft es im ausgepackten Paket
# unterhalb von data/system/tmp/uploads auf), hoechstens acht Ebenen.
#
# Findet sich keine, bricht dieses Skript mit Rueckgabewert 2 ab. Am Geraet
# nachgelesen (sbin/plugininstall.pl, LoxBerry 4.0.0.15, Zeilen 845-874): ein
# Wert groesser 1 beendet die Installation ("Installation cannot be
# continued") VOR purge_installation - die alte Fassung samt Konfiguration
# bleibt dann unberuehrt stehen. Ein Update ohne Sicherung waere schlimmer
# als gar keins.
ko_ist_loxberry() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] \
        && [ -f "$1/config/system/general.json" ]
}
ko_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if ko_ist_loxberry "$v"; then
            echo "$v"
            return 0
        fi
        v=$(dirname "$v")
        i=$((i + 1))
    done
    return 1
}
BASE=""
for KO_KAND in "$ARGV5" "$LBHOMEDIR"; do
    if ko_ist_loxberry "$KO_KAND"; then
        BASE="$KO_KAND"
        break
    fi
done
if [ -z "$BASE" ]; then
    BASE=$(ko_wurzel_suchen)
fi
if [ -z "$BASE" ]; then
    echo "<FAIL> Das Wurzelverzeichnis des LoxBerry war nicht zu ermitteln (fuenftes"
    echo "<FAIL> Argument: '$ARGV5', LBHOMEDIR: '$LBHOMEDIR', Suche ab dem Ablageort"
    echo "<FAIL> dieses Skripts ohne Treffer). Die Konfiguration von Kodi NG kann"
    echo "<FAIL> deshalb NICHT gesichert werden, und das Update wuerde sie loeschen."
    echo "<FAIL> Das Update wird hier abgebrochen; die bisherige Fassung bleibt"
    echo "<FAIL> unveraendert installiert."
    exit 2
fi
# Der Rueckfall hiess bis 1.1.9 "kodi" - der Ordnername VOR der
# Umbenennung auf kodi_ng. Griff er, sicherte bzw. suchte dieses
# Skript in einem Verzeichnis, das es nicht mehr gibt.
PDIR="${ARGV3:-kodi_ng}"

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
    # Das Ergebnis PRUEFEN: meldete dieses Skript "gesichert", ohne dass
    # etwas gesichert war, loeschte der Installer danach die einzige Kopie.
    # Rueckgabewert 2 bricht die Aktualisierung vor dem Loeschen ab
    # (plugininstall.pl: >1 = Abbruch), die alte Fassung bleibt stehen.
    if cp -a "$BASE/config/plugins/$PDIR/." "$SICHER/config/" \
       && diff -r "$BASE/config/plugins/$PDIR" "$SICHER/config" >/dev/null 2>&1; then
        echo "<OK> Konfiguration gesichert."
    else
        echo "<FAIL> Die Konfiguration liess sich NICHT nach $SICHER sichern."
        echo "<FAIL> Die Aktualisierung wird abgebrochen, damit sie nicht verlorengeht."
        exit 2
    fi
else
    # Dieses Skript laeuft nur bei einem Update - "Erstinstallation" war
    # hier nie die richtige Erklaerung.
    echo "<INFO> Unter $BASE/config/plugins/$PDIR liegt keine Konfiguration - es gibt nichts zu sichern."
fi

exit 0
