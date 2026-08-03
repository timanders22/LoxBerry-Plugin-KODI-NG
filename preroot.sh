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

exit 0
