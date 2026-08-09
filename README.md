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

## Änderungen in 1.1.0

### Bestätigt und behoben

- **`$ARGV1\_upgrade`** in `preupgrade.sh` und `postupgrade.sh` → `${ARGV1}_upgrade`.
  Zusätzlich liegt die Sicherung nicht mehr unter `/tmp` — das ist auf dem
  LoxBerry eine Ramdisk, und dieses Plugin setzt `REBOOT=true`: zwischen den
  beiden Schritten kann planmäßig ein Neustart liegen.
- **`cp` ohne Existenzprüfung.** Gab es noch keine Konfiguration, meldete das
  Installationsprotokoll „No such file or directory". Jetzt in `if [ -d … ]`
  gekapselt, in beide Richtungen.
- **`chown -R kodi /home/kodi`** → `chown -R kodi:kodi`, dazu `chmod 750` (darin
  liegen die Zugangsdaten der Medienquellen).
- **`ARCHITECTURE="raspberry"`** → ohne Anführungszeichen.
- **SysVinit → systemd.** Das 168-Zeilen-Skript unter `/etc/init.d/kodi` ist
  ersatzlos entfallen; an seiner Stelle steht eine `kodi.service`. Der Grund ist
  konkreter als „veraltet": der `systemd-sysv-generator` kennt die Abhängigkeiten
  nur aus den LSB-Kopfzeilen, und dort standen `$remote_fs` und `$syslog` —
  ausgerechnet Grafik und Ton, worauf Kodi wirklich wartet, fehlten. Die Unit
  wartet auf `sound.target` und `systemd-udev-settle` und startet nach einem
  Absturz neu (begrenzt auf fünf Versuche in zwei Minuten).
- **`VO_Kodi_V1.xml`, Zeile 26.** Genau wie beschrieben: `CmdOn="Application.SetMute"`
  mit dem JSON im ungenutzten `CmdOnHTTP`. Über `tcp://` schickt Loxone nur
  `CmdOn` — Kodi bekam einen Text, den es nicht auswerten kann, und der Befehl
  schlug lautlos fehl. Verschoben und nachgemessen: 26 Befehlsfelder, alle
  enthalten jetzt gültiges JSON-RPC 2.0, kein `CmdOn*HTTP` mehr gefüllt.
- **udev-Regeln.** Die `tty`-Zeilen sind entfernt — `KERNEL==tty[0-9]*, GROUP=tty,
  MODE=0660` in der einen und `MODE=0666` in der anderen Datei überschrieben die
  systemweiten Rechte aller Terminalschnittstellen, an denen auf einem LoxBerry
  auch RS485- und DMX-Adapter anderer Plugins hängen. Die Gruppenzuordnung in
  `postroot.sh` ist der vorgesehene Weg.

  *Nebenbefund:* beide Regeldateien wurden bis 1.0.0 **überhaupt nicht
  installiert** — weder `postroot.sh` noch `postinstall.sh` haben sie je nach
  `/etc/udev/rules.d/` kopiert. Sie lagen wirkungslos im Paket. Seit 1.1.0 werden
  sie eingespielt, und deshalb mussten sie erst richtig werden.

- **`eval { … or warn … }` in `elevatedhelper.pl`.** Der Befund stimmt genau:
  `warn` wirft keine Ausnahme, das `eval` konnte nie auslösen, die Schleife las
  aus einem ungültigen Dateizeiger weiter, `$@` blieb leer — und die Funktion
  meldete Erfolg. Die Oberfläche zeigte also an, die Codec-Lizenz sei eingetragen,
  obwohl die Datei unberührt blieb. Jetzt `die`, und die `config.txt` wird über
  eine Zwischendatei mit `rename()` ersetzt.

### Nicht bestätigt

- **Fehlende Root-Rechte („der Showstopper").** Trifft auf diese Fassung nicht
  zu. Die Oberfläche ist **PHP**, nicht Perl-CGI, und sie ruft `sudo
  bin/elevatedhelper.pl` auf; die passende Regel liegt seit jeher in
  `sudoers/sudoers`. `systemctl` und die `config.txt` werden also sehr wohl mit
  den nötigen Rechten angefasst — genau nach dem Muster, das der Prüfer
  empfiehlt. Der Befund beschreibt die Vorgängerfassung 0.1.2.
- **`python3-paho-mqtt` in `dpkg/apt`.** Das Callback-Addon benutzt keine
  MQTT-Bibliothek, sondern ein UDP-Paket an das LoxBerry-MQTT-Gateway
  (`send_raw_udp` in `default.py`) — es braucht nur `socket` aus der
  Standardbibliothek. Das Paket zu installieren nützte auch nichts: Addons laufen
  im eingebauten Python von Kodi, das die Debian-Systempakete nicht
  zwangsläufig sieht. In `dpkg/apt` steht jetzt eine Begründung, damit die Frage
  nicht wiederkehrt.

### Selbst gefunden

- **Der privilegierte Helfer prüfte seine Eingaben nicht.** `elevatedhelper.pl`
  läuft über sudo als root und schreibt in die Bootkonfiguration; die
  sudoers-Regel erlaubt beliebige Argumente. `$R::value` ging ungeprüft in die
  Ersetzung — ein Wert mit einem Zeilenumbruch hätte zusätzliche Zeilen in die
  `config.txt` geschrieben, etwa ein `dtoverlay`, das beim nächsten Start geladen
  wird. Die Oberfläche maskiert zwar, aber darauf darf sich der privilegierte
  Teil nicht verlassen: er ist die Grenze, an der die Rechte wechseln. Jetzt
  Positivliste für `key` und ein Muster für `value`.
- **Kein `uninstall`-Skript.** Nach dem Entfernen des Plugins blieben der Dienst,
  die udev-Regeln und die sudoers-Regel zurück — Kodi startete beim nächsten
  Systemstart weiter. Jetzt vorhanden; die gesicherte `config.txt` wird dabei
  zurückgelegt. Benutzer `kodi` und `/home/kodi` bleiben bewusst stehen (darin
  liegt die Mediathek), mit Hinweis, wie man sie von Hand entfernt.
- **Reiter ohne JavaScript.** Sie waren `<div>`-Elemente, `ko-active` setzte nur
  das JavaScript, und alle Bereiche stehen auf `display:none` — ohne JavaScript
  war die Seite leer. Jetzt echte Verweise mit serverseitigem `ko-active`.

### Abspaltung: das Plugin heißt jetzt Kodi NG

Ab 1.1.0 trägt diese Fortführung eine **eigene Kennung** und einen eigenen
Namen: `[PLUGIN] NAME` und `FOLDER` lauten `kodi_ng`, `TITLE` ist `Kodi NG`,
und im `[AUTHOR]`-Block steht eine Projektkennung statt des Originalautors.

**Warum.** Die Felder unter `[AUTHOR]` sind kein Urhebervermerk, sondern das,
woraus LoxBerry zusammen mit `[PLUGIN] NAME` die Kennzahl bildet, unter der es
Installation und Updates führt. Bis 1.0.0 stand dort der Autor der Fassung von
2018 mit seiner privaten Mailadresse, während `RELEASECFG` bereits hierher
zeigte. Fehlerberichte zu dieser Fassung wären bei ihm gelandet. Die
urheberrechtliche Nennung steht jetzt in `NOTICE` und im Kopf der Oberfläche.

**Die Systemdateien wandern mit.** Dieses Plugin legt Dateien außerhalb seines
eigenen Verzeichnisses an:

| bisher | ab 1.1.0 |
|---|---|
| `/etc/systemd/system/kodi.service` | `kodi_ng.service` |
| `/etc/udev/rules.d/99-kodi.rules` | `99-kodi_ng.rules` |
| `/etc/udev/rules.d/10-kodi-permissions.rules` | `10-kodi_ng-permissions.rules` |

Wären die Namen geblieben, hätte die Deinstallation dieser Fassung die Dateien
einer fremden Installation mitgenommen. `uninstall/uninstall` entfernt deshalb
nur noch die eigenen.

**Nicht umbenannt** wurden der Systembenutzer `kodi` und `/home/kodi`. Dort
liegen Medienbibliothek, Wiedergabestände und die Zugangsdaten der Quellen —
ein neuer Benutzer würde das alles zurücklassen. Die Unit läuft weiter als
`User=kodi`.

**An Loxone ändert sich nichts.** Loxone spricht über `tcp://loxberry:9090`
direkt mit Kodi, nicht mit dem Plugin. Der Ordnername kommt in keiner
Loxone-Adresse vor — die virtuellen Ausgänge bleiben unverändert.

**Kein Parallelbetrieb.** Es gibt nur ein Kodi auf dem Gerät, und es lauscht auf
Port 9090. `postroot.sh` legt eine vorhandene `kodi.service` deshalb beiseite
(als `kodi.service.vor-ng`, für systemd unsichtbar) — sie wird abgeschaltet,
aber nicht gelöscht, weil sie zu einer fremden Installation gehören könnte.

**Für eine bestehende Installation heißt das:** LoxBerry sieht ab 1.1.0 ein
anderes Plugin. Ein vorhandenes 1.0.0 bekommt dieses Update *nicht* angeboten —
einmal neu installieren, danach läuft das Auto-Update von selbst. Die
Einstellungen des Plugins (Autostart, Codec-Lizenzen, MQTT) sind einmal neu zu
setzen; Kodis eigene Bibliothek bleibt unberührt, weil `/home/kodi` bleibt.

### Herkunft und Lizenz

Grundlage ist [LoxBerry-Plugin-Kodi von **Christian Fenzl (christianTF)**](https://github.com/christianTF/LoxBerry-Plugin-Kodi),
Stand 0.1.2 von 2018, Apache-Lizenz 2.0. Die Liste der Änderungen steht in
`NOTICE` (Apache-Lizenz 2.0, Abschnitt 4 b). Das mitgelieferte `bin/kodi-rpc`
steht unter der GPL v3; sein Lizenztext liegt als `bin/kodi-rpc.LICENSE.txt`
daneben.

### Aufgeräumt (Altbestand im Paket)

- `webfrontend/htmlauth/icon_128.png` — bytegleiche Zweitfassung von
  `icons/icon_128.png`, nirgends referenziert. Icons gehören nach `icons/`.
- `bin/kodi-rpc-master/` — eine vollständige, verschachtelte Kopie des
  Fremd-Repositories; die enthaltene `kodi-rpc` war bytegleich mit
  `bin/kodi-rpc`. Der Ordner ist ein Artefakt eines GitHub-ZIP-Downloads.
  Entfernt — die **Lizenz** des Fremdwerkzeugs ist als
  `bin/kodi-rpc.LICENSE.txt` neben dem Skript erhalten geblieben, sie muss
  mitgeliefert werden.
- `data/addons/service.callback.handler/__pycache__/default.cpython-310.pyc` —
  ein übersetzter Python-Zwischenstand, entstanden beim Ausprobieren mit
  Python 3.10 und versehentlich mitgepackt. Entfernt; eine `.gitignore`
  verhindert die Wiederkehr.
- `postinstall.sh` bestand aus 67 Zeilen auskommentiertem Vorlagentext, der
  aus dem Plugin *LoxBerry Backup* stammte — samt einer Meldung über dessen
  Zeitplansystem. Ersetzt durch das Wenige, was ohne Rootrechte zu tun ist.
- `preroot.sh` enthielt rund vierzig Zeilen aus der LoxBerry-Vorlage, die
  Pfadvariablen zuwiesen und wieder ausgaben, ohne sie zu benutzen.
- **Die Hilfeseite war nie angeschlossen.** `templates/help/kodi_main.html`
  und die zugehörigen Schlüssel lagen im Paket, aber `LBWeb::lbheader()`
  bekam als drittes Argument einen leeren Text — das Fragezeichen oben rechts
  hat die Seite nie angezeigt. Jetzt verbunden, samt deutscher Fassung.

**Kein `webfrontend/html/`:** das ist richtig so und keine Lücke. Dort liegt
bei LoxBerry der *unangemeldete* Bereich. Dieses Plugin hat keinen Endpunkt,
den der Miniserver ohne Anmeldung aufrufen müsste — Loxone spricht Kodi direkt
über `tcp://…:9090` an (siehe `VO_Kodi_V1.xml`), nicht über den LoxBerry. Einen
leeren Ordner anzulegen brächte nichts; Git würde ihn ohnehin nicht mitführen.

