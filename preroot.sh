#!/bin/bash
# Will be executed as user "root".

# v1.0.0: Das frueher hier eingerichtete pipplware-Repository (Debian stretch)
# existiert nicht mehr, und "apt-key" wurde in Debian bookworm entfernt -
# beides wuerde die Installation auf LoxBerry 4 abbrechen lassen.
# Kodi wird jetzt direkt aus dem Standard-Repository von Raspberry Pi OS /
# Debian installiert (siehe dpkg/apt).

# Altes pipplware-Repo entfernen, falls von einer frueheren Version vorhanden
if [ -f /etc/apt/sources.list.d/kodi.list ]; then
    echo "<INFO> Entferne veraltetes pipplware-Repository (kodi.list)"
    rm -f /etc/apt/sources.list.d/kodi.list
fi

# HIER STAND BIS 1.0.0 ausserdem ein auskommentierter Block, der
# websocat_linuxarm32 von GitHub nach /usr/local/bin geladen haette - ein
# alter Versuch, mit Kodi unmittelbar per WebSocket zu sprechen. Ersatzlos
# entfernt: er war nie in Betrieb, band eine Fremddatei aus dem Netz in ein
# Systemverzeichnis ein und liess beim Lesen offen, ob das Plugin so etwas
# tut oder nicht.
#
# Ebenfalls entfallen sind rund vierzig Zeilen aus der LoxBerry-Vorlage, die
# lediglich die Pfadvariablen ($PCGI, $PTEMPL, $PSBIN ...) zugewiesen und
# anschliessend ausgegeben haben. Benutzt wurde davon nichts.

exit 0
