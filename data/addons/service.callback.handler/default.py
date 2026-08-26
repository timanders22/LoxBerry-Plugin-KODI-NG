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

import socket

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
# tatsaechlich bildet. Dagegen steht der Wachposten in send_event() selbst, der
# jedes Thema ausserhalb dieser Liste ins Kodi-Protokoll schreibt.
THEMEN = ('event', 'movie_title', 'music_title', 'episode_title',
          'unknown_title', 'screensaver')

# Die Vorgaben stehen an ZWEI Stellen: hier und in resources/settings.xml.
# Kodi liest die XML-Vorgabe; dieses Feld greift nur, wenn getSetting einen
# Leerstring liefert. Gemessen wichen sie in genau einem Wert voneinander ab -
# volume_on_start stand hier auf 50 und dort auf 90. Solange Kodi antwortete,
# galt die 90; auf einem frisch aufgesetzten Addon die 50. Zwei Vorgabelisten
# sind zwei Wahrheiten, und diese hier war die stille.
settings = {
    'udp_address': '',
    'udp_port': '7000',
    'volume_on_start': '90',
    'mqtt_enable': 'true',
    'mqtt_address': '',
    'mqtt_udpport': '11884',
    'mqtt_topic': 'kodi',
}


def log(txt):
    xbmc.log(msg='%s: %s' % (__addonname__, txt), level=xbmc.LOGDEBUG)


def log_warnung(txt):
    xbmc.log(msg='%s: %s' % (__addonname__, txt), level=xbmc.LOGWARNING)


erster_lauf = [True]


def read_settings():
    """Die Einstellungen aus Kodi uebernehmen.

    BEIM ERSTEN LAUF bleibt ein leeres Feld auf der Vorgabe stehen - das ist
    der Rueckfall. BEI JEDEM WEITEREN Lauf wird ein geleertes Feld auch
    uebernommen: sonst laesst sich eine einmal eingetragene Adresse zur
    Laufzeit nicht mehr abschalten, und das Addon sendet bis zum naechsten
    Kodi-Neustart weiter dorthin. onSettingsChanged ruft genau diesen Weg.
    """
    for key in settings:
        val = __addon__.getSetting(key)
        if val != '' or not erster_lauf[0]:
            settings[key] = val
        log('%s = "%s"' % (key, settings[key]))
    erster_lauf[0] = False


def send_raw_udp(payload, address, port):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.sendto(payload.encode('utf-8'), (address, int(port)))
        sock.close()
    except Exception as e:
        log('UDP send failed (%s:%s): %s' % (address, port, e))


def mqtt_wert(wert):
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest ZEILENWEISE, und Leerzeichen trennt Thema von Wert. Ein
    Zeilenumbruch in einem Filmtitel - es gibt sie - zerlegt die Uebertragung,
    und aus den Bruchstuecken bildet das Gateway erfundene Themen.

    Ein LEERER Wert wird zu "-". Ohne das ginge die Zeile mit einem
    abschliessenden Leerzeichen hinaus, und ob das Gateway daraus ein leeres
    Thema oder gar keines macht, ist an dieser Stelle nicht gemessen. Der
    Bindestrich ist eindeutig und steht so in der Themen-Tabelle des Plugins.
    """
    t = str(wert).replace('\r\n', ' ').replace('\r', ' ').replace('\n', ' ')
    t = t.replace('\t', ' ')
    while '  ' in t:
        t = t.replace('  ', ' ')
    t = t.strip()
    return t if t != '' else '-'


def send_event(event, value=None):
    """Ereignis an Miniserver (UDP, altes Format) und/oder MQTT Gateway senden."""
    text = event if value is None else '%s=%s' % (event, value)
    log(text)
    # 1) Klassisch: UDP direkt an den Miniserver (Virtueller UDP-Eingang).
    #    Das Format bleibt UNVERAENDERT - in bestehenden Anlagen haengen
    #    Befehlserkennungen daran.
    if settings['udp_address']:
        send_raw_udp(text, settings['udp_address'], settings['udp_port'])
    # 2) MQTT ueber das LoxBerry MQTT Gateway (UDP-Schnittstelle, retained)
    if settings['mqtt_enable'] == 'true' and settings['mqtt_address']:
        topic = settings['mqtt_topic'].rstrip('/')
        if value is None:
            zweig = 'event'
            nutzlast = event
        else:
            zweig = event
            nutzlast = value
        # Der Wachposten: ein Thema, das nicht in THEMEN steht, ginge
        # unbemerkt hinaus und fehlte in der Tabelle des Plugins. Gesendet
        # wird es trotzdem - ein Ereignis zu verschlucken waere schlimmer -,
        # aber es steht danach im Kodi-Protokoll.
        if zweig not in THEMEN:
            log_warnung('Thema "%s" steht nicht in THEMEN - die Tabelle des '
                        'Plugins kennt es nicht.' % zweig)
        send_raw_udp('retain %s/%s %s' % (topic, zweig, mqtt_wert(nutzlast)),
                     settings['mqtt_address'], settings['mqtt_udpport'])


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
        # Die zuletzt erkannte Medienart.
        #
        # WOZU: playing_type() fragt isPlayingAudio() und
        # VideoPlayer.Content(...) - beides ist beim Stoppen bereits falsch,
        # weil nichts mehr laeuft. Die Art waere dort also immer 'unknown'.
        # Ohne diesen Merker ginge beim Stoppen 'unknown_stopped' hinaus statt
        # 'movie_stopped', und geraeumt wuerde 'unknown_title', waehrend
        # 'movie_title' den alten Filmtitel retained behielte - genau das
        # Gegenteil dessen, was das Raeumen bezweckt.
        self.letzte_art = 'unknown'

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

    def onPlayBackStarted(self):
        ptype = self.playing_type()
        self.letzte_art = ptype
        send_event(ptype + '_started')
        send_event(ptype + '_title', self.titel(ptype))

    def onAVStarted(self):
        # Kodi 18+: eigentlicher Wiedergabestart. Die Titel und die Medienart
        # stehen erst hier zuverlaessig - onPlayBackStarted kann ihnen
        # zuvorkommen und liefert dann 'unknown'. Deshalb wird beides ein
        # zweites Mal geschickt; retained heisst, dass der zweite den ersten
        # ersetzt, nicht ergaenzt.
        ptype = self.playing_type()
        if ptype != self.letzte_art:
            # Die erste Meldung ging unter der falschen Art hinaus. Ihren
            # Titel raeumen, sonst bliebe er retained stehen und in Loxone
            # staende an einem Eingang ein Titel, der zu nichts gehoert.
            send_event(self.letzte_art + '_title', '')
            send_event(ptype + '_started')
            self.letzte_art = ptype
        send_event(ptype + '_title', self.titel(ptype))

    def onPlayBackEnded(self):
        self.onPlayBackStopped()

    def onPlayBackStopped(self):
        # Die GEMERKTE Art, nicht die aktuelle: beim Stoppen laeuft nichts
        # mehr, und playing_type() lieferte hier immer 'unknown'.
        ptype = self.letzte_art
        send_event(ptype + '_stopped')
        # Und den Titel raeumen. Ohne das steht der letzte Titel dauerhaft im
        # Broker (retained) und in Loxone - "was laeuft gerade" saehe dann
        # auch dann nach Wiedergabe aus, wenn nichts mehr laeuft.
        send_event(ptype + '_title', '')

    def onPlayBackPaused(self):
        send_event(self.letzte_art + '_paused')

    def onPlayBackResumed(self):
        send_event(self.letzte_art + '_resumed')


class Main:

    def __init__(self):
        read_settings()
        self.player = MyPlayer()
        self.monitor = MyMonitor(update_settings=read_settings)
        try:
            xbmc.executebuiltin('SetVolume(%s)' % int(float(settings['volume_on_start'])))
        except Exception as e:
            log('SetVolume failed: %s' % e)
        send_event('kodi_started')
        while not self.monitor.abortRequested():
            if self.monitor.waitForAbort(10):
                break
        log('abort requested')
        send_event('kodi_stopped')


if __name__ == '__main__':
    log('script version %s started' % __addonversion__)
    Main()
    log('script version %s stopped' % __addonversion__)
