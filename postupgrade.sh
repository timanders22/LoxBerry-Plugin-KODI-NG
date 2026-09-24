#!/bin/bash
# Kodi - postupgrade (laeuft als Benutzer loxberry)

ARGV1=$1
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

# DIE WURZEL WIRD GEPRUEFT, NICHT GEGLAUBT - dieselbe Pruefung wie in
# preupgrade.sh (dort begruendet).
#
# Bis 1.2.7 stand hier nur BASE="${ARGV5:-$LBHOMEDIR}". War beides leer,
# suchte das Skript die Sicherung unter "/data/plugins/...", fand nichts,
# meldete beruhigend "nichts zurueckzustellen" und "Update abgeschlossen" -
# und die Konfiguration war weg.
#
# Findet sich keine Wurzel, wird NICHTS zurueckgespielt und NICHTS geloescht,
# und das Skript endet mit Rueckgabewert 1. Nicht mit 2: an dieser Stelle hat
# der Installer die alte Fassung schon entfernt, ein Abbruch verhinderte nur
# noch postroot.sh (Unit, udev-Regeln). Mit 1 laeuft die Installation weiter
# und fuehrt die Zeile in ihrer Fehlerliste und als Benachrichtigung
# (sbin/plugininstall.pl, LoxBerry 4.0.0.15, Zeilen 1329-1350).
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
# Der Rueckfall hiess bis 1.1.9 "kodi" - der Ordnername VOR der
# Umbenennung auf kodi_ng. Griff er, sicherte bzw. suchte dieses
# Skript in einem Verzeichnis, das es nicht mehr gibt.
PDIR="${ARGV3:-kodi_ng}"
if [ -z "$BASE" ]; then
    echo "<FAIL> Das Wurzelverzeichnis des LoxBerry war nicht zu ermitteln (fuenftes"
    echo "<FAIL> Argument: '$ARGV5', LBHOMEDIR: '$LBHOMEDIR', Suche ab dem Ablageort"
    echo "<FAIL> dieses Skripts ohne Treffer). Die gesicherte Konfiguration von Kodi NG"
    echo "<FAIL> wurde deshalb NICHT zurueckgespielt und auch nicht geloescht. Sie liegt,"
    echo "<FAIL> sofern preupgrade.sh sie anlegen konnte, unter"
    echo "<FAIL>   <LoxBerry>/data/plugins/$PDIR.upgrade_sicherung/config/"
    echo "<FAIL> und gehoert von Hand nach <LoxBerry>/config/plugins/$PDIR/ kopiert."
    exit 1
fi
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"

mkdir -p "$BASE/config/plugins/$PDIR" 2>/dev/null

# Wer von 1.0.0 oder frueher kommt, hat die Sicherung noch in der Ramdisk.
if [ ! -d "$SICHER/config" ] && [ -d "/tmp/${ARGV1}_upgrade/config" ]; then
    SICHER="/tmp/${ARGV1}_upgrade"
    echo "<INFO> Sicherung am alten Ort gefunden ($SICHER)."
fi

if [ -d "$SICHER/config" ] && [ -n "$(ls -A "$SICHER/config" 2>/dev/null)" ]; then
    # Erst pruefen, dann die Sicherung loeschen. Bis 1.2.7 hiess es
    # "zurueckgestellt", auch wenn cp scheiterte - und danach war die
    # Sicherung weg. Jetzt bleibt sie in diesem Fall liegen.
    if cp -a "$SICHER/config/." "$BASE/config/plugins/$PDIR/" \
       && diff -r "$SICHER/config" "$BASE/config/plugins/$PDIR" >/dev/null 2>&1; then
        echo "<OK> Konfiguration zurueckgestellt."
    else
        echo "<FAIL> Die Konfiguration liess sich NICHT vollstaendig zurueckstellen."
        echo "<FAIL> Die Sicherung bleibt unter $SICHER/config liegen;"
        echo "<FAIL> bitte von Hand nach $BASE/config/plugins/$PDIR/ kopieren."
        chown -R loxberry:loxberry "$BASE/config/plugins/$PDIR" 2>/dev/null
        exit 1
    fi
else
    echo "<INFO> Keine gesicherte Konfiguration gefunden - nichts zurueckzustellen."
fi

chown -R loxberry:loxberry "$BASE/config/plugins/$PDIR" 2>/dev/null

# Erst hier faellt die Sicherung, und nur, wenn das Zurueckstellen oben
# geprueft gelungen ist - der Fehlerzweig steigt mit 1 aus und laesst sie
# liegen. Die beiden Nebendateien gehen mit: preupgrade.sh baut die neue
# Sicherung seit 1.2.8 unter <ordner>.upgrade_sicherung.neu und schiebt die
# alte waehrend des Umbenennens nach ".alt" (dort begruendet). Nach einem
# abgebrochenen Lauf koennen sie liegenbleiben, und sie tragen dieselben
# Zugangsdaten wie die Sicherung selbst.
rm -rf "$BASE/data/plugins/$PDIR.upgrade_sicherung" \
       "$BASE/data/plugins/$PDIR.upgrade_sicherung.neu" \
       "$BASE/data/plugins/$PDIR.upgrade_sicherung.alt" 2>/dev/null
rm -rf "/tmp/${ARGV1}_upgrade" 2>/dev/null

# Die Schlusszeile sagt, ob Einstellungen da sind - nach dem Zurueckstellen,
# mit derselben Pruefung wie postinstall.sh (dort begruendet): kodi.json
# lesbar und nicht leer. Fehlen sie, hat postinstall.sh die Anleitung schon
# ausgegeben.
ko_cfg_inhalt() {
    [ -f "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        exit((is_array($d) && count($d) > 0) ? 0 : 1);
    ' -- "$1" 2>/dev/null
}
if ko_cfg_inhalt "$BASE/config/plugins/$PDIR/kodi.json"; then
    echo "<OK> Update abgeschlossen, Einstellungen uebernommen."
else
    echo "<OK> Update abgeschlossen. Es lagen keine gespeicherten Einstellungen vor."
fi
echo "<INFO> Kodi laeuft ab 1.1.0 als systemd-Dienst statt ueber /etc/init.d/kodi."
echo "<INFO> Zustand pruefen mit: systemctl status kodi_ng"
exit 0
