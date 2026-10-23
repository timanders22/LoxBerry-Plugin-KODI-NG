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
# K5 (1.2.22, Hausform Matter2Lox 0.9.44): der Ordnername kommt vom Installer aus der eigenen plugin.cfg;
# trotzdem kein Pfadtrenner, kein ".." und nicht "." oder ".." selbst - alle rm -rf haengen ihn an.
case "$PDIR" in
    ''|.|..|*/*|*..*)
        echo "<FAIL> Unzulaessiger Ordnername '$PDIR' - es wird nichts zurueckgestellt und nichts geloescht."
        exit 1 ;;
esac
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
# K1 (1.2.22, Entscheidung 2 vom 23.10.2026; aendert Entscheidung 1 vom 29.09.): die Marke einer laufenden
# Aktualisierung faellt HIER, erst nach dem geprueften Zurueckstellen. Bis 1.2.21 entfernte postinstall.sh sie per
# trap - brach das Update zwischen postinstall und postupgrade ab, hielt der zweite Anlauf eine verwaiste
# Sicherung fuer fremd und raeumte sie weg (Pruefbericht Fall D). Im Fehlerzweig bleibt sie liegen; die
# Befehlsbruecke startet bis dahin nicht, und ihre Befehle quittieren AKTUALISIERUNG.
MARKE="$BASE/data/plugins/$PDIR.upgrade_laeuft"

# K3 (1.2.22, Bauart EVCC 0.9.47 postupgrade.sh): jede Datei DER SICHERUNG muss im Ziel bytegleich stehen.
# Bis 1.2.21 stand hier "diff -r" ueber den ganzen Konfigordner - seit 1.2.13 legt der Installer dort aber
# config/mqtt_subscriptions.cfg aus dem Paket ab, und eine Sicherung aus 1.2.12 oder frueher kennt sie nicht:
# "Only in", <FAIL>, Rueckgabe 1, und die Sicherung mit dem Kodi-Kennwort blieb liegen (Pruefbericht Fall B;
# das Haus steht auf 1.2.11).
ko_sicherung_gleich() {
    ( cd "$1" 2>/dev/null && find . -type f ) | while IFS= read -r ko_f; do
        cmp -s "$1/$ko_f" "$2/$ko_f" || exit 1
    done
}

mkdir -p "$BASE/config/plugins/$PDIR" 2>/dev/null

# Wer von 1.0.0 oder frueher kommt, hat die Sicherung noch in der Ramdisk.
if [ ! -d "$SICHER/config" ] && [ -d "/tmp/${ARGV1}_upgrade/config" ]; then
    SICHER="/tmp/${ARGV1}_upgrade"
    echo "<INFO> Sicherung am alten Ort gefunden ($SICHER)."
fi

# WELCHE SICHERUNG HIER LIEGT (Entscheidung 1, Befund I1, 29.09.2026): nur eine
# aus DIESEM Vorgang. preupgrade.sh hat sie eben angelegt, oder es hat eine
# Sicherung aus einem frueheren Vorgang weggeraeumt und nur die eines
# abgebrochenen Versuchs dieses Updates stehen lassen (dessen Marke lag noch).
# Bei einer Neuinstallation laeuft dieses Skript nicht; dort legt
# postinstall.sh eine liegengebliebene Sicherung nach .alt. Die Marke liegt
# hier noch (seit 1.2.22, K1) und faellt erst nach dem geprueften Zurueckstellen.
if [ -d "$SICHER/config" ] && [ -n "$(ls -A "$SICHER/config" 2>/dev/null)" ]; then
    # Erst pruefen, dann die Sicherung loeschen. Bis 1.2.7 hiess es
    # "zurueckgestellt", auch wenn cp scheiterte - und danach war die
    # Sicherung weg. Jetzt bleibt sie in diesem Fall liegen.
    if cp -a "$SICHER/config/." "$BASE/config/plugins/$PDIR/" \
       && ko_sicherung_gleich "$SICHER/config" "$BASE/config/plugins/$PDIR"; then
        echo "<OK> Konfiguration zurueckgestellt."
        rm -f "$MARKE" 2>/dev/null
    else
        echo "<FAIL> Die Konfiguration liess sich NICHT vollstaendig zurueckstellen."
        echo "<FAIL> Die Sicherung bleibt unter $SICHER/config liegen;"
        echo "<FAIL> bitte von Hand nach $BASE/config/plugins/$PDIR/ kopieren."
        chown -R loxberry:loxberry "$BASE/config/plugins/$PDIR" 2>/dev/null
        exit 1
    fi
else
    echo "<INFO> Keine gesicherte Konfiguration gefunden - nichts zurueckzustellen."
    rm -f "$MARKE" 2>/dev/null
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
# Drei Ausgaenge wie dort (Befund I5): eine vorhandene, aber unlesbare
# kodi.json ist nicht "keine Einstellungen".
ko_cfg_inhalt() {
    [ -f "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $r = @file_get_contents($argv[1]);
        if ($r === false) { exit(3); }
        if (trim($r) === "") { exit(4); }
        $d = json_decode($r, true);
        if (!is_array($d)) { exit(3); }
        exit(count($d) > 0 ? 0 : 4);
    ' -- "$1" 2>/dev/null
}
ko_cfg_inhalt "$BASE/config/plugins/$PDIR/kodi.json"
KO_RC=$?
if [ "$KO_RC" = 0 ]; then
    echo "<OK> Update abgeschlossen, Einstellungen uebernommen."
elif [ "$KO_RC" != 1 ] && [ "$KO_RC" != 2 ] && [ "$KO_RC" != 4 ]; then
    echo "<WARNING> Update abgeschlossen, aber $BASE/config/plugins/$PDIR/kodi.json"
    echo "<WARNING> ist nicht lesbar (kein gueltiges JSON). Die Oberflaeche legt sie beim"
    echo "<WARNING> ersten Aufruf als kodi.json.kaputt beiseite und liest die Zweitschrift"
    echo "<WARNING> $BASE/config/plugins/$PDIR.backup.json, falls es sie gibt."
else
    echo "<OK> Update abgeschlossen. Es lagen keine gespeicherten Einstellungen vor."
fi
echo "<INFO> Kodi laeuft ab 1.1.0 als systemd-Dienst statt ueber /etc/init.d/kodi."
echo "<INFO> Zustand pruefen mit: systemctl status kodi_ng"
exit 0
