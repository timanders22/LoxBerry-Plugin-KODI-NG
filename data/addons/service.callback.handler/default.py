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

settings = {
    'udp_address': '',
    'udp_port': '7000',
    'volume_on_start': '50',
    'mqtt_enable': 'true',
    'mqtt_address': '',
    'mqtt_udpport': '11884',
    'mqtt_topic': 'kodi',
}


def log(txt):
    xbmc.log(msg='%s: %s' % (__addonname__, txt), level=xbmc.LOGDEBUG)


def read_settings():
    for key in settings:
        val = __addon__.getSetting(key)
        if val != '':
            settings[key] = val
        log('%s = "%s"' % (key, settings[key]))


def send_raw_udp(payload, address, port):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.sendto(payload.encode('utf-8'), (address, int(port)))
        sock.close()
    except Exception as e:
        log('UDP send failed (%s:%s): %s' % (address, port, e))


def send_event(event, value=None):
    """Ereignis an Miniserver (UDP, altes Format) und/oder MQTT Gateway senden."""
    text = event if value is None else '%s=%s' % (event, value)
    log(text)
    # 1) Klassisch: UDP direkt an den Miniserver (Virtueller UDP-Eingang)
    if settings['udp_address']:
        send_raw_udp(text, settings['udp_address'], settings['udp_port'])
    # 2) MQTT ueber das LoxBerry MQTT Gateway (UDP-Interface, retained)
    if settings['mqtt_enable'] == 'true' and settings['mqtt_address']:
        topic = settings['mqtt_topic'].rstrip('/')
        if value is None:
            msg = 'retain %s/event %s' % (topic, event)
        else:
            msg = 'retain %s/%s %s' % (topic, event, value)
        send_raw_udp(msg, settings['mqtt_address'], settings['mqtt_udpport'])


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
    title = ''

    def __init__(self):
        xbmc.Player.__init__(self)
        self.substrings = ['-trailer', 'http://']

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
                    MyPlayer.title = xbmc.getInfoLabel('ListItem.Title')
            elif xbmc.getCondVisibility('VideoPlayer.Content(episodes)'):
                if xbmc.getInfoLabel('VideoPlayer.Season') != '' and xbmc.getInfoLabel('VideoPlayer.TVShowTitle') != '':
                    ptype = 'episode'
        return ptype

    def onPlayBackStarted(self):
        ptype = self.playing_type()
        send_event(ptype + '_started')
        send_event(ptype + '_title', MyPlayer.title)

    def onAVStarted(self):
        # Kodi 18+: eigentlicher Wiedergabestart
        pass

    def onPlayBackEnded(self):
        self.onPlayBackStopped()

    def onPlayBackStopped(self):
        send_event(self.playing_type() + '_stopped')

    def onPlayBackPaused(self):
        send_event(self.playing_type() + '_paused')

    def onPlayBackResumed(self):
        send_event(self.playing_type() + '_resumed')


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
