# LoxBerry-Plugin-Kodi

Installiert Kodi direkt auf dem LoxBerry (Raspberry Pi) und verbindet es mit
Loxone: Ereignisse (Start/Stopp/Pause, Titel, Bildschirmschoner) gehen per
**UDP** an den Miniserver und/oder per **MQTT** über das LoxBerry MQTT Gateway;
gesteuert wird Kodi über `kodi-rpc` (JSON-RPC) bzw. die mitgelieferte
Virtuelle-Ausgänge-Vorlage (`data/VO_Kodi_V1.xml`).

## Version 1.0.0 – was ist neu (LoxBerry 4)

- **Installation repariert:** Das tote pipplware-Repository (Debian stretch)
  und der in bookworm entfernte `apt-key`-Aufruf brachen die Installation ab.
  Kodi kommt jetzt aus dem Standard-Repository von Raspberry Pi OS.
- **Callback-Addon auf Python 3 portiert** – das alte Addon war Python 2 und
  lief seit Kodi 19 „Matrix" (2021) auf keinem aktuellen Kodi mehr.
- **MQTT integriert:** Das Addon kann Ereignisse zusätzlich (oder statt UDP)
  an das LoxBerry MQTT Gateway senden – retained, Topics `kodi/<ereignis>`.
  Einstellungen direkt im Kodi-Addon (LoxBerry-IP + Gateway-UDP-Port).
- **bookworm-Pfade:** `config.txt` wird unter `/boot/firmware/` gefunden;
  Codec-Lizenzabfragen sind auf Pi 4/5 tolerant („n/a", dort nicht mehr nötig);
  `gpu_mem` wird auf Pi 4/5 nicht mehr gesetzt.
- Upgrade-Fehler behoben (Addon wurde bei Updates nach `addons/addons/` kopiert),
  falsche AUTOUPDATE-URLs entfernt (zeigten auf ein fremdes Repository),
  SVG-Icon ergänzt (PNG als Fallback).

## Ereignisse

`kodi_started`, `kodi_stopped`, `music_/movie_/episode_started|paused|resumed|stopped`,
`movie_title=<Titel>`, `screensaver=on/off`

- **UDP:** Virtueller UDP-Eingang am Miniserver (Standard-Port 7000).
- **MQTT:** Topics `kodi/event`, `kodi/movie_title`, `kodi/screensaver`, … –
  im MQTT Gateway mit `kodi/#` abonnieren.

## Hinweise

- Kodi-Weboberfläche: Port 8080 (in Kodi unter Dienste aktivieren, für kodi-rpc nötig).
- Nach der Installation ist ein Neustart erforderlich (GPU-/Gruppenrechte).

Forum: https://www.loxforum.com/forum/projektforen/loxberry/plugins/150094-kodi-plugin-f%C3%BCr-loxberry
