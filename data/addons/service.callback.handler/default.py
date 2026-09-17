#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
#     Copyright (C) 2015 Tefi
#     Python-3-Port + MQTT (LoxBerry MQTT Gateway) 2026
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation, either version 3 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program. If not, see <http://www.gnu.org/licenses/>.

import re
import socket
import time

import xbmc
import xbmcaddon

__addon__ = xbmcaddon.Addon()
__addonversion__ = __addon__.getAddonInfo('version')
__addonname__ = __addon__.getAddonInfo('name')

# Die MQTT-Themen, die dieses Addon veroeffentlichen kann.
#
# Sie stehen hier AUSGESCHRIEBEN, damit die Pruefzeile "Themenliste" im Reiter
# Test des Plugins sie gegen ihre eigene Tabelle halten kann. Bis 1.1.9 gab es
# diese Liste nicht - und die Tabelle im Reiter "Einbindung in Loxone" nannte
# zwei Themen, die es gar nicht gab (status, titel), waehrend die vier, die
# hier wirklich hinausgehen, fehlten. Es hat niemand gemerkt, weil nichts
# beide Seiten gegeneinander gehalten hat.
#
# Ausgeschrieben heisst: diese Liste kann von dem abweichen, was send_event()
# tatsaechlich bildet. Dagegen steht der Wachposten in send_mqtt() selbst, der
# jedes Thema ausserhalb dieser Liste ins Kodi-Protokoll schreibt.
THEMEN = ('event', 'movie_title', 'music_title', 'episode_title',
          'unknown_title', 'screensaver')

# Die Medienarten, die playing_type() liefern kann. Zu jeder gehoert ein
# Titelthema <art>_title.
ARTEN = ('movie', 'episode', 'music', 'unknown')

# Die Einstellungen kommen AUSSCHLIESSLICH aus Kodi.
#
# Bis 3.1.0 stand hier eine zweite Vorgabeliste, auf die ein leeres Feld beim
# ERSTEN Lesen zurueckfiel - bei jedem spaeteren Lesen aber nicht mehr. Ein
# geleertes Feld verhielt sich also nach dem Kodi-Start anders als nach dem
# Aendern einer Einstellung (gemessen: mqtt_topic leer ging erst an
# "kodi/event", nach onSettingsChanged an "/event"). Die Vorgaben stehen jetzt
# nur noch in resources/settings.xml; Kodi liefert sie fuer ein nie
# angefasstes Feld selbst. Ein trotzdem leeres Pflichtfeld wird nicht still
# ersetzt, sondern beanstandet, und der betroffene Weg sendet nicht.
EINSTELLUNGEN = ('udp_address', 'udp_port', 'volume_on_start', 'mqtt_enable',
                 'mqtt_address', 'mqtt_udpport', 'mqtt_topic')
settings = dict((k, '') for k in EINSTELLUNGEN)

# Die geprueften Sendeziele, gesetzt von read_settings().
#   'udp':  (adresse, port)          oder None = dieser Weg sendet nicht
#   'mqtt': (adresse, port, thema)   oder None
ziele = {'udp': None, 'mqtt': None}

# Die Uhr steht als Name im Modul, damit ein Pruefstand sie ersetzen kann.
uhr = time.monotonic

# So lange schweigt die Stoerungsmeldung eines Sendewegs nach einer Warnung.
STOERUNG_PAUSE = 600

# So lange nach onPlayBackStarted wird auf onAVStarted gewartet, bevor eine
# Wiedergabe mit unbekannter Art trotzdem gemeldet wird (siehe nachholen()).
NACHHOLEN_NACH = 30


def log(txt):
    xbmc.log(msg='%s: %s' % (__addonname__, txt), level=xbmc.LOGDEBUG)


def log_info(txt):
    xbmc.log(msg='%s: %s' % (__addonname__, txt), level=xbmc.LOGINFO)


def log_warnung(txt):
    xbmc.log(msg='%s: %s' % (__addonname__, txt), level=xbmc.LOGWARNING)


PORT_MUSTER = re.compile(r'\A[0-9]{1,5}\Z')


def port_lesen(wert):
    """Ein Port 1..65535 oder None."""
    w = wert.strip()
    if not PORT_MUSTER.match(w):
        return None
    p = int(w)
    return p if 1 <= p <= 65535 else None


def read_settings():
    """Die Einstellungen aus Kodi uebernehmen und die Sendeziele pruefen.

    JEDER Lauf verhaelt sich gleich - beim Start wie nach onSettingsChanged.
    Alle Beanstandungen werden gesammelt und jede als Warnung protokolliert,
    nicht nur die erste. Ein Weg mit Beanstandung sendet nicht.

    Was KEINE Beanstandung ist: eine leere Adresse. Sie heisst "dieser Weg ist
    nicht eingerichtet" - so steht es nach der Installation fuer MQTT (Vorgabe
    eingeschaltet, Adresse leer) auf jedem Geraet, das nur UDP benutzt. Eine
    Warnung dafuer bei jedem Kodi-Start waere ein Fehlalarm; es steht deshalb
    als Hinweis (LOGINFO) im Protokoll.
    """
    for key in EINSTELLUNGEN:
        settings[key] = __addon__.getSetting(key)
        log('%s = "%s"' % (key, settings[key]))
    stoerung.vergessen()

    beanstandet = []

    # 1) UDP direkt an den Miniserver
    ziele['udp'] = None
    adresse = settings['udp_address'].strip()
    if adresse:
        port = port_lesen(settings['udp_port'])
        if port is None:
            beanstandet.append('udp_port "%s" ist leer oder kein Port 1-65535 - '
                               'an %s wird nichts gesendet.'
                               % (settings['udp_port'], adresse))
        else:
            ziele['udp'] = (adresse, port)

    # 2) MQTT ueber das LoxBerry MQTT Gateway
    ziele['mqtt'] = None
    schalter = settings['mqtt_enable']
    adresse = settings['mqtt_address'].strip()
    if schalter not in ('true', 'false'):
        beanstandet.append('mqtt_enable "%s" ist weder true noch false - '
                           'MQTT sendet nicht.' % schalter)
    elif schalter == 'true' and not adresse:
        log_info('MQTT ist eingeschaltet, aber mqtt_address ist leer - '
                 'MQTT ist nicht eingerichtet und sendet nicht.')
    elif schalter == 'true':
        fehler = []
        port = port_lesen(settings['mqtt_udpport'])
        if port is None:
            fehler.append('mqtt_udpport "%s" ist leer oder kein Port 1-65535'
                          % settings['mqtt_udpport'])
        thema = settings['mqtt_topic'].strip().rstrip('/')
        if thema == '':
            fehler.append('mqtt_topic "%s" ist leer' % settings['mqtt_topic'])
        elif re.search(r'[\s#+]', thema):
            # Leerzeichen trennt im UDP-Eingang des Gateways Thema und Wert;
            # # und + sind in einem MQTT-Thema zum Senden nicht erlaubt.
            fehler.append('mqtt_topic "%s" enthaelt Leerzeichen, # oder +'
                          % settings['mqtt_topic'])
        for f in fehler:
            beanstandet.append('%s - MQTT sendet nicht.' % f)
        if not fehler:
            ziele['mqtt'] = (adresse, port, thema)

    for b in beanstandet:
        log_warnung('Einstellung beanstandet: %s' % b)
    log_info('Sendewege: UDP %s, MQTT %s' % (
        '%s:%d' % ziele['udp'] if ziele['udp'] else 'aus',
        '%s:%d Thema %s' % ziele['mqtt'] if ziele['mqtt'] else 'aus'))


class Stoerung(object):
    """Sendefehler SICHTBAR, aber GEBREMST protokollieren.

    Bis 3.1.0 stand ein gescheitertes Senden nur als LOGDEBUG im Protokoll -
    also nirgends, solange Kodi nicht im Debug-Modus laeuft. Jede Zeile als
    Warnung dagegen liesse Kodis Protokoll bei einer dauerhaften Stoerung
    volllaufen (jede Pause, jeder Titel, jeder Bildschirmschoner).

    Deshalb je Sendeweg: der erste Fehler als Warnung; danach hoechstens alle
    STOERUNG_PAUSE Sekunden eine Warnung mit der Zahl der dazwischen nicht
    einzeln protokollierten Fehler; geht der Weg wieder, EINE Zeile.

    Was hier NICHT ankommt: ein UDP-Paket, das unterwegs verloren geht. UDP
    meldet dem Absender keinen Verlust. Gemeldet wird, was schon beim Senden
    scheitert - Adresse nicht aufloesbar, kein Weg ins Netz, ungueltiger Port.
    """

    def __init__(self):
        self.stand = {}

    def vergessen(self):
        self.stand = {}

    def fehler(self, weg, text):
        jetzt = uhr()
        s = self.stand.get(weg)
        if s is None:
            self.stand[weg] = {'gemeldet': jetzt, 'still': 0, 'gesamt': 1}
            log_warnung('%s: Senden gescheitert: %s (weitere Fehler dieses '
                        'Wegs hoechstens alle %d s)' % (weg, text, STOERUNG_PAUSE))
            return
        s['gesamt'] += 1
        if jetzt - s['gemeldet'] >= STOERUNG_PAUSE:
            log_warnung('%s: Senden weiterhin gestoert: %s (%d Fehler seit der '
                        'letzten Warnung nicht einzeln protokolliert)'
                        % (weg, text, s['still']))
            s['gemeldet'] = jetzt
            s['still'] = 0
        else:
            s['still'] += 1
            log('%s: Senden gescheitert: %s' % (weg, text))

    def erfolg(self, weg):
        s = self.stand.pop(weg, None)
        if s is not None:
            log_info('%s: Senden geht wieder (%d Fehler insgesamt)'
                     % (weg, s['gesamt']))


stoerung = Stoerung()


def send_raw_udp(payload, ziel, name):
    """Ein Datagramm senden; ziel = (adresse, port), name fuer das Protokoll."""
    weg = '%s %s:%s' % (name, ziel[0], ziel[1])
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            sock.sendto(payload.encode('utf-8'), (ziel[0], int(ziel[1])))
        finally:
            sock.close()
    except Exception as e:
        stoerung.fehler(weg, e)
        return False
    stoerung.erfolg(weg)
    return True


def mqtt_wert(wert):
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest ZEILENWEISE, und Leerzeichen trennt Thema von Wert. Ein
    Zeilenumbruch in einem Filmtitel - es gibt sie - zerlegt die Uebertragung,
    und aus den Bruchstuecken bildet das Gateway erfundene Themen.

    Ein LEERER Wert wird zu "-". Eine leere Nutzlast LOESCHT ein
    zurueckbehaltenes Thema im Broker (mqttgateway.pl, sub udpin: "Delete ...
    because of empty message"); danach stuende in Loxone der letzte Titel
    einfach weiter. Der Bindestrich ist eindeutig und steht so in der
    Themen-Tabelle des Plugins.
    """
    t = str(wert).replace('\r\n', ' ').replace('\r', ' ').replace('\n', ' ')
    t = t.replace('\t', ' ')
    while '  ' in t:
        t = t.replace('  ', ' ')
    t = t.strip()
    return t if t != '' else '-'


def send_mqtt(zweig, nutzlast):
    """Ein Thema ueber den UDP-Eingang des MQTT-Gateways senden.

    Alle Themen dieses Addons sind Zustaende (letztes Ereignis, laufender
    Titel, Bildschirmschoner an/aus) und gehen retained hinaus - Hausstandard
    seit 03.09.2026. Ein Lebenszeichen oder ein Messwert mit Zeitbezug ist
    nicht darunter.
    """
    ziel = ziele['mqtt']
    if not ziel:
        return
    adresse, port, thema = ziel
    # Der Wachposten: ein Thema, das nicht in THEMEN steht, ginge
    # unbemerkt hinaus und fehlte in der Tabelle des Plugins. Gesendet
    # wird es trotzdem - ein Ereignis zu verschlucken waere schlimmer -,
    # aber es steht danach im Kodi-Protokoll.
    if zweig not in THEMEN:
        log_warnung('Thema "%s" steht nicht in THEMEN - die Tabelle des '
                    'Plugins kennt es nicht.' % zweig)
    wert = mqtt_wert(nutzlast)
    # Zweite Sicherung (Regeln/07): ein leerer Wert geht NIE retained hinaus,
    # auch wenn mqtt_wert() einmal geaendert wird - er loeschte das Thema.
    befehl = 'retain' if wert != '' else 'publish'
    send_raw_udp('%s %s/%s %s' % (befehl, thema, zweig, wert),
                 (adresse, port), 'MQTT-Gateway')


def send_event(event, value=None):
    """Ereignis an Miniserver (UDP, altes Format) und/oder MQTT Gateway senden."""
    text = event if value is None else '%s=%s' % (event, value)
    log(text)
    # 1) Klassisch: UDP direkt an den Miniserver (Virtueller UDP-Eingang).
    #    Das Format bleibt UNVERAENDERT - in bestehenden Anlagen haengen
    #    Befehlserkennungen daran.
    if ziele['udp']:
        send_raw_udp(text, ziele['udp'], 'UDP')
    # 2) MQTT ueber das LoxBerry MQTT Gateway (UDP-Schnittstelle, retained)
    if value is None:
        send_mqtt('event', event)
    else:
        send_mqtt(event, value)


class MyMonitor(xbmc.Monitor):

    def __init__(self, update_settings):
        xbmc.Monitor.__init__(self)
        self.update_settings = update_settings

    def onSettingsChanged(self):
        self.update_settings()

    def onScreensaverActivated(self):
        log('screensaver starts')
        send_event('screensaver', 'on')

    def onScreensaverDeactivated(self):
        log('screensaver stops')
        send_event('screensaver', 'off')


class MyPlayer(xbmc.Player):

    def __init__(self):
        xbmc.Player.__init__(self)
        self.substrings = ['-trailer', 'http://']
        # Die Art, unter der das letzte <art>_started hinausging.
        #
        # WOZU: playing_type() fragt isPlayingAudio() und
        # VideoPlayer.Content(...) - beides ist beim Stoppen bereits falsch,
        # weil nichts mehr laeuft. Die Art waere dort also immer 'unknown'.
        # Ohne diesen Merker ginge beim Stoppen 'unknown_stopped' hinaus statt
        # 'movie_stopped', und geraeumt wuerde 'unknown_title', waehrend
        # 'movie_title' den alten Filmtitel retained behielte.
        self.letzte_art = 'unknown'
        # Ging seit dem letzten Stoppen ein <art>_started hinaus? Nur dann
        # gibt es etwas zu pausieren, fortzusetzen oder zu stoppen.
        self.laeuft = False
        # Ist die GERADE gestartete Wiedergabe schon gemeldet?
        self.gemeldet = False
        # Die Art, deren Titel gerade gesetzt (nicht "-") ist, sonst None.
        self.titel_art = None
        # onPlayBackStarted kannte die Art noch nicht: Zeitpunkt, sonst None.
        self.wartet_seit = None

    def playing_type(self):
        ptype = 'unknown'
        if self.isPlayingAudio():
            ptype = 'music'
        else:
            if xbmc.getCondVisibility('VideoPlayer.Content(movies)'):
                filename = ''
                is_movie = True
                try:
                    filename = self.getPlayingFile()
                except Exception:
                    pass
                if filename:
                    for s in self.substrings:
                        if s in filename:
                            is_movie = False
                            break
                if is_movie:
                    ptype = 'movie'
            elif xbmc.getCondVisibility('VideoPlayer.Content(episodes)'):
                if xbmc.getInfoLabel('VideoPlayer.Season') != '' and xbmc.getInfoLabel('VideoPlayer.TVShowTitle') != '':
                    ptype = 'episode'
        return ptype

    def titel(self, ptype):
        """Der Titel zur jeweiligen Art - je Art aus der passenden Quelle.

        HIER STAND BIS 1.1.9 EIN KLASSENWEITES MyPlayer.title, das
        AUSSCHLIESSLICH fuer Filme gesetzt wurde (aus ListItem.Title). Bei
        Musik und Serien blieb der Wert des ZULETZT gespielten Films stehen
        und ging unter music_title beziehungsweise episode_title hinaus. In
        Loxone stand dann der Filmtitel von gestern am Radioprogramm - und
        das sieht nicht nach einem Fehler aus, sondern nach einem Wert.
        """
        if ptype == 'movie':
            return xbmc.getInfoLabel('VideoPlayer.Title')
        if ptype == 'episode':
            serie = xbmc.getInfoLabel('VideoPlayer.TVShowTitle')
            folge = xbmc.getInfoLabel('VideoPlayer.Title')
            if serie and folge:
                return '%s - %s' % (serie, folge)
            return folge or serie
        if ptype == 'music':
            kuenstler = xbmc.getInfoLabel('MusicPlayer.Artist')
            stueck = xbmc.getInfoLabel('MusicPlayer.Title')
            if kuenstler and stueck:
                return '%s - %s' % (kuenstler, stueck)
            return stueck or kuenstler
        # 'unknown': es ist etwas an, aber Kodi sagt nicht was. Dann wird
        # NICHTS geraten - ein leerer Titel wird zu "-" und ist damit von
        # einem echten Titel unterscheidbar.
        return ''

    def melden(self, ptype):
        """<art>_started und den Titel senden.

        Stand zuvor der Titel einer ANDEREN Art, wird er geraeumt - sonst
        bliebe er retained stehen, und in Loxone stuende an einem Eingang ein
        Titel, der zu nichts gehoert.
        """
        if self.titel_art is not None and self.titel_art != ptype:
            send_event(self.titel_art + '_title', '')
        send_event(ptype + '_started')
        send_event(ptype + '_title', self.titel(ptype))
        self.letzte_art = ptype
        self.titel_art = ptype
        self.laeuft = True
        self.gemeldet = True
        self.wartet_seit = None

    def onPlayBackStarted(self):
        # Bis 3.1.0 ging hier SOFORT <art>_started hinaus. Zu diesem
        # Zeitpunkt kennt Kodi die Art oft noch nicht, playing_type() liefert
        # 'unknown' - und bei jedem Filmstart stand zuerst "unknown_started"
        # in kodi/event und am UDP-Eingang, erst danach "movie_started". Eine
        # Befehlserkennung auf unknown_started schaltete also bei jedem Film.
        #
        # Jetzt: ist die Art hier schon bekannt, wird sofort gemeldet wie
        # bisher. Ist sie es nicht, wird auf onAVStarted gewartet; kommt das
        # nie, holt nachholen() die Meldung nach NACHHOLEN_NACH Sekunden nach.
        # Die Ereignisnamen behalten ihre Bedeutung: unknown_started heisst
        # weiter "Kodi spielt etwas, das es nicht einordnet" - nur nicht mehr
        # "Kodi weiss es noch nicht".
        ptype = self.playing_type()
        self.gemeldet = False
        if ptype == 'unknown':
            self.wartet_seit = uhr()
            log('Wiedergabe gestartet, Art noch unbekannt - warte auf onAVStarted')
            return
        self.melden(ptype)

    def onAVStarted(self):
        # Kodi 18+: eigentlicher Wiedergabestart. Art und Titel stehen erst
        # hier zuverlaessig.
        ptype = self.playing_type()
        if not self.gemeldet:
            self.melden(ptype)
            return
        if ptype == 'unknown' or ptype == self.letzte_art:
            # Eine bereits gemeldete Art wird nicht auf 'unknown'
            # zurueckgestuft; nur der Titel wird aufgefrischt - er steht erst
            # jetzt sicher fest.
            send_event(self.letzte_art + '_title', self.titel(self.letzte_art))
            return
        # onPlayBackStarted hatte eine ANDERE bekannte Art erkannt.
        self.melden(ptype)

    def nachholen(self):
        """Vom Hauptlauf regelmaessig gerufen: eine gestartete Wiedergabe, fuer
        die onAVStarted nie kam, wird nach NACHHOLEN_NACH Sekunden mit der
        dann bekannten Art gemeldet."""
        if self.wartet_seit is None or self.gemeldet:
            return
        if not self.isPlaying():
            self.wartet_seit = None
            return
        if uhr() - self.wartet_seit >= NACHHOLEN_NACH:
            self.melden(self.playing_type())

    def onPlayBackEnded(self):
        self.onPlayBackStopped()

    def onPlayBackStopped(self):
        # Die GEMERKTE Art, nicht die aktuelle: beim Stoppen laeuft nichts
        # mehr, und playing_type() lieferte hier immer 'unknown'.
        if self.laeuft:
            send_event(self.letzte_art + '_stopped')
        else:
            # Es ging kein _started hinaus (Abbruch vor onAVStarted) - dann
            # gibt es auch nichts zu stoppen.
            log('Wiedergabe gestoppt, bevor sie gemeldet war')
        # Und den Titel raeumen. Ohne das steht der letzte Titel dauerhaft im
        # Broker (retained) und in Loxone - "was laeuft gerade" saehe dann
        # auch dann nach Wiedergabe aus, wenn nichts mehr laeuft.
        if self.titel_art is not None:
            send_event(self.titel_art + '_title', '')
        self.laeuft = False
        self.gemeldet = False
        self.titel_art = None
        self.wartet_seit = None

    def onPlayBackPaused(self):
        if self.laeuft:
            send_event(self.letzte_art + '_paused')

    def onPlayBackResumed(self):
        if self.laeuft:
            send_event(self.letzte_art + '_resumed')


def startzustand_senden(player):
    """Die zurueckbehaltenen Themen beim Start auf einen ehrlichen Stand setzen.

    WARUM: alle Themen sind retained. Faellt der Strom waehrend eines Films
    aus, kommt weder onPlayBackStopped noch kodi_stopped - und movie_title
    stuende nach dem Neustart fuer immer mit dem alten Film im Broker, ebenso
    "screensaver on". Loxone bekaeme beides nach jedem Neustart des Gateways
    erneut als aktuellen Stand.

    WAS gesendet wird:
      * je Titelthema der Titel der gerade laufenden Wiedergabe, sonst "-".
        NICHT die leere Nutzlast: eine leere Nutzlast mit retain LOESCHT das
        Thema im Broker (Regeln/07, mqttgateway.pl). Dann erfuehre Loxone gar
        nichts, und der virtuelle Eingang behielte den alten Titel. "-" ist der
        Wert, den das Addon auch beim Stoppen fuer "kein Titel" sendet.
      * screensaver: der gemessene Zustand aus Kodi
        (System.ScreenSaverActive), nicht ein angenommener.
      * event: nichts hier - kodi_started folgt unmittelbar danach und
        ersetzt das alte Ereignis.

    NUR ueber MQTT. Am UDP-Eingang des Miniservers bleibt nichts stehen, was
    aufzuraeumen waere, und jede zusaetzliche UDP-Zeile koennte dort eine
    Befehlserkennung ausloesen.
    """
    art = None
    if player.isPlaying():
        art = player.playing_type()
    for a in ARTEN:
        if a == art and art != 'unknown':
            send_mqtt(a + '_title', player.titel(a))
        else:
            send_mqtt(a + '_title', '')
    if art is not None and art != 'unknown':
        # Laeuft schon etwas (etwa nach einem Neustart des Addons), soll sein
        # Stoppen auch gemeldet und sein Titel geraeumt werden.
        player.letzte_art = art
        player.titel_art = art
        player.laeuft = True
        player.gemeldet = True
    elif art == 'unknown':
        player.wartet_seit = uhr()
    if xbmc.getCondVisibility('System.ScreenSaverActive'):
        send_mqtt('screensaver', 'on')
    else:
        send_mqtt('screensaver', 'off')


def lautstaerke_setzen():
    """Startlautstaerke: 1..100 wird gesetzt, 0 heisst "nicht veraendern".

    Bis 3.1.0 war die Startlautstaerke nicht abschaltbar (Regler 5..100,
    SetVolume auch ohne Einstellung mit der Vorgabe 90). Die Vorgabe bleibt
    90, damit sich bestehende Anlagen nicht aendern. Ein leerer oder
    unzulaessiger Wert wird beanstandet und die Lautstaerke nicht angefasst -
    nicht still durch die Vorgabe ersetzt.
    """
    roh = settings['volume_on_start'].strip()
    if roh == '':
        log_warnung('Einstellung beanstandet: volume_on_start ist leer - die '
                    'Lautstaerke wird beim Start nicht veraendert '
                    '(0 bedeutet ausdruecklich "nicht veraendern").')
        return
    try:
        wert = float(roh)
    except ValueError:
        wert = None
    # wert != wert faengt nan, der Bereich faengt inf - beide vor int(), das
    # sonst ausserhalb jedes try abbricht und das Addon beendet.
    if wert is None or wert != wert or not 0 <= wert <= 100 or wert != int(wert):
        log_warnung('Einstellung beanstandet: volume_on_start "%s" ist keine '
                    'ganze Zahl von 0 bis 100 - die Lautstaerke wird beim '
                    'Start nicht veraendert.' % roh)
        return
    if int(wert) == 0:
        log_info('volume_on_start = 0 - die Lautstaerke wird beim Start nicht veraendert.')
        return
    try:
        xbmc.executebuiltin('SetVolume(%d)' % int(wert))
    except Exception as e:
        log_warnung('SetVolume(%d) gescheitert: %s' % (int(wert), e))


class Main:

    def __init__(self):
        read_settings()
        # Kodi stellt Rueckrufe erst zu, wenn dieses Skript in Kodi wartet
        # (waitForAbort); bis zur Schleife laeuft der Start also ungestoert.
        self.player = MyPlayer()
        self.monitor = MyMonitor(update_settings=read_settings)
        lautstaerke_setzen()
        startzustand_senden(self.player)
        send_event('kodi_started')
        while not self.monitor.abortRequested():
            if self.monitor.waitForAbort(10):
                break
            self.player.nachholen()
        log('abort requested')
        send_event('kodi_stopped')


if __name__ == '__main__':
    log('script version %s started' % __addonversion__)
    Main()
    log('script version %s stopped' % __addonversion__)
