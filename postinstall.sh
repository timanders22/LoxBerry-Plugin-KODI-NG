#!/bin/sh
# Kodi NG - postinstall (laeuft als Benutzer loxberry)
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

# DIE WURZEL WIRD GEPRUEFT, NICHT GEGLAUBT - dieselbe Pruefung wie in
# preupgrade.sh und postupgrade.sh (dort begruendet). Bis 1.2.7 stand hier
# nur BASE="${ARGV5:-$LBHOMEDIR}"; war beides leer, liefen mkdir und chmod
# still gegen "/log/plugins/..." und "/bin/plugins/...", und die Cron-Pruefung
# unten meldete einen fehlenden Eintrag an einem Ort, den es nicht gibt.
# Ohne Wurzel: <FAIL> und Rueckgabewert 1 (die Installation laeuft weiter und
# fuehrt die Zeile in ihrer Fehlerliste).
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
if ko_ist_loxberry "$ARGV5"; then
    BASE="$ARGV5"
elif ko_ist_loxberry "$LBHOMEDIR"; then
    BASE="$LBHOMEDIR"
else
    BASE=$(ko_wurzel_suchen)
fi
if [ -z "$BASE" ]; then
    echo "<FAIL> Das Wurzelverzeichnis des LoxBerry war nicht zu ermitteln (fuenftes"
    echo "<FAIL> Argument: '$ARGV5', LBHOMEDIR: '$LBHOMEDIR'). Verzeichnisse, Rechte"
    echo "<FAIL> und der Cron-Eintrag wurden NICHT eingerichtet bzw. geprueft."
    exit 1
fi
# Der Rueckfall hiess bis 1.1.9 "kodi" - das ist der Ordnername VOR der
# Umbenennung auf kodi_ng. Griff er, legte dieses Skript Verzeichnisse an,
# die niemand mehr liest, und setzte die Ausfuehrungsrechte an einer Stelle,
# an der keine Datei liegt. Ohne jede Meldung.
PDIR="${ARGV3:-kodi_ng}"

# ---------- Die Marke "Aktualisierung laeuft" (Entscheidung 1, Befund I1) ----------
#
# preupgrade.sh legt sie an, und preupgrade.sh laeuft nur bei einem Update.
# Liegt sie, ist dies eine Aktualisierung, sonst eine Neuinstallation.
# Entschieden wird ALLEIN am Vorhandensein, ohne Altersvergleich: zwischen
# preupgrade.sh und diesem Skript liegt die apt-Installation von Kodi, und die
# kann laenger als eine Stunde dauern (Praezisierung des Hausherrn vom
# 29.09.2026, gemessen an der Funkwacht). Entfernt wird sie ueber einen trap,
# nicht am Dateiende - jeder Ausstieg nimmt sie mit, und der Rueckgabewert
# bleibt erhalten. postupgrade.sh braucht sie nicht mehr: bei einer
# Neuinstallation laeuft es nicht, und eine Upgrade-Sicherung aus einem
# frueheren Vorgang hat preupgrade.sh schon weggeraeumt.
MARKE="$BASE/data/plugins/$PDIR.upgrade_laeuft"
KO_UPGRADE=0
if [ -f "$MARKE" ]; then KO_UPGRADE=1; fi
ko_marke_weg() {
    ko_rc=$?
    rm -f "$MARKE" 2>/dev/null
    exit "$ko_rc"
}
trap ko_marke_weg EXIT

mkdir -p "$BASE/log/plugins/$PDIR" "$BASE/config/plugins/$PDIR" \
         "$BASE/data/plugins/$PDIR" 2>/dev/null

# Die ausfuehrbaren Teile ausfuehrbar machen.
#
# ko_lib.php steht bewusst NICHT dabei: sie wird eingebunden, nicht
# aufgerufen. Ein Ausfuehrungsrecht darauf waere eine Behauptung ueber ihren
# Zweck, die nicht stimmt.
chmod 755 "$BASE/bin/plugins/$PDIR/kodi-rpc" 2>/dev/null
chmod 755 "$BASE/bin/plugins/$PDIR/elevatedhelper.pl" 2>/dev/null
chmod 755 "$BASE/bin/plugins/$PDIR/kodi_ng_status.php" 2>/dev/null

# Den eigenen Cron-Eintrag nachsehen und MELDEN, was da ist.
#
# `cron/cron.01min` ist eine DATEI. Legt der Installer sie als Verzeichnis
# ab - etwa weil an derselben Stelle noch das Verzeichnis einer Vorfassung
# steht -, fuehrt LoxBerry sie nicht aus, und der Statussender laeuft nie.
# Das Plugin stuende vollstaendig installiert da und taete nichts.
CRON="$BASE/system/cron/cron.01min/$PDIR"
if [ -f "$CRON" ] && [ -x "$CRON" ]; then
    echo "<OK> Der Cron-Eintrag liegt als ausfuehrbare Datei unter $CRON."
elif [ -f "$CRON" ]; then
    # run-parts fuehrt in diesen Ordnern NUR ausfuehrbare Dateien aus. Eine
    # Datei ohne Ausfuehrungsrecht liegt richtig und laeuft nie - und eine
    # Pruefung, die nur nach der Datei fragt, meldet dazu <OK>.
    echo "<INFO> Der Cron-Eintrag liegt unter $CRON, aber ohne Ausfuehrungsrecht."
    if chmod 755 "$CRON" 2>/dev/null; then
        echo "<OK> Ausfuehrungsrecht nachgetragen."
    else
        echo "<WARNING> Das Ausfuehrungsrecht liess sich nicht setzen - der"
        echo "<WARNING> Statussender wuerde nicht laufen. Von Hand:"
        echo "<WARNING>   sudo chmod 755 $CRON"
    fi
elif [ -d "$CRON" ]; then
    echo "<WARNING> Unter $CRON liegt ein VERZEICHNIS statt einer Datei."
    echo "<WARNING> LoxBerry fuehrt dort nur Dateien aus - der Statussender"
    echo "<WARNING> wuerde nie laufen. Bitte das Verzeichnis entfernen und das"
    echo "<WARNING> Plugin noch einmal installieren."
else
    echo "<WARNING> Unter $CRON liegt nichts. Der Statussender wuerde nicht laufen."
fi

# ---------- Neuinstallation: Liegengebliebenes beiseitelegen (Befund I1/K5) ----------
#
# Entscheidung 1 des Hausherrn, 29.09.2026. Bis 1.2.11 spielte der erste
# Seitenaufbau nach einer NEUinstallation eine liegengebliebene
# config/plugins/<ordner>.backup.json ein - alter Kodi-Wirt, altes Kennwort,
# Statussender und JSON-RPC eingeschaltet, ohne jede Meldung (in WSL gemessen);
# eine liegengebliebene Upgrade-Sicherung spielte das naechste Update ein.
# Ohne Marke werden beide nach <name>.alt verschoben - die Bibliothek
# (ko_config) liest .alt nie - und EINMAL gemeldet; die Deinstallation raeumt
# .alt mit ab. Bei einer Aktualisierung bleiben sie, wo sie sind.
KO_GEMELDET=0
ko_beiseite_melden() {
    if [ "$KO_GEMELDET" != "1" ]; then
        echo "<WARNING> Neuinstallation: aus einer frueheren Installation lagen gesicherte"
        echo "<WARNING> Einstellungen da (mit dem Kodi-Kennwort im Klartext). Sie werden"
        echo "<WARNING> NICHT eingespielt und liegen jetzt beiseite unter:"
        KO_GEMELDET=1
    fi
    echo "<WARNING>   $1"
}
if [ "$KO_UPGRADE" != "1" ]; then
    KO_ZW="$BASE/config/plugins/$PDIR.backup.json"
    if [ -e "$KO_ZW" ] || [ -L "$KO_ZW" ]; then
        if mv -f "$KO_ZW" "$KO_ZW.alt" 2>/dev/null; then
            [ -L "$KO_ZW.alt" ] || chmod 600 "$KO_ZW.alt" 2>/dev/null
            ko_beiseite_melden "$KO_ZW.alt"
        else
            echo "<WARNING> $KO_ZW liess sich nicht beiseitelegen - die Oberflaeche"
            echo "<WARNING> spielte sie beim ersten Aufruf ein. Bitte von Hand entfernen."
        fi
    fi
    KO_US="$BASE/data/plugins/$PDIR.upgrade_sicherung"
    if [ -e "$KO_US" ] || [ -L "$KO_US" ]; then
        rm -rf "$KO_US.alt" 2>/dev/null
        if mv -f "$KO_US" "$KO_US.alt" 2>/dev/null; then
            ko_beiseite_melden "$KO_US.alt"
        else
            echo "<WARNING> $KO_US liess sich nicht beiseitelegen - das naechste Update"
            echo "<WARNING> spielte sie ein. Bitte von Hand entfernen."
        fi
    fi
    if [ "$KO_GEMELDET" = "1" ]; then
        echo "<WARNING> Die Deinstallation raeumt sie mit ab; wer sie nicht braucht, loescht sie."
    fi
fi

# DIE ERSTANLEITUNG NUR, WENN KEINE EINSTELLUNGEN DA SIND.
#
# Dieses Skript laeuft bei der Erstinstallation UND bei jedem Upgrade
# (plugininstall.pl uebergibt kein Kennzeichen). Bis 1.2.8 stand die
# Anleitung unten nach jedem Upgrade im Protokoll, obwohl postupgrade.sh die
# Einstellungen gleich danach zurueckstellt.
#
# Entschieden wird nach dem INHALT von kodi.json: lesbares JSON und nicht
# leer - dieselbe Bedingung, nach der ko_config() in bin/ko_lib.php die
# Zweitschrift fuer brauchbar haelt ("if ($z)"). Die Vorgaben enthalten keine
# Pflichtangabe; "eingerichtet" heisst also: die Oberflaeche hat die
# Einstellungen mindestens einmal gespeichert.
# Beim Upgrade liegt kodi.json in diesem Augenblick noch nicht an ihrem Platz
# - purge_installation hat den Konfigordner geraeumt, und zurueckgestellt
# wird erst in postupgrade.sh aus der Sicherung, die preupgrade.sh angelegt
# hat. Deshalb zaehlt auch die dort wartende Datei. Ob das Zurueckstellen
# gelang, meldet postupgrade.sh (mit derselben Pruefung) in seiner
# Schlusszeile; scheitert es, steigt es mit <FAIL> aus.
# Ohne php ist nichts pruefbar; dann steht die Anleitung.
# Gemessen am 24.09.2026: Pruefung-KODI-NG-1.2.9/postinstall_hinweis.md.
#
# DREI AUSGAENGE STATT ZWEIEN (Befund I5, 29.09.2026): 0 lesbar mit Inhalt,
# 1 fehlt, 2 kein php, 3 vorhanden, aber nicht lesbar (kein gueltiges JSON),
# 4 leer. Bis 1.2.11 hiess "unlesbar" dasselbe wie "fehlt", und der Anwender
# bekam die Ersteinrichtung empfohlen, obwohl seine Einstellungen in der
# Zweitschrift lagen.
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
# Die Anleitung fuer eine Anlage ohne Einstellungen. Der Autostart von Kodi
# ist NICHT ab Werk aus: postroot.sh schaltet ihn beim ersten Einspielen ein
# (Befund I2; bis 1.2.11 stand hier das Gegenteil, und das Protokoll
# widersprach sich zwei Zeilen spaeter).
ko_anleitung() {
    echo "<INFO> Naechster Schritt: Plugin-Oberflaeche oeffnen."
    echo "<INFO> Dort laesst sich der Kodi-Dienst starten und der Statussender"
    echo "<INFO> einschalten (ab Werk aus), und es entstehen die Vorlagen fuer"
    echo "<INFO> Loxone Config. Den Autostart von Kodi schaltet die Installation"
    echo "<INFO> beim ersten Einspielen ein; in der Oberflaeche laesst er sich abschalten."
}
ko_cfg_inhalt "$BASE/config/plugins/$PDIR/kodi.json"
KO_RC=$?
if [ "$KO_RC" = 0 ]; then
    echo "<OK> Einstellungen vorhanden - eine Ersteinrichtung ist nicht noetig."
elif [ "$KO_RC" != 1 ] && [ "$KO_RC" != 2 ] && [ "$KO_RC" != 4 ]; then
    echo "<WARNING> $BASE/config/plugins/$PDIR/kodi.json ist vorhanden, aber nicht lesbar"
    echo "<WARNING> (kein gueltiges JSON). Die Oberflaeche legt sie beim ersten Aufruf als"
    echo "<WARNING> kodi.json.kaputt beiseite und liest die Zweitschrift"
    echo "<WARNING> $BASE/config/plugins/$PDIR.backup.json, falls es sie gibt."
elif [ "$KO_UPGRADE" = "1" ]; then
    # Nur bei einer Aktualisierung ist von einer Aktualisierung die Rede
    # (Befund I2): postupgrade.sh laeuft bei einer Neuinstallation nicht.
    ko_cfg_inhalt "$BASE/data/plugins/$PDIR.upgrade_sicherung/config/kodi.json"
    KO_RC=$?
    if [ "$KO_RC" = 0 ]; then
        echo "<INFO> Aktualisierung: die gesicherten Einstellungen werden im naechsten"
        echo "<INFO> Schritt zurueckgestellt (postupgrade.sh)."
    elif [ "$KO_RC" != 1 ] && [ "$KO_RC" != 2 ] && [ "$KO_RC" != 4 ]; then
        echo "<WARNING> Aktualisierung: die gesicherte kodi.json unter"
        echo "<WARNING> $BASE/data/plugins/$PDIR.upgrade_sicherung/config/ ist nicht lesbar"
        echo "<WARNING> (kein gueltiges JSON). postupgrade.sh stellt sie zurueck, wie sie ist;"
        echo "<WARNING> die Oberflaeche legt sie dann beiseite und liest die Zweitschrift"
        echo "<WARNING> $BASE/config/plugins/$PDIR.backup.json, falls es sie gibt."
    else
        ko_anleitung
    fi
else
    ko_anleitung
fi
exit 0
