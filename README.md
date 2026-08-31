# LoxBerry-Plugin-Kodi NG

Version 1.2.5 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

Installiert Kodi direkt auf dem LoxBerry (Raspberry Pi) und verbindet es mit
Loxone. Zustand und Ereignisse gehen per **MQTT** über das LoxBerry MQTT
Gateway an den Miniserver und auf Wunsch zusätzlich per **UDP**; gesteuert wird
Kodi über JSON-RPC. Die Importdateien für Loxone Config erzeugt das Plugin
selbst.

## Version 1.2.3 – „Aber wie komme ich zu Kodi?"

Die Nachfrage auf die Antwort oben — und sie zeigt, dass die Antwort nur halb
war. „Stellen Sie das in Kodi ein" hilft niemandem, der nicht weiß, wo Kodi
überhaupt ist.

**Kodi läuft auf dem Bildschirm am LoxBerry.** Der Dienst startet
`kodi-standalone` auf der Konsole `/dev/tty7` mit `PAMName=login`; die Ausgabe
geht auf den HDMI-Ausgang. Kodis Oberfläche erscheint also auf dem
angeschlossenen Fernseher oder Monitor und wird mit einer USB-Tastatur, einer
Fernbedienung oder über HDMI-CEC mit der Fernbedienung des Fernsehers bedient.
Vom Handy geht *Kore* (Android) beziehungsweise *Kodi Remote* (iOS).
**Kodis Einstellungsmenü gibt es aber nur dort** — weder eine Handy-App noch
eine Weboberfläche im Browser kann es öffnen.

**Und das Wichtigste: für dieses Plugin muss dort nichts eingestellt werden.**
Das Plugin bringt `data/advancedsettings.xml` mit, und `postroot.sh` legt sie
unter `/home/kodi/.kodi/userdata/` ab — darin steht `<webserver>true</webserver>`,
`<esallinterfaces>true</esallinterfaces>` und `<zeroconf>true</zeroconf>`. Kodis
Fernsteuerung ist damit **von Anfang an eingeschaltet**. Das Callback-Addon
spielt das Plugin ebenfalls selbst ein, und dessen sieben Felder setzt der Knopf
*Addon-Einstellungen setzen* im Reiter *MQTT*. An Kodis Oberfläche geht man nur
für die eigenen **Medienquellen**.

Das stand nirgends — und ob es noch gilt, fragte auch niemand nach. Deshalb:

* **Neue Prüfzeile *Steht Kodis eigene Fernsteuerung an?*** — vor der
  Dienstzeile. Sie liest `advancedsettings.xml` über den Helfer (die Datei
  gehört `kodi` und liegt in einem Verzeichnis mit `chmod 750`, der Webbenutzer
  kommt nicht heran) und unterscheidet vier Lagen: eingeschaltet mit genanntem
  Port, ausgeschaltet, Datei gar nicht vorhanden, Helfer schweigt. Der neue
  Helferbefehl `advread` liest **nur**; geschrieben wird die Datei
  ausschließlich beim Einspielen.
* **Zwei Hilfeabschnitte** (`K04d`, `K04e`): wie man an Kodi herankommt, und
  was man dort für dieses Plugin einstellen muss — nämlich nichts.

**Mitgegangen, eine kleine Berichtigung in `postroot.sh`:** das `cp` überschrieb
eine vorhandene `advancedsettings.xml` **ohne Sicherungskopie**. Dort können
Einstellungen des Anwenders stehen, die dieses Plugin nichts angehen
(Netzwerkabstimmung, Datenbank, Zwischenspeicher) — die waren nach jedem
Einspielen weg, ohne dass es irgendwo stand. Bei der `config.txt` legt dasselbe
Skript seit je eine Sicherung an; hier fehlte sie. Jetzt heißt sie
`advancedsettings.xml.kodiplugin`.

**Sie wird nie überschrieben, und das ist der eigentliche Punkt.** `postroot.sh`
läuft bei *jedem* Einspielen, nicht nur beim ersten. Beim zweiten ist die
aktuelle Datei bereits die des Plugins — eine Sicherung ohne diese Bedingung
kopierte sie über die Sicherung des ersten Laufs, und die Anwenderdatei wäre
endgültig weg. Also genau der Verlust, den die Stelle verhindern soll, nur eine
Fassung später. Dazu die Gegenbedingung: eine Datei, die mit der mitgelieferten
übereinstimmt, wird gar nicht erst gesichert, sonst bliebe bei jeder
Neuinstallation eine Sicherung stehen, die nichts enthält als das, was das
Plugin selbst mitbringt. Nachgestellt mit dreimaligem Einspielen in beiden
Lagen.

Gemessen von `t_advread.pl` (die beiden Lesemuster am echten Quelltext des
Helfers, gegen die ausgelieferte Datei und fünf Abwandlungen) und
`ps/t_fernweg.py` (die Prüfzeile in sechs Lagen, darunter einmal der ganze Weg
vom PHP durch `sudo` in den echten Helfer und zurück).

Sonst ist 1.2.3 inhaltlich gleich 1.2.2.

## Version 1.2.2 – „Ist Kodi überhaupt installiert?"

**Das Plugin hat diese Frage nie gestellt.** Kodi kommt mit dem Plugin: beim
Einspielen liest LoxBerry die Datei `dpkg/apt` und holt das Paket `kodi` aus
der Standardquelle von Raspberry Pi OS. Geht das schief — kein Netz, Platte
voll, Paket gerade nicht verfügbar —, dann installiert sich das Plugin
**trotzdem erfolgreich**: die systemd-Unit wird angelegt und eingeschaltet, ihr
`ExecStart` zeigt ins Leere, `systemctl is-active` meldet *inactive*.

Und die Oberfläche sagte daraufhin: **„Kodi: gestoppt".**

Das ist eine Falschaussage, nicht bloß eine unvollständige. „Gestoppt" heißt
*es ist da und läuft gerade nicht*; hier ist gar nichts da. Der Anwender drückt
also Start, systemd bricht mit `203/EXEC` ab und gibt nach fünf Versuchen auf —
und der wahre Grund stand an keiner Stelle der Oberfläche.

Seit 1.2.2 wird gefragt, an drei Stellen:

* **Reiter *Test*, eine neue Zeile vor der Dienstzeile:** *Ist Kodi überhaupt
  installiert?* Sie unterscheidet vier Lagen — Paket und Programm da (Haken,
  mit genannter Paketfassung); Programm da, aber kein Paket `kodi` (Haken, von
  Hand gebaut oder aus fremder Quelle, die Fassung ist dann nicht zu nennen);
  Paket eingetragen, Programm fehlt (Kreuz, unvollständig installiert); nichts
  von beidem (Kreuz, mit dem Weg zurück).
* **Die Dienstzeile darunter sagt nicht mehr „gestoppt", wenn es nichts zu
  starten gibt**, sondern nennt den Grund und warnt vor dem Startknopf.
* **Die Kachel oben** kennt jetzt drei Zustände statt zwei: *läuft*,
  *gestoppt*, *nicht installiert*.

**Der Pfad zum Programm kommt aus der mitgelieferten `kodi_ng.service`**, nicht
aus dem Gedächtnis — er steht dort genau einmal. Ihn im PHP ein zweites Mal
hinzuschreiben wäre eine zweite Wahrheit, die beim nächsten Umbau der Unit
zurückbliebe. Ist die Unit nicht lesbar, sagt die Zeile **„nicht
feststellbar"** und rät nichts.

Dazu zwei Sätze in der Hilfe, die eine wiederkehrende Frage beantworten: Kodi
muss **nicht getrennt installiert** werden, und wenn doch etwas fehlt, sagt es
diese Prüfzeile.

**Ein Knopf „Kodi jetzt installieren" ist bewusst nicht dabei.** Ein `apt-get`
über die Weboberfläche läuft minutenlang, ohne Rückmeldung, mit Root-Rechten
und ohne Aussicht darauf, einen Abbruch mittendrin sauber aufzuräumen. Der Weg
über ein erneutes Einspielen des Plugins tut dasselbe, nur beaufsichtigt.

### Der erste Gerätebefund: das Plugin lehnte seine eigene Sicherung ab

Aus dem Betrieb, am Tag der ersten Installation:

```
Übersteht die Sicherung eine Rundreise?   NEIN
Unzulässiger Wert für addon_udp_port:  |  Unzulässiger Wert für addon_volume_on_start:
```

**Ein leeres Addon-Feld ist ein gültiger Zustand — und zwar genau der, den ein
frisch aufgesetztes Gerät hat.** Kodi legt ein nie angefasstes Feld als
`<setting id="udp_port" default="true"></setting>` ab; das Addon fällt dann auf
seine eigene Vorgabe zurück. Die *Schreibseite* des Plugins kennt das und
schreibt den leeren Wert ausdrücklich als `-`. Die *Leseseite* wies ihn ab.

Damit lehnte das Plugin genau die Sicherung ab, die es selbst erzeugt hatte —
auf jedem Gerät, auf dem die Addon-Einstellungen noch nie angefasst wurden.
Also auf jedem neuen.

Die Regel gilt jetzt an **beiden** Stellen, im PHP und im Helfer mit erhöhten
Rechten: **leer ist erlaubt und heißt „nicht gesetzt".** Die Bereichsprüfungen
(Port 1…65535, Lautstärke 0…100) gelten nur für nicht-leere Werte — ein leerer
Wert hat keinen Bereich. Dass das Addon damit umgeht, ist nachgelesen und nicht
vermutet: `SetVolume` steht in einem `try/except`, `send_raw_udp` fängt jede
Ausnahme, und UDP wie MQTT werden überhaupt nur gesendet, wenn die zugehörige
**Adresse** nicht leer ist.

Warum die Prüfstände das nicht gefunden haben: ohne Helfer liefert
`ko_addon_lesen()` `null`, und dann stehen gar keine `addon_`-Zeilen in der
Sicherung. Der Fall war nie aufgebaut. Seit dieser Fassung baut ihn
`ps/t_rundreise.py` auf — mit dem *echten* Lesecode des Helfers, angewandt auf
eine Nachbildung der Gerätedatei — und `t_addonwrite.pl` misst dieselbe Regel
noch einmal an der Prüfschleife des Helfers selbst, samt Gegenprobe, dass Port
0, Port 99999 und Lautstärke 101 weiterhin abgewiesen werden.

**Und die Prüfzeile, die den Befund gebracht hat, war schon da.** *Übersteht die
Sicherung eine Rundreise?* wurde in 1.2.0 gebaut, für genau diese Klasse — einen
Fehler, der sonst erst beim Zurückspielen auffällt, Monate später und auf einem
anderen Gerät. Sie hat ihn am ersten Tag gefunden.

### Zwei Fragen vom Gerät — und beide waren Lücken in der Auskunft

**„Warum steht der Dienst nach der Installation auf *gestoppt*?"** Weil
`postroot.sh` `systemctl enable` macht und **nicht** `start` — mit Absicht: die
Gruppenrechte für Ton, Bild und Eingabegeräte (`usermod -a -G`) und die
GPU-Einstellung in der `config.txt` greifen erst nach einem Neustart. Genau
deshalb trägt `plugin.cfg` `REBOOT=true`, und LoxBerry fragt nach der
Installation nach einem Neustart. Danach läuft Kodi von selbst.

Das war richtig gebaut — und **nirgends gesagt**. Die Kachel zeigte *gestoppt*,
der Autostart *ein*, und der Zusammenhang war nicht zu erraten. Die Dienstzeile
im Reiter *Test* nennt ihn jetzt, samt der Nebenwirkung: wer stattdessen sofort
startet, dem fehlen bis zum nächsten Neustart genau jene Rechte.

**„Warum gibt es keinen Knopf, der Kodis Weboberfläche aufruft?"** Den gab es —
im Reiter *Test* unter *Ansehen*, also zwei Reiter von der Adresse entfernt.
Jetzt steht er **auch dort, wo die Adresse eingetragen wird**, und beide Stellen
holen sie aus derselben Funktion.

Die Adresse ist dabei der eigentliche Punkt. In der Konfiguration steht
`127.0.0.1`, und das ist **richtig** — das Plugin läuft auf demselben Gerät wie
Kodi. Im Browser des Anwenders meint `127.0.0.1` aber dessen **eigenen**
Rechner; genau daran gab es ein `ERR_CONNECTION_REFUSED`. Der Knopf nimmt
deshalb den Wirtsnamen, unter dem der Anwender *diese Seite gerade sieht* — die
einzige Adresse, von der feststeht, dass sie zum LoxBerry führt. Zeigt
`kodi_host` auf ein anderes Gerät, gilt diese Angabe.

Beim Zusammenführen kamen drei Mängel des alten Verweises mit heraus, alle drei
gemessen von `ps/t_weblink.py`:

* er trug `target="_blank"` **ohne** `rel="noopener noreferrer"` — die geöffnete
  Seite kann sonst über `window.opener` auf die öffnende zugreifen;
* er wurde auch bei **unzulässigem Port** angeboten und zeigte dann auf
  `http://<wirt>:0` — ein Knopf ins Leere;
* die Regel „welcher Wirtsname ist für den Anwender sichtbar" stand **zweimal**
  im Quelltext. Jetzt sagt `ko_sicht_wirt()` sie einmal.

**Die leere Seite ist übrigens kein Fehler.** Kodi liefert seit Fassung 18 keine
Weboberfläche mehr mit; steht unter *Weboberfläche* „Keine", antwortet der
Webserver auf `/` mit einem leeren Rumpf — während `/jsonrpc` tadellos
antwortet. **Dieses Plugin braucht sie nicht:** es spricht ausschließlich
`/jsonrpc` an. Wer sie will, installiert sie in Kodi als Erweiterung (etwa
Chorus2). Auch das steht jetzt in der Hilfe unter dem Knopf.

### Was daneben an den Werkzeugen berichtigt wurde

* **`harte_pfade.py` hängt jetzt in `freigabe_pruefen.py`** (Prüfung 4d). Der
  Befund von 1.2.1 konnte nur deshalb ins ausgelieferte Paket geraten, weil die
  Regel in Prosa stand und kein Werkzeug danach suchte. Die Prüfung kennt zwei
  Stufen: **hart** wird sie bei Fundstellen in endungslosen Dateien — nur die
  sieht der Pluginprüfer wirklich —, und **genannt, aber nicht hart** bei
  Kommentaren und README-Beispielen. Sonst wäre die erste Folge eine
  Freigabewelle über fünfzehn veröffentlichte Linien gewesen, deren Fundstellen
  beim Einspielen nichts auslösen.
* **`sicherung_verdrahtung.py` fand den Sicherungszweig dieser Linie nicht.**
  Es verlangte den Knopfnamen mit Kürzel (`ko_sichern`) und die Wache als
  Variable; hier heißt der Knopf `sichern` und die Wache ist `ko_ist_post()`.
  Ergebnis: „0 Linien gemessen, 0 mit Befund" bei Rückgabewert 0 — das liest
  sich wie bestanden. Jetzt misst es **52 Linien**.
* **`php_bilanz.py` war über den ganzen Bestand rot.** Es meldete auf *jeder*
  Linie `_exists` als doppelt definiert (sein Muster griff in
  `function_exists(`) und jede JavaScript-Funktion als toten PHP-Code (die
  Definitionen wurden im Rohtext gesucht, die Aufrufe im entkernten). Dazu
  zählte eine mit `if (!function_exists(...))` **bewachte** Zweitdefinition —
  das Hausmuster für die getrennten Bäume — als Doppelung. Von 16 roten Linien
  bleibt **eine**, und die ist echt: Smartmeter classic definiert `sm_log`
  ungedeckt in zwei Dateien.
* **Die Perl-Attrappe `LoxBerry::System` log.** `begins_with` und `trim`
  fehlten ganz, `is_enabled` stand als leeres Unterprogramm da und lieferte
  damit **immer** *undef* — ein Prüfstand, der so misst, sieht jeden Schalter
  als ausgeschaltet und meldet trotzdem grün. Alle drei sind jetzt dem echten
  Vorbild nachgebaut und in beide Richtungen geeicht.

### Was an dieser Fassung nicht gemessen ist

Auf dem Entwicklungsrechner gibt es kein `dpkg`. Gemessen sind deshalb alle
Zustände, die am **Vorhandensein der ausführbaren Datei** hängen — beide
Antworten der Dienstzeile und alle drei Zustände der Kachel —, aber nicht der
Unterschied zwischen *„ja, Paket 20.5"* und *„ja, aber ohne Paket"*. Diese eine
Verzweigung steht erst am Gerät auf dem Prüfstand.

Sonst ist 1.2.2 inhaltlich gleich 1.2.1 — bis auf die eine Stelle im
Sicherungsweg, die der Gerätebefund oben aufgedeckt hat.

## Version 1.2.1 – Berichtigung

**Ein einziger Befund, und er kam aus dem Betrieb.** Beim Einspielen von 1.2.0
meldet der Pluginprüfer von LoxBerry:

```
WARNING Kodi NG: HARDCODED PATH'S: Das Plugin nutzt einen hardkodierten Pfad
zu <LoxBerry-Wurzel> … /uninstall/uninstall
```

In `uninstall/uninstall` stand ein fester Rückfall auf das übliche
Installationsverzeichnis. Er war in 1.2.0 **neu entstanden** — beim Beheben
eines anderen Befundes, und ausgerechnet in derselben Fassung, die an anderer
Stelle genau so einen Pfad *entfernt* hat. Die Regel dagegen steht seit dem
22.08.2026 in den Hausregeln, samt Vorlage.

Die Wurzel wird jetzt **gesucht statt gesetzt**, nach dem Hausmuster: vom
eigenen Ablageort aufwärts, bis ein Verzeichnis gefunden ist, das
`config/plugins` **und** `data/plugins` trägt — mit **harter Obergrenze von
acht Ebenen**. Die Grenze ist kein Beiwerk: der erste Versuch hatte keine, und
auf dem Entwicklungsrechner fand der Lauf daraufhin die Wurzel des Laufwerks,
weil dort beide Verzeichnisse zufällig existierten. Danach wäre `rm -rf` gegen
einen geratenen Baum gelaufen. Findet die Suche nichts, wird **nichts
gelöscht** und gesagt, was liegenbleibt.

Zwei kleinere Punkte derselben Klasse gingen mit:

* **Auch der Kommentar durfte den Pfad nicht nennen.** In `bin/ko_lib.php`
  stand er in einem Satz, der erklärte, warum er falsch wäre. Der Prüfer sucht
  die Zeichenkette und liest den Zusammenhang nicht — er hätte den Kommentar
  genauso beanstandet wie den Fehler.
* **`config/` steht jetzt in der `.gitignore`.** Läuft `index.php` ohne
  `LBHOMEDIR` — also aus dem entpackten Archiv, wie bei jedem Prüflauf —,
  fallen die Pfade auf `<paket>/config/` zurück, und dort entsteht das
  Formularmerkmal `ko_formkey`. Beim Packen wanderte es einmal mit: das Archiv
  hatte 47 statt 46 Dateien, und die überzählige war ein **Geheimnis**.
  Aufgefallen ist es nur, weil die Dateizahl mitgezählt wurde.

**Und die Lehre über allen dreien:** die Prüfkette war grün — zehn Prüfungen,
null Beanstandungen — während der Fehler im ausgelieferten Paket lag. Es gab
schlicht kein Werkzeug, das nach harten Pfaden sucht; die Regel stand in Prosa.
Seit dieser Fassung gibt es `Werkzeuge/harte_pfade.py`, geeicht in beide
Richtungen und über den ganzen Bestand gelaufen.

Sonst ist 1.2.1 inhaltlich gleich 1.2.0.

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

## Änderungen

Die Freigabenotiz zu jeder Fassung steht bei den Releases:
<https://github.com/timanders22/LoxBerry-Plugin-KODI-NG/releases>

Der folgende Abschnitt hieß bis 1.2.4 „Änderungen in 1.1.0“ — eine Fassungsnummer
in einer Überschrift wird mit jedem Release falscher. Der Inhalt bleibt, weil
er beschreibt, *warum* das Plugin so gebaut ist; er betrifft die Fassung 1.1.0.

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

## Fassung 1.2.4 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in `bin/ko_lib.php:511`. PHP merkt
sich aber die Antworten von `stat()`: innerhalb **eines** Prozesses sieht
`filesize()` die erste Größe und danach nie wieder eine neue —
`file_put_contents(…, FILE_APPEND)` macht den Eintrag nicht ungültig. Die
Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

