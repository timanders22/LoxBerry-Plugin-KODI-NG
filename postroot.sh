#!/bin/bash
# Will be executed as user "root".
# v1.0.0: bookworm-/LoxBerry-4-tauglich gemacht (config.txt-Pfad, Guards).

echo "<INFO> Stopping Kodi if it is running..."
systemctl stop kodi_ng 2>/dev/null || true
systemctl stop kodi 2>/dev/null || true
echo "<INFO> Try to kill remaining Kodi processes..."
killall kodi 2>/dev/null || true
killall kodi-standalone 2>/dev/null || true
killall kodi.bin 2>/dev/null || true

echo "<INFO> Creating user kodi"
useradd -d /home/kodi -m kodi 2>/dev/null || true
echo "<INFO> Creating group input"
addgroup --system input 2>/dev/null || true

# Add kodi user to groups
echo "<INFO> Adding kodi to groups audio, video, input, dialout, plugdev, tty, render"
for grp in audio video input dialout plugdev tty render gpio; do
    usermod -a -G "$grp" kodi 2>/dev/null || true
done

# Kodi als Systemdienst einrichten - seit 1.1.0 ueber eine systemd-Unit.
#
# Bis 1.0.0 wurde ein 168 Zeilen langes SysVinit-Skript nach /etc/init.d/kodi
# kopiert. Debian bookworm (LoxBerry 4) uebersetzt so etwas nur noch ueber
# den systemd-sysv-generator, und der kennt die Abhaengigkeiten allein aus
# den LSB-Kopfzeilen. Dort stand $remote_fs und $syslog - ausgerechnet
# Grafik und Ton, worauf Kodi wirklich wartet, tauchten nicht auf. Ergebnis:
# Kodi startete nach dem Systemstart mal und mal nicht.
# Vorgaengerdienst ausser Betrieb nehmen.
#
# Seit 1.1.0 heisst die Unit kodi_ng.service. Eine vorhandene kodi.service
# (aus 1.0.0 dieser Linie oder aus der Originalfassung) wuerde denselben
# Kodi auf demselben Port 9090 starten - zwei koennen dort nicht laufen.
#
# Sie wird deshalb abgeschaltet, aber NICHT geloescht: sie koennte zu einer
# fremden Installation gehoeren. Stattdessen wird sie beiseitegelegt; eine
# Datei ohne Endung .service ist fuer systemd unsichtbar, und wer sie
# zurueckhaben will, benennt sie einfach zurueck.
if [ -f /etc/systemd/system/kodi.service ]; then
    systemctl disable kodi 2>/dev/null || true
    mv -f /etc/systemd/system/kodi.service /etc/systemd/system/kodi.service.vor-ng
    echo "<INFO> Die bisherige kodi.service wurde abgeschaltet und liegt jetzt"
    echo "<INFO> als /etc/systemd/system/kodi.service.vor-ng. Sie wird nicht"
    echo "<INFO> mehr gebraucht und kann geloescht werden."
fi

echo "<INFO> Entferne ein etwaiges altes SysVinit-Skript"
if [ -f /etc/init.d/kodi ]; then
    # Erst aus der Startreihenfolge nehmen, dann loeschen - sonst bleibt ein
    # verwaister Verweis in /etc/rc?.d/ stehen.
    systemctl disable kodi 2>/dev/null || true
    update-rc.d -f kodi remove 2>/dev/null || true
    rm -f /etc/init.d/kodi
    echo "<OK> Altes init.d-Skript entfernt."
fi

echo "<INFO> Installiere die systemd-Unit"
if [ -f data/kodi_ng.service ]; then
    cp -f data/kodi_ng.service /etc/systemd/system/kodi_ng.service
    chmod 644 /etc/systemd/system/kodi_ng.service
    systemctl daemon-reload
    if systemctl enable kodi_ng 2>/dev/null; then
        echo "<OK> Kodi startet kuenftig automatisch mit dem System."
    else
        echo "<WARNING> systemctl enable kodi_ng ist fehlgeschlagen - bitte im"
        echo "<WARNING> Protokoll nachsehen: systemctl status kodi_ng"
    fi
else
    echo "<FAIL> data/kodi_ng.service fehlt im Paket - der Dienst wurde NICHT eingerichtet."
fi

# udev-Regeln einspielen.
#
# Diese beiden Dateien lagen bis 1.0.0 im Paket, wurden aber NIE kopiert -
# weder hier noch in postinstall.sh. Sie waren also wirkungslos, und die
# Eingabegeraete funktionierten nur, weil der Benutzer kodi ohnehin in den
# passenden Gruppen ist. Jetzt werden sie eingespielt (ohne die frueheren
# tty-Zeilen, die systemweite Rechte ueberschrieben haben).
echo "<INFO> Installiere die udev-Regeln fuer Eingabegeraete"
for r in 10-kodi_ng-permissions.rules 99-kodi_ng.rules; do
    if [ -f "data/$r" ]; then
        cp -f "data/$r" "/etc/udev/rules.d/$r"
        chmod 644 "/etc/udev/rules.d/$r"
    fi
done
udevadm control --reload-rules 2>/dev/null || true
udevadm trigger --subsystem-match=input 2>/dev/null || true

# Raspberry Pi OS bookworm (LoxBerry 4): config.txt liegt unter /boot/firmware/
CONFIGTXT="/boot/config.txt"
if [ -f /boot/firmware/config.txt ]; then
    CONFIGTXT="/boot/firmware/config.txt"
fi

if [ -f "$CONFIGTXT" ]; then
    if [ ! -f "${CONFIGTXT}.kodiplugin" ]; then
        echo "<INFO> Creating backup of your $CONFIGTXT as config.txt.kodiplugin"
        cp "$CONFIGTXT" "${CONFIGTXT}.kodiplugin"
    fi
    # gpu_mem nur auf aelteren Pis sinnvoll (Pi <= 3); auf Pi 4/5 verwaltet der
    # Treiber den Speicher selbst - dort nichts erzwingen.
    PIMODEL=$(tr -d '\0' < /proc/device-tree/model 2>/dev/null || echo "")
    case "$PIMODEL" in
        *"Pi 4"*|*"Pi 5"*|*"Pi 500"*|*"Compute Module 4"*|*"Compute Module 5"*)
            echo "<INFO> $PIMODEL erkannt - gpu_mem wird nicht gesetzt (nicht noetig)."
            ;;
        *)
            # Die config.txt von bookworm ist in bedingte Abschnitte geteilt
            # ([pi4], [cm4], [all] ...). Eine Zeile blank ans Dateiende zu
            # haengen legt sie in den ZULETZT geoeffneten Abschnitt - sie gilt
            # dann nur unter dessen Bedingung. Deshalb wird beim Anhaengen ein
            # ausdrueckliches [all] mitgeschrieben; steht es doppelt, ist die
            # zweite Zeile wirkungslos.
            echo "<INFO> Setting GPU memory to 192MB in $CONFIGTXT"
            awk -v s="gpu_mem=192" '/^gpu_mem=/{$0=s;f=1} {a[++n]=$0} END{if(!f){a[++n]="[all]";a[++n]=s};for(i=1;i<=n;i++)print a[i]>ARGV[1]}' "$CONFIGTXT"
            ;;
    esac
else
    echo "<WARNING> Keine config.txt gefunden - GPU-Einstellung uebersprungen."
fi

echo "<INFO> Creating Kodi settings directory"
mkdir -p /home/kodi/.kodi/userdata
cp -v data/advancedsettings.xml /home/kodi/.kodi/userdata
mkdir -p /home/kodi/.kodi/addons
cp -v -R data/addons/. /home/kodi/.kodi/addons/

# Eigentuemer UND Gruppe setzen.
#
# Bis 1.0.0 stand hier nur "chown -R kodi /home/kodi". Damit blieb die
# Gruppe auf dem Wert, den die Dateien beim Kopieren aus dem
# Entpackungsordner hatten - meist root. Kodi laeuft als kodi:kodi und
# konnte in solchen Verzeichnissen keine Addon-Daten schreiben.
chown -R kodi:kodi /home/kodi
# Das Heimatverzeichnis muss dem Benutzer gehoeren, aber nicht der Welt
# offenstehen: darin liegen die Zugangsdaten der Medienquellen.
chmod 750 /home/kodi

exit 0
