#!/bin/bash
# Will be executed as user "root".
# v1.0.0: bookworm-/LoxBerry-4-tauglich gemacht (config.txt-Pfad, Guards).

# WOHER DIE PAKETDATEIEN KOMMEN.
#
# Bis 1.2.7 stand hier ueberall "data/..." - relativ, ohne Pruefung. Am Geraet
# nachgelesen (sbin/plugininstall.pl, LoxBerry 4.0.0.15, Zeilen 1357-1377):
# der Installer ruft dieses Skript als root mit
#     cd "$tempfolder" && "$script" "$tempfile" "$pname" "$pfolder" \
#                           "$pversion" "$lbhomedir" "$tempfolder"
# auf. Das Arbeitsverzeichnis ist also der ausgepackte Paketordner, und
# derselbe steht im sechsten Argument. Die relativen Pfade trugen damit -
# aber nur, solange niemand das Skript von anderswo aufruft. Jetzt wird der
# Ordner ausdruecklich bestimmt: sechstes Argument, sonst der Ablageort
# dieses Skripts; ein Ordner zaehlt nur, wenn er data/ enthaelt.
#
# WIE EIN FEHLER ANKOMMT. Derselbe Abschnitt des Installers wertet den
# Rueckgabewert aus: 1 heisst "Script/Command finished with errors. I will try
# to continue installation." - die Zeile kommt in die Fehlerliste am Ende des
# Installationsprotokolls und als Benachrichtigung in die LoxBerry-
# Oberflaeche, die Installation laeuft weiter. Ein Wert groesser 1 bricht ab
# (fail), und zwar NACHDEM alle Dateien schon kopiert sind. Dieses Skript endet
# deshalb mit 1, wenn ein <FAIL> gemeldet wurde, und sonst mit 0 - nie mit
# mehr als 1.
KO_FEHLER=0
KO_PKG=""
if [ -n "$6" ] && [ -d "$6/data" ]; then
    KO_PKG="$6"
else
    KO_SELBST=$(cd "$(dirname "$0")" 2>/dev/null && pwd)
    if [ -n "$KO_SELBST" ] && [ -d "$KO_SELBST/data" ]; then
        KO_PKG="$KO_SELBST"
    fi
fi
if [ -z "$KO_PKG" ]; then
    echo "<FAIL> Der Paketordner mit data/ war nicht zu finden (sechstes Argument:"
    echo "<FAIL> '${6}', Ablageort: '$(dirname "$0")'). Unit, udev-Regeln,"
    echo "<FAIL> advancedsettings.xml und das Kodi-Addon werden NICHT eingespielt."
    KO_FEHLER=1
fi

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
echo "<INFO> Adding kodi to groups audio, video, input, dialout, plugdev, tty, render, gpio"
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
if [ -n "$KO_PKG" ] && [ -f "$KO_PKG/data/kodi_ng.service" ]; then
    if cp -f "$KO_PKG/data/kodi_ng.service" /etc/systemd/system/kodi_ng.service; then
        chmod 644 /etc/systemd/system/kodi_ng.service
        systemctl daemon-reload
        if systemctl enable kodi_ng 2>/dev/null; then
            echo "<OK> Kodi startet kuenftig automatisch mit dem System."
        else
            echo "<WARNING> systemctl enable kodi_ng ist fehlgeschlagen - bitte im"
            echo "<WARNING> Protokoll nachsehen: systemctl status kodi_ng"
        fi
    else
        echo "<FAIL> Die Unit liess sich nicht nach /etc/systemd/system kopieren."
        KO_FEHLER=1
    fi
else
    echo "<FAIL> data/kodi_ng.service fehlt im Paket - der Dienst wurde NICHT eingerichtet."
    KO_FEHLER=1
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
    if [ -n "$KO_PKG" ] && [ -f "$KO_PKG/data/$r" ]; then
        if cp -f "$KO_PKG/data/$r" "/etc/udev/rules.d/$r"; then
            chmod 644 "/etc/udev/rules.d/$r"
        else
            echo "<FAIL> udev-Regel $r liess sich nicht kopieren."
            KO_FEHLER=1
        fi
    else
        echo "<FAIL> data/$r fehlt im Paket - die udev-Regel wurde NICHT eingespielt."
        KO_FEHLER=1
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
            #
            # ERST IN EINE ZWISCHENDATEI, DANN UMBENENNEN. Bis 1.2.7 schrieb
            # awk die config.txt an Ort und Stelle (print ... > ARGV[1]): die
            # Datei war ab dem ersten print gekuerzt, und ein Stromausfall in
            # diesem Augenblick haette eine halbe Bootkonfiguration
            # hinterlassen. Die Zwischendatei liegt im SELBEN Verzeichnis -
            # nur dort ist mv ein Umbenennen und kein Kopieren. Derselbe Name
            # wie im Helfer (elevatedhelper.pl): <config.txt>.kodiplugin.tmp.
            echo "<INFO> Setting GPU memory to 192MB in $CONFIGTXT"
            KO_TMPCFG="${CONFIGTXT}.kodiplugin.tmp"
            rm -f "$KO_TMPCFG"
            if awk -v s="gpu_mem=192" '/^gpu_mem=/{$0=s;f=1} {print} END{if(!f){print "[all]"; print s}}' "$CONFIGTXT" > "$KO_TMPCFG" \
               && [ -s "$KO_TMPCFG" ]; then
                if cmp -s "$KO_TMPCFG" "$CONFIGTXT"; then
                    rm -f "$KO_TMPCFG"
                    echo "<INFO> gpu_mem=192 stand bereits in $CONFIGTXT - nichts geaendert."
                else
                    # Rechte der alten Datei uebernehmen (auf der FAT-Bootpartition
                    # wirkungslos, auf /boot/config.txt einer ext4-Wurzel nicht),
                    # die Zwischendatei auf die Karte bringen, dann umbenennen.
                    chmod --reference="$CONFIGTXT" "$KO_TMPCFG" 2>/dev/null
                    sync "$KO_TMPCFG" 2>/dev/null || sync
                    if mv -f "$KO_TMPCFG" "$CONFIGTXT" && grep -qx 'gpu_mem=192' "$CONFIGTXT"; then
                        echo "<OK> gpu_mem=192 steht in $CONFIGTXT (wirkt nach einem Neustart)."
                    else
                        rm -f "$KO_TMPCFG"
                        echo "<FAIL> gpu_mem=192 konnte nicht in $CONFIGTXT geschrieben werden."
                        KO_FEHLER=1
                    fi
                fi
            else
                rm -f "$KO_TMPCFG"
                echo "<FAIL> $CONFIGTXT liess sich nicht lesen oder die Zwischendatei nicht"
                echo "<FAIL> schreiben - die Datei bleibt unveraendert, gpu_mem ist NICHT gesetzt."
                KO_FEHLER=1
            fi
            ;;
    esac
else
    echo "<WARNING> Keine config.txt gefunden - GPU-Einstellung uebersprungen."
fi

echo "<INFO> Creating Kodi settings directory"
mkdir -p /home/kodi/.kodi/userdata
# Eine vorhandene Datei zuerst sichern. Sie kann Einstellungen des
# Anwenders tragen, die dieses Plugin nichts angehen (Netzwerkabstimmung,
# Datenbank, Zwischenspeicher) - bis 1.2.1 waren die nach jedem Einspielen
# weg, ohne dass es irgendwo stand. Bei der config.txt macht dasselbe Skript
# es seit je richtig; hier fehlte es.
#
# ZWEI BEDINGUNGEN, und beide sind noetig. Dieses Skript laeuft bei JEDEM
# Einspielen, nicht nur beim ersten:
#
#   1. Eine vorhandene Sicherung wird NICHT ueberschrieben. Beim zweiten
#      Einspielen ist die aktuelle Datei bereits die des Plugins; ohne diese
#      Bedingung kopierte sie sich ueber die Sicherung, und die Anwenderdatei
#      waere endgueltig weg - genau der Verlust, den diese Stelle verhindern
#      soll, nur eine Fassung spaeter. Nachgestellt mit dreimaligem Aufruf.
#   2. Eine Datei, die mit der mitgelieferten uebereinstimmt, wird gar nicht
#      erst gesichert. Sonst entstuende bei der ersten Neuinstallation eine
#      Sicherung, die nichts enthaelt als das, was das Plugin selbst
#      mitbringt - und die dann nach 1. fuer immer stehen bliebe.
#
# <esallinterfaces> BLEIBT. Gemessen am 17.09.2026 im Quelltext von Kodi 21
# (xbmc/network/NetworkServices.cpp, StartJSONRPCServer; TCPServer.cpp): der
# JSON-RPC-Server auf TCP 9090 lauscht nur mit dieser Einstellung auch fuer
# ANDERE Rechner, sonst nur auf 127.0.0.1. Die Steuerbefehle aus Loxone gehen
# vom Miniserver genau dorthin. Dieselbe Einstellung oeffnet Kodis EventServer
# (UDP 9777, ohne Anmeldung) fuer das Netz; in Kodi laesst sich das nicht
# trennen. README und Hilfe sagen es.
ADVUSER=/home/kodi/.kodi/userdata/advancedsettings.xml
ADVSICHER="$ADVUSER.kodiplugin"
KO_ADV="$KO_PKG/data/advancedsettings.xml"

# KEINEM VERWEIS UNTER /home/kodi FOLGEN (seit 1.2.7). Dieses Skript laeuft als
# root; /home/kodi gehoert dem Benutzer kodi, und jedes Kodi-Addon laeuft als
# kodi. Legte es advancedsettings.xml als Verweis auf eine fremde Datei an,
# ueberschrieb bis 1.2.6 ein erneutes Einspielen diese Datei mit dem XML des
# Plugins und kopierte ihren alten Inhalt nach .kodiplugin - gefunden beim
# Gegenlesen am 17.09.2026. Derselbe Schutz steht im Helfer.
# Geprueft wird jeder Bestandteil unterhalb von /home.
ko_verweis_im_pfad() {
    kp="$1"
    while [ -n "$kp" ] && [ "$kp" != "/" ] && [ "$kp" != "/home" ]; do
        [ -L "$kp" ] && return 0
        kp=$(dirname "$kp")
    done
    return 1
}
KO_VERWEIS=0
for kv in "$ADVUSER" "$ADVSICHER" /home/kodi/.kodi/addons/service.callback.handler; do
    if ko_verweis_im_pfad "$kv"; then
        echo "<FAIL> Unter $kv liegt ein Verweis (Symlink). Als root wird dem nicht gefolgt:"
        echo "<FAIL> advancedsettings.xml und das Kodi-Addon werden NICHT eingespielt."
        KO_VERWEIS=1
    fi
done
if [ -d /home/kodi/.kodi/addons/service.callback.handler ] \
   && [ -n "$(find /home/kodi/.kodi/addons/service.callback.handler -type l 2>/dev/null | head -1)" ]; then
    echo "<FAIL> Im Addon-Ordner service.callback.handler liegen Verweise - das Addon wird NICHT eingespielt."
    KO_VERWEIS=1
fi
if [ "$KO_VERWEIS" -ne 0 ]; then
    KO_FEHLER=1
elif [ -z "$KO_PKG" ] || [ ! -f "$KO_ADV" ]; then
    echo "<FAIL> data/advancedsettings.xml fehlt im Paket - Kodis Fernsteuerung"
    echo "<FAIL> wird NICHT eingerichtet, eine vorhandene Datei bleibt unberuehrt."
    KO_FEHLER=1
else
    if [ ! -f "$ADVUSER" ]; then
        :
    elif [ -f "$ADVSICHER" ]; then
        echo "<INFO> advancedsettings.xml.kodiplugin ist bereits vorhanden und"
        echo "<INFO> bleibt unberuehrt - sie traegt den Stand vor der ersten"
        echo "<INFO> Installation dieses Plugins."
    elif cmp -s "$ADVUSER" "$KO_ADV"; then
        echo "<INFO> Vorhandene advancedsettings.xml stimmt mit der"
        echo "<INFO> mitgelieferten ueberein - keine Sicherung noetig."
    else
        cp -v "$ADVUSER" "$ADVSICHER"
        echo "<INFO> Bisherige advancedsettings.xml gesichert als"
        echo "<INFO> advancedsettings.xml.kodiplugin"
    fi
    if cp -v "$KO_ADV" /home/kodi/.kodi/userdata/advancedsettings.xml \
       && cmp -s "$KO_ADV" "$ADVUSER"; then
        echo "<OK> advancedsettings.xml eingespielt (Webserver, Zeroconf und"
        echo "<OK> Fernsteuerung von anderen Rechnern ein - noetig fuer tcp://...:9090)."
    else
        echo "<FAIL> advancedsettings.xml liess sich nicht nach /home/kodi/.kodi/userdata kopieren."
        KO_FEHLER=1
    fi
fi
if [ "$KO_VERWEIS" -ne 0 ]; then
    :
elif [ -n "$KO_PKG" ] && [ -d "$KO_PKG/data/addons" ]; then
    mkdir -p /home/kodi/.kodi/addons
    if ! cp -v -R "$KO_PKG/data/addons/." /home/kodi/.kodi/addons/; then
        echo "<FAIL> Das Kodi-Addon liess sich nicht nach /home/kodi/.kodi/addons kopieren."
        KO_FEHLER=1
    fi
    # Der Sprachordner "English" (Schreibweise bis Kodi 18) ist seit Addon
    # 3.2.0 entfallen. cp -R loescht nichts; ohne diese Zeile bliebe er auf
    # jeder bestehenden Anlage liegen.
    KO_EN=/home/kodi/.kodi/addons/service.callback.handler/resources/language/English
    if [ -d "$KO_EN" ] && [ ! -L "$KO_EN" ]; then
        rm -rf "$KO_EN" && echo "<INFO> Alten Sprachordner English des Addons entfernt."
    fi
else
    echo "<FAIL> data/addons fehlt im Paket - das Kodi-Addon wird NICHT eingespielt."
    KO_FEHLER=1
fi

# Eigentuemer UND Gruppe setzen.
#
# Bis 1.0.0 stand hier nur "chown -R kodi /home/kodi". Damit blieb die
# Gruppe auf dem Wert, den die Dateien beim Kopieren aus dem
# Entpackungsordner hatten - meist root. Kodi laeuft als kodi:kodi und
# konnte in solchen Verzeichnissen keine Addon-Daten schreiben.
# -h: einen Verweis selbst umstellen, nie sein Ziel.
chown -hR kodi:kodi /home/kodi
# Das Heimatverzeichnis muss dem Benutzer gehoeren, aber nicht der Welt
# offenstehen: darin liegen die Zugangsdaten der Medienquellen.
chmod 750 /home/kodi

# 1 bei jedem <FAIL> oben, sonst 0 - nie mehr als 1 (siehe Kopf).
if [ "$KO_FEHLER" -ne 0 ]; then
    echo "<FAIL> Mindestens ein Schritt ist fehlgeschlagen - siehe die <FAIL>-Zeilen oben."
    exit 1
fi
exit 0
