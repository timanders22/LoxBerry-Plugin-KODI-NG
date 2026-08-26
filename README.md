# LoxBerry-Plugin-Kodi NG

Version 1.2.0 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

Installiert Kodi direkt auf dem LoxBerry (Raspberry Pi) und verbindet es mit
Loxone. Zustand und Ereignisse gehen per **MQTT** über das LoxBerry MQTT
Gateway an den Miniserver und auf Wunsch zusätzlich per **UDP**; gesteuert wird
Kodi über JSON-RPC. Die Importdateien für Loxone Config erzeugt das Plugin
selbst.

## Version 1.2.0 – was ist neu

Diese Fassung schließt drei Lücken, die alle dieselbe Eigenschaft hatten: sie
sahen im Betrieb nicht wie ein Defekt aus.

### Der Statussender – das Plugin meldet jetzt von sich aus

Bis 1.1.9 hatte dieses Plugin **keinen Cron, keinen Dienst und keinen
Endpunkt**. Die drei Themen `kodi/dienst`, `kodi/autostart` und
`kodi/zeitstempel` wurden an genau einer Stelle veröffentlicht: im Reiter
Test, wenn jemand auf *Test-Ereignis an MQTT senden* drückte. Die
mitgelieferte Loxone-Vorlage legte für eben diese Werte virtuelle Eingänge an –
die im Betrieb nie einen Wert bekamen. In Loxone sieht das aus wie „Kodi ist
aus“, nicht wie „hier sendet niemand“.

Seit 1.2.0 gibt es `bin/kodi_ng_status.php`, angestoßen aus
`cron/cron.01min`. Der Abstand zwischen zwei Sendungen steht in der
Konfiguration (`sender_takt`, Vorgabe 300 s) und nicht im Ordnernamen des
Cron – der Lauf entscheidet selbst, ob er etwas tut.

**Der Sender ist ab Werk ausgeschaltet.** Ein Update darf einer bestehenden
Anlage nicht ungefragt Themen in den Broker legen. Der Reiter Test und der
Reiter *Einbindung in Loxone* sagen deutlich, dass er aus ist – damit niemand
vergeblich auf Werte wartet.

### Die Themenliste stimmt jetzt mit dem überein, was gesendet wird

Die Tabelle im Reiter *Einbindung in Loxone* nannte `kodi/status` mit den
Werten *play, pause, stop* und `kodi/titel`. **Beides gab es nicht.** Das
Callback-Addon sendet `kodi/event`, `kodi/movie_title`, `kodi/music_title`,
`kodi/episode_title` und `kodi/screensaver` – von denen keines in der Tabelle
stand. Der Erläuterungssatz darunter („die letzten beiden liefert das
Kodi-Addon“) war damit ebenfalls falsch.

Jetzt kommt die Tabelle aus `ko_themen()`, und eine Zeile der Selbstprüfung
hält sie gegen den Sendecode beider Seiten. `kodi/wiedergabe` und
`kodi/titel` gibt es seit 1.2.0 wirklich – das Plugin liest sie über JSON-RPC
aus Kodi.

### Sichern und Zurückspielen über zwei Knöpfe

Im Reiter *Einstellungen* stehen zwei Knöpfe: einer lädt eine Textdatei
herunter, der andere nimmt sie entgegen. Zweck ist der **Umzug** auf einen
zweiten LoxBerry, nicht die Sicherung gegen Verlust – dafür gibt es die
Zweitschrift neben dem Konfigurationsordner.

Gesichert wird deshalb mehr als `kodi.json`. Das mühsam Eingerichtete liegt
bei diesem Plugin an vier Orten:

| Was | Wo es liegt | in `kodi.json`? |
|---|---|---|
| MQTT-Thema, Kodi-Adresse, Port, Zugangsdaten | `config/plugins/kodi_ng/kodi.json` | ja |
| Autostart | `systemctl is-enabled kodi_ng` | nein |
| Codec-Lizenzen MPEG2/VC1 | `/boot/firmware/config.txt` | nein |
| Addon: LoxBerry-IP, UDP-Port, Gateway-Port, Thema, Startlautstärke | Kodis Addon-Einstellungen | nein |

Die sieben Addon-Felder werden in Kodi selbst gesetzt, über die Fernbedienung –
vier davon gehören dem Plugin, drei dem Anwender; gesichert werden alle sieben.
Sie sind das, was beim Umzug wirklich weh tut; wer nur `kodi.json` sichert,
hat die Funktion halbiert.

**Die Datei trägt damit ein Geheimnis** – das Kodi-Passwort und die Adressen
der Anlage. Der Text am Knopf sagt das: wie ein Passwort behandeln, nicht in
ein Forum hängen, nicht an einen Fehlerbericht heften.

**Die Codec-Lizenzen sind gerätegebunden.** Sie gelten für genau eine
Seriennummer. Sie stehen deshalb *mit* dieser Seriennummer in der Sicherung
und werden nur zurückgespielt, wenn der Anwender es ausdrücklich anhakt **und**
die Seriennummer übereinstimmt. Weicht sie ab, ist das eine Beanstandung, kein
stilles Übergehen.

Die Verarbeitung folgt den sieben Punkten des Hausstandards: vollständig aus
der Vorgabenliste heraus, lesbarer Kopf mit Datum, eine halb gültige Datei
überschreibt nichts, alle Beanstandungen auf einmal, unbekannte Schlüssel sind
eine Beanstandung, 64 kB Obergrenze und `is_uploaded_file()` zuerst, Dienst
nachziehen und sagen, was mit ihm geschehen ist. Zusätzlich läuft jeder
zurückgespielte Wert durch **dieselbe** Prüfung wie das Formular – zwei
getrennte Prüfungen wären zwei Wahrheiten.

### Das MQTT-Gateway hat zwei Fassungen, und das Plugin liest jetzt welche

Der Satz *„Ohne diesen Eintrag kommt am Miniserver nichts an“* gilt für
Gateway **V1**. Unter **V2** schaltet der LoxBerry-Kern auf der
Abonnement-Seite die Knöpfe ab – dort ist gar nichts mehr einzutragen, und der
unbedingte Satz schickte jeden V2-Anwender zu einem Eingabefeld, das es nicht
gibt.

`ko_mqtt_gateway_info()` liest `Mqtt.Gatewayversion` aus `general.json` und
gibt Autostart, Fassung und UDP-Eingang aus **einem** Dateizugriff. Ist die
Fassung nicht lesbar, stehen beide Sätze da – einen von beiden zu behaupten
wäre für die Hälfte der Anlagen falsch.

**Und der ganze Schritt 3 hängt mit daran.** Der Satz „Im Gateway die
gewünschten Themen dem Miniserver zuweisen“ beschreibt die V1-Bedienung und
stand außerhalb jeder Verzweigung; er ist jetzt in `SCHRITT3_V1` und
`SCHRITT3_V2` geteilt. Dasselbe im Hilfetext, der als statisches HTML gar
nicht verzweigen kann: `K06` nennt jetzt beide Fälle und verweist für die
Entscheidung auf den Reiter, der die Fassung wirklich gelesen hat.

Eine eigene Prüfzeile im Reiter Test zählt nach, ob der V1-Satz außerhalb
seines Schlüssels noch irgendwo auftaucht. `Werkzeuge/gateway_fassung_reihe.py`
findet das **nicht**: es fragt nur, ob ein Plugin `Gatewayversion` überhaupt
liest – sobald es das tut, ist es dort grün, egal wie oft der Satz danach
noch unbedingt dasteht.

### Die Einstellungen des Kodi-Addons aus der Oberfläche

Vier Felder des Addons gehören dem Plugin: ob per MQTT gesendet wird, an
welche Adresse, an welchen Port und unter welchem Thema. Bis 1.1.9 wurden sie
in Kodi von Hand gesetzt und liefen unbemerkt auseinander – wer das Thema im
Plugin änderte, bekam eine Themen-Tabelle auf den neuen Namen, während das
Addon weiter unter dem alten sendete.

Der Reiter MQTT zeigt jetzt Ist und Soll nebeneinander und setzt sie auf
Knopfdruck. Zwei Dinge daran sind Absicht:

* **Es wird abgewiesen, solange Kodi läuft.** Kodi schreibt seine
  Einstellungsdatei beim Beenden aus dem Speicher zurück und machte die
  Änderung damit ungeschehen. Wer den Haken *Kodi dafür anhalten* setzt, hält
  das Plugin Kodi an, schreibt und startet es wieder.
* **Was das Plugin selbst nicht weiß, schreibt es nicht.** Ist die eigene
  IP-Adresse nicht zu ermitteln, wird gar nichts gesetzt und das gesagt. Eine
  geratene Adresse im Addon wäre schlimmer als keine: es sendete ins Leere,
  und die Oberfläche behauptete, es sei eingerichtet.

Der privilegierte Helfer hat dafür zwei neue Aktionen (`addonread`,
`addonwrite`). Sie brauchen ihn, weil `/home/kodi` seit 1.1.0 `chmod 750`
trägt und `kodi:kodi` gehört – der Webserver läuft als `loxberry` und kommt
dort nicht hinein. Die Positivliste für `key`/`value` wurde dabei **nicht**
gelockert; jedes Addon-Feld bekommt sein eigenes Muster. Eine gemeinsame,
weitere Liste hätte den Lizenzfeldern still das erlaubt, was nur die
Addon-Felder brauchen – und die Lizenzfelder schreiben in die
Bootkonfiguration.

### Formularmerkmal gegen fremde Absender

Bis 1.1.9 trug **keines der elf Formulare** ein Merkmal. `htmlauth/` schützt
gegen den unangemeldeten Aufruf, nicht dagegen, dass der Browser eines
*angemeldeten* Bedieners ein Formular abschickt, das auf einer fremden Seite
steht – die Anmeldung geht dabei automatisch mit. Erreichbar waren damit unter
anderem: den Kodi-Dienst anhalten, die Konfiguration überschreiben, das
Protokoll leeren, und über die Lizenzfelder ein Schreibvorgang in die
Bootkonfiguration über sudo.

Jetzt: ein abgeleitetes Merkmal aus `config/plugins/kodi_ng/ko_formkey`
(16 Zufallsbytes, `chmod 0600`, atomar angelegt), **ein** Wachposten vor allen
Handlern, der `$_POST` *und* `$_FILES` leert, und eine Prüfzeile, die
nachzählt, ob jedes Formular es mitschickt. Das Merkmal steht bewusst in einer
eigenen Datei neben der Konfiguration – in der Sicherungsdatei hätte es nichts
zu suchen.

### Selbstprüfung im Reiter Test

Der Reiter hatte sechs Knöpfe und keine einzige Prüfzeile. Jetzt sind es
sechzehn Zeilen: Helfer, Dienst, Autostart, Kodi über JSON-RPC, Cron-Eintrag,
Statussender samt Herzschlag, Konfigurationslage, Vollständigkeit (fehlende
*und* fremde Schlüssel), MQTT-Gateway, Addon-Abgleich, Themenliste, Aussagen
zur Gateway-Fassung, Reiter-Kongruenz, Formularmerkmal, Wohlgeformtheit beider
Vorlagen und die Rundreise der Sicherung.

Drei Eigenschaften sind dabei Absicht:

* Ein **grauer Punkt** heißt „konnte nicht gemessen werden“ und ist kein
  Haken. Über einen Sender, der gar nicht laufen soll, wird kein Herzschlag
  beurteilt.
* Jede Zeile, die die eigene Datei liest, **nennt die Zahl der angesehenen
  Stellen**. Eine Null ist dann kein „in Ordnung“, sondern der Hinweis, dass
  nichts gemessen wurde.
* Der Aufruf über das Netz ist **zwischengespeichert** (300 s). Alle Reiter
  werden bei jedem Seitenaufbau mitgerendert; ohne das liefe die Abfrage bei
  jedem Klick.

### Kleineres, das mitgegangen ist

* **`@socket_create()` ohne `php-sockets`.** Die Oberfläche benutzte die
  Socket-Erweiterung, `dpkg/apt` führte sie nicht. Ihr Fehlen ist kein
  abfangbarer Fehler, sondern ein fataler; das `@` hätte daran nichts
  geändert. Jetzt `stream_socket_client()` – das gehört zum PHP-Kern.
* **Die Zweitschrift überlebte die Deinstallation.** `uninstall/uninstall`
  räumte `data/plugins/<ordner>.upgrade_sicherung` ab, aber nicht
  `config/plugins/kodi_ng.backup.json`. Eine spätere *Neu*installation holte
  daraus stillschweigend die alte Konfiguration zurück. Jetzt weg – und das
  Skript zählt am Ende nach, was es stehengelassen hat.
* **`data/VO_Kodi_V1.xml` ist ersatzlos entfallen.** Sie war gegenüber dem
  Erzeuger in der Oberfläche veraltet – ohne `<Info>`-Zeile, ohne `HintText`,
  mit BOM und mit LF statt CRLF –, und die Oberfläche verwies ausdrücklich auf
  sie. Sie neu zu erzeugen und weiter mitzuliefern wäre wieder ein zweiter
  Stand desselben Inhalts gewesen: einer, der beim nächsten Eingriff still
  zurückbleibt, und einer, den niemand braucht – der Knopf im Reiter
  *Einbindung in Loxone* erzeugt dieselbe Datei mit **Ihrer** Kodi-Adresse
  statt des Platzhalters `loxberry`.
* **`bin/kodi-rpc.conf` entfernt.** `kodi-rpc` bevorzugt die Datei *neben dem
  Skript* vor `-c` und vor `$HOME/.config/` (gemessen an Zeile 118 ff. des
  Skripts). Die mitgelieferte trug fest `localhost:8080` ohne Zugangsdaten und
  hätte jede andere Einstellung still überstimmt. `kodi-rpc` selbst bleibt –
  es ist ein Werkzeug für die Befehlszeile, das dieses Plugin nicht aufruft.
* **Der Ordner-Rückfall hieß `kodi`** – der Name *vor* der Umbenennung – in
  `postinstall.sh`, `preupgrade.sh` und `postupgrade.sh`. Griff er, arbeiteten
  die Skripte in einem Verzeichnis, das es nicht mehr gibt. Jetzt `kodi_ng`,
  und der Rückfall im PHP ist zusätzlich bewacht: er wird nur genommen, wenn
  dort nichts liegt oder schon die eigene Konfigurationsdatei.
* **Der Titel im Callback-Addon.** `MyPlayer.title` wurde ausschließlich für
  Filme gesetzt. Bei Musik und Serien blieb der Titel des zuletzt gespielten
  Films stehen und ging unter `music_title` bzw. `episode_title` hinaus – in
  Loxone stand der Filmtitel von gestern am Radioprogramm. Jetzt je Art aus
  der passenden Quelle, und beim Stoppen wird der Titel geräumt.
* **`ini_set('display_errors', '1')`** in der Oberfläche zeigte im Fehlerfall
  Pfade und Quelltext. Jetzt `0`.
* **Der Hausstandard-Dialekt.** Die Oberfläche führte `sm-pane` statt
  `sm-seite`, `sm-small` statt `sm-hilfe` und ein eigenes `sm-alert`-System;
  `sm-kacheln`, `sm-breit`, `sm-an`/`sm-aus` und `sm-pre` fehlten ganz. Damit
  griffen die Proben des Hausstandards hier ins Leere. Jetzt der Stand aus
  `VORLAGE_hausstandard.css.html`.
* **Tote Dateien entfernt:** `templates/help/kodi_main.html`,
  `templates/lang/kodi_main_de.ini` und `kodi_main_en.ini` – die
  Hilfe-Zweitfassung aus 0.1.2, auf die seit 1.1.0 nichts mehr verweist.

### Was die zweite Sicht am neuen Code gefunden hat

Der Stand oben war fertig, die statische Prüfkette grün und sechs eigene
Prüfstände liefen sauber durch. Eine zeilenweise Gegenlesung *desselben*
neuen Codes hat trotzdem sechzehn Befunde gebracht — davon vier, die vor der
Veröffentlichung stehen bleiben mussten. Sie stehen hier, weil eine Fassung,
die ihre eigenen Fehler verschweigt, beim nächsten Mal dieselben macht.

**Der schwerste: eine beschädigte `kodi.json` wurde beim bloßen Aufruf der
Seite durch Vorgabewerte ersetzt — samt Zweitschrift.** `ko_json_lesen()`
bildet „nicht auswertbar" auf denselben Wert ab wie „leer", nämlich ein leeres
Feld. In `ko_cfg_vervollstaendigen()` galten damit *alle* Schlüssel als
fehlend, die Wache `is_file()` trug nicht (die Datei war ja da), und beide
Kopien wurden überschrieben. Gemessen mit einem einzigen GET: Adresse, Thema
und Kodi-Passwort in beiden Dateien fort, die `.kaputt`-Beweiskopie nie
angelegt — und die Selbstprüfung meldete **„Ist die Konfiguration heil? ja"**.
Der ganze `kaputt`-Zweig war aus der Oberfläche heraus unerreichbar. Jetzt
entscheidet die Lage, bevor irgendetwas geschrieben wird; nachgemessen in
beide Richtungen.

**Ein Symlink in Kodis Addon-Verzeichnis hätte `root` gegeben.** Der Name der
Zwischendatei beim Schreiben der Addon-Einstellungen ist vorhersagbar, und das
Verzeichnis gehört `kodi:kodi` — dort darf jedes Kodi-Addon schreiben. Ein
blankes `open('>')` als root wäre einem vorab angelegten Symlink gefolgt, hätte
die Zieldatei gekürzt, beschrieben und an `kodi:kodi` übereignet. Jetzt
`sysopen` mit `O_EXCL|O_NOFOLLOW` und `fchmod`/`fchown` über den Dateizeiger.

**Kodi 19 schreibt Einstellungen, die auf ihrem Vorgabewert stehen, als
`<setting id="x" default="true">wert</setting>`** — mit einem Attribut zwischen
`id` und `>`. Die Lesemuster verlangten dort unmittelbar `>`. Gemessen an einer
Datei mit sieben Feldern: gelesen wurden **drei**. Das allein wäre eine falsche
Anzeige; schlimmer ist die zweite Stufe, denn beim Schreiben übernimmt das
Plugin nur das Gelesene und schreibt die Datei neu — die vier übersehenen
Felder wären **still verloren** gegangen. Jetzt 7 von 7, in beide Richtungen
nachgemessen.

**Nach dem Speichern zeigte die Seite den Stand von vorher.** `ko_status()`
merkt sich seine Antwort; im Speichern-Zweig wurde danach der Autostart
geschaltet, ohne den Merker aufzufrischen. Gemessen: Haken setzen, Speichern —
Kachel „Autostart aus", Kästchen leer, Prüfzeile „nein", während der Helfer
sehr wohl geschaltet hatte. Der Anwender hakt erneut an und schaltet damit
wieder zurück.

Dazu zwölf kleinere: `CGI::header()` kann dem JSON einen Kopfblock voranstellen
(beidseitig aufgelöst — der Helfer gibt ihn nur noch unter einem Webserver aus,
und der Aufrufer schneidet ihn trotzdem ab); `action=service` und
`kodiautostart` meldeten „OK", ohne dass etwas gelaufen war; ein Feld statt
einer Zeichenkette in `kodi.json` riss unter PHP 8.4 die ganze Seite um; ein
`sicherung[]` beim Zurückspielen ebenso; fehlte `language_en.ini` bei
eingestelltem Englisch, standen 92 rohe Schlüssel da *ohne* die dafür gebaute
Warnung; das Plugin ließ MQTT-Themen zu, die der Helfer anschließend abwies;
das Addon räumte beim Stoppen das *falsche* Thema (`playing_type()` ist dort
schon `unknown`); ein geleertes Adressfeld ließ sich im Addon zur Laufzeit
nicht abschalten; `uninstall` meldete „alles entfernt", wenn es mangels
Argumenten gar nichts prüfen konnte; ein Wert, der genau `-` lautet, kam aus
der Sicherung leer zurück; `gpu_mem` und die Codec-Lizenzen landeten am
Dateiende und damit *innerhalb* eines bedingten Abschnitts der `config.txt`;
und in vier Texten stand „fünf Addon-Felder", gezählt sind es sieben.

Zwei Verdachtsmomente haben sich **nicht** bestätigt und stehen deshalb auch
hier: die Sperre des Statussenders wird in jedem Ausstiegspfad freigegeben, und
das `grep`-Muster im Cron-Eintrag trifft die Schreibweise von
`JSON_PRETTY_PRINT` — beides nachgemessen statt angenommen.

### Was Sie nach dem Update tun müssen

1. **Reiter Einstellungen:** den Statussender einschalten, wenn Sie ihn wollen.
   Er ist ab Werk aus.
2. **Reiter MQTT:** einmal auf *Addon-Einstellungen setzen* drücken (mit dem
   Haken *Kodi dafür anhalten*, falls Kodi läuft). Danach führen Plugin und
   Addon dasselbe Thema.
3. **Reiter Einbindung in Loxone:** beide Vorlagen neu erzeugen. Die Namen der
   virtuellen Eingänge haben sich geändert – `status` und `titel` gab es nie,
   dafür kommen `erreichbar`, `herzschlag` und `wiedergabe` dazu. Bestehende
   Eingänge auf `status` bleiben leer und können entfernt werden.
4. **Reiter Test:** die Selbstprüfung durchsehen.

### Was in dieser Fassung NICHT am Gerät erprobt ist

Bei der Entwicklung lief kein Kodi und kein LoxBerry – gearbeitet wurde gegen
die Attrappen der Prüfumgebung. Belegt heißt hier nicht erprobt, und das gehört
an genau diese Stelle:

* **Der JSON-RPC-Weg zu Kodi** (`rpc_ein`, ab Werk aus). Die Methodennamen
  stehen so in Kodis Dokumentation; ob dieses Kodi sie annimmt, konnte
  niemand messen. Was sich nicht ablesen lässt, meldet das Plugin als `-` und
  nicht als Erfolg.
* **Der Ablageort der Addon-Einstellungen**
  (`/home/kodi/.kodi/userdata/addon_data/service.callback.handler/settings.xml`).
  Das ist Kodis dokumentierte Ablage, hier aber an keinem laufenden Kodi
  nachgesehen. Findet das Plugin die Datei nicht, meldet es das und rechnet
  nicht mit Vorgaben weiter.
* **Dass MQTT-Gateway V2 die Themen von selbst erkennt.** Das steht in der
  Oberfläche eines fremden Plugins und passt zu den abgeschalteten Knöpfen im
  LoxBerry-Kern – es bleibt eine Sekundärquelle.

## Version 1.0.0 – was war neu (LoxBerry 4)

- **Installation repariert:** Das tote pipplware-Repository (Debian stretch)
  und der in bookworm entfernte `apt-key`-Aufruf brachen die Installation ab.
  Kodi kommt jetzt aus dem Standard-Repository von Raspberry Pi OS.
- **Callback-Addon auf Python 3 portiert** – das alte Addon war Python 2 und
  lief seit Kodi 19 „Matrix" (2021) auf keinem aktuellen Kodi mehr.
- **MQTT integriert:** Das Addon kann Ereignisse zusätzlich (oder statt UDP)
  an das LoxBerry MQTT Gateway senden – retained, Topics `kodi/<ereignis>`.
- **bookworm-Pfade:** `config.txt` wird unter `/boot/firmware/` gefunden;
  Codec-Lizenzabfragen sind auf Pi 4/5 tolerant („n/a", dort nicht mehr nötig);
  `gpu_mem` wird auf Pi 4/5 nicht mehr gesetzt.
- Upgrade-Fehler behoben (Addon wurde bei Updates nach `addons/addons/` kopiert),
  falsche AUTOUPDATE-URLs entfernt (zeigten auf ein fremdes Repository),
  SVG-Icon ergänzt (PNG als Fallback).

## Themen

Die verbindliche Liste steht im Reiter *Einbindung in Loxone*; sie entsteht
aus `ko_themen()`, und der Reiter Test hält sie gegen den Sendecode.

**Vom Plugin (Statussender, Cron):** `dienst`, `autostart`, `erreichbar`,
`zeitstempel`, `herzschlag`, `wiedergabe`, `titel`

**Vom Kodi-Addon:** `event`, `movie_title`, `music_title`, `episode_title`,
`unknown_title`, `screensaver`

- **MQTT:** im MQTT Gateway mit `kodi/#` abonnieren (Gateway V1) bzw. die
  Datenpunkte in den Subscriptions anhaken (Gateway V2).
- **UDP:** virtueller UDP-Eingang am Miniserver (Standard-Port 7000). Das
  Format der Addon-Ereignisse ist unverändert – in bestehenden Anlagen hängen
  Befehlserkennungen daran.

## Hinweise

- Kodi-Weboberfläche: Port 8080 (in Kodi unter Dienste aktivieren, für die
  JSON-RPC-Abfrage des Plugins nötig; neuere Fassungen verlangen dort Benutzer
  und Passwort).
- Nach der Installation ist ein Neustart erforderlich (GPU-/Gruppenrechte).
- `bin/kodi-rpc` ist ein Werkzeug für die **Befehlszeile**; das Plugin ruft es
  nicht auf. Verbindungsangaben über `-H`, `-P`, `-u`, `-p` oder eine eigene
  `~/.config/kodi-rpc.conf`.

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
über `tcp://…:9090` an, nicht über den LoxBerry. Einen leeren Ordner anzulegen
brächte nichts; Git würde ihn ohnehin nicht mitführen.
