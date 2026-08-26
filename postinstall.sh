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

BASE="${ARGV5:-$LBHOMEDIR}"
# Der Rueckfall hiess bis 1.1.9 "kodi" - das ist der Ordnername VOR der
# Umbenennung auf kodi_ng. Griff er, legte dieses Skript Verzeichnisse an,
# die niemand mehr liest, und setzte die Ausfuehrungsrechte an einer Stelle,
# an der keine Datei liegt. Ohne jede Meldung.
PDIR="${ARGV3:-kodi_ng}"

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

echo "<INFO> Naechster Schritt: Plugin-Oberflaeche oeffnen."
echo "<INFO> Dort laesst sich der Kodi-Dienst starten, der Autostart und der"
echo "<INFO> Statussender einschalten (beide ab Werk aus), und die Vorlagen"
echo "<INFO> fuer Loxone Config erzeugen."
exit 0
