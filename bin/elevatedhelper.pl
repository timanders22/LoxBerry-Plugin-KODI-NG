#!/usr/bin/perl

use LoxBerry::System;
use CGI;
use Fcntl qw(O_WRONLY O_CREAT O_EXCL O_NOFOLLOW);
use warnings;
use strict;

our $cgi = CGI->new;
$cgi->import_names('R');
my  $version = "1.2.0";

# ------------------------------------------------------------------
# KOPFZEILEN NUR UNTER EINEM WEBSERVER.
#
# CGI::header() fragt nicht, wo es laeuft - es liefert immer den vollen
# Kopfzeilenblock ("Status: 200 OK", "Content-Type: ...", Leerzeile). Dieses
# Skript wird aber ausschliesslich ueber die Kommandozeile aufgerufen (sudo
# aus der Oberflaeche, siehe sudoers/sudoers), und der Aufrufer schickt die
# Ausgabe unmittelbar durch json_decode. Ein vorangestellter Kopfzeilenblock
# macht daraus ungueltiges JSON - und dann steht die Oberflaeche vor lauter
# Fragezeichen, ohne dass irgendwo ein Fehler auftaucht.
#
# Ob CGI.pm das wirklich tut, ist auf DIESEM Rechner nicht messbar (das Modul
# fehlt hier). Deshalb wird es beidseitig aufgeloest: hier wird die Kopfzeile
# nur noch unter einem Webserver ausgegeben, und der Aufrufer schneidet einen
# etwaigen Kopfblock trotzdem ab. Jede der beiden Seiten traegt fuer sich.
#
# GATEWAY_INTERFACE setzt jeder CGI-faehige Webserver; auf der Kommandozeile
# gibt es sie nicht.
sub kopf
{
	my (%p) = @_;
	return '' if (!defined $ENV{'GATEWAY_INTERFACE'});
	return $cgi->header(%p);
}

# LoxBerry 4 / Raspberry Pi OS bookworm: config.txt liegt unter /boot/firmware/
my $configtxt = -f "/boot/firmware/config.txt" ? "/boot/firmware/config.txt" : "/boot/config.txt";

# Die Einstellungen des Kodi-Addons.
#
# NICHT AM GERAET GEMESSEN. Kodi legt die Einstellungen eines Addons unter
# <Heimat>/.kodi/userdata/addon_data/<addon-id>/settings.xml ab; das ist die
# dokumentierte Ablage, im Hause aber an keinem laufenden Kodi nachgesehen
# worden - es lief keines. Wird die Datei nicht gefunden, wird das GEMELDET
# und nicht mit Vorgaben weitergerechnet.
my $addon_id = "service.callback.handler";
my @kodi_heim = ("/home/kodi", "/var/lib/kodi");

# ------------------------------------------------------------------
# Eingaben pruefen - HIER, nicht nur im Aufrufer.
#
# Diese Datei laeuft ueber sudo als ROOT und schreibt in die
# Bootkonfiguration. Die sudoers-Regel erlaubt dem Benutzer loxberry, sie
# mit BELIEBIGEN Argumenten aufzurufen:
#     %loxberry ALL = NOPASSWD: REPLACELBPBINDIR/elevatedhelper.pl
#
# Bis 1.0.0 wurde $R::value ungeprueft in die Ersetzung uebernommen. Ein
# Wert mit einem Zeilenumbruch darin haette damit beliebige weitere Zeilen
# in die config.txt geschrieben - etwa ein dtoverlay, das beim naechsten
# Start geladen wird. Die Oberflaeche maskiert zwar mit escapeshellarg,
# aber darauf darf sich der privilegierte Teil nicht verlassen: er ist die
# Grenze, an der die Rechte wechseln, und muss fuer sich selbst stehen.
#
# Erlaubt sind ausschliesslich die Werte, die es hier ueberhaupt gibt.
my $action = defined($R::action) ? $R::action : '';
my $key    = defined($R::key)    ? $R::key    : '';
my $value  = defined($R::value)  ? $R::value  : '';

# Auch die Verwendung von undef ist behoben: bis 1.0.0 stand hier
# $R::action eq "change" ohne defined-Pruefung. Fehlte das Argument, meldete
# "use warnings" eine uninitialisierte Variable ins Fehlerprotokoll.

my %erlaubte_schluessel = map { $_ => 1 } qw(licvc1 licmpeg2 kodiautostart kodi);
if ($action ne '' && $key ne '' && !$erlaubte_schluessel{$key}) {
	print STDERR "Unbekannter key: '$key'\n";
	print kopf(-type => 'application/json;charset=utf-8', -status => "400 Bad Request");
	print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"unknown key\"}";
	exit 1;
}
# Der Wert darf nur aus Buchstaben, Ziffern, Punkt, Bindestrich und
# Unterstrich bestehen. Kein Zeilenumbruch, kein Leerzeichen, kein
# Gleichheitszeichen - nichts, womit sich eine zweite Zeile in die
# config.txt schmuggeln liesse.
if ($value ne '' && $value !~ /\A[A-Za-z0-9._-]{1,32}\z/) {
	print STDERR "Unzulaessiger value: '$value'\n";
	print $cgi->header(-type => 'application/json;charset=utf-8', -status => "400 Bad Request");
	print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"invalid value\"}";
	exit 1;
}

# NEU IN 1.2.0: die Addon-Felder tragen ANDERE Werte als key/value oben - eine
# IP-Adresse, eine Portnummer, "true"/"false", ein MQTT-Thema mit
# Schraegstrichen. Die Positivliste dort waere dafuer zu eng.
#
# Sie wird deshalb NICHT gelockert. Jedes Addon-Feld bekommt sein eigenes
# Muster. Eine gemeinsame, weitere Liste waere die bequeme und die falsche
# Loesung: sie erlaubte den Lizenzfeldern still das, was nur die Addon-Felder
# brauchen - und die Lizenzfelder schreiben in die BOOTKONFIGURATION.
#
# Die Werte kommen url-kodiert an und werden von CGI dekodiert, damit ein
# Leerzeichen oder ein kaufmaennisches Und die Argumentkette nicht zerlegt.
# Geprueft wird NACH dem Dekodieren - vorher pruefte man die Kodierung.
my %addon_muster = (
	'udp_address'     => qr/\A[A-Za-z0-9._-]{0,64}\z/,
	'udp_port'        => qr/\A[0-9]{1,5}\z/,
	'volume_on_start' => qr/\A[0-9]{1,3}\z/,
	'mqtt_enable'     => qr/\A(true|false)\z/,
	'mqtt_address'    => qr/\A[A-Za-z0-9._-]{0,64}\z/,
	'mqtt_udpport'    => qr/\A[0-9]{1,5}\z/,
	'mqtt_topic'      => qr{\A[A-Za-z0-9._/-]{1,64}\z},
);
# ------------------------------------------------------------------

if ($action eq "change") {
	my $success;
	if ($key eq "licvc1") {
		$success = replace_str_in_file($configtxt, "decode_WVC1=", "decode_WVC1=$value");
	}
	if ($key eq "licmpeg2") {
		$success = replace_str_in_file($configtxt, "decode_MPG2=", "decode_MPG2=$value");
	}

	if ($key eq "kodiautostart") {
		# Den Rueckgabewert von system() ANSEHEN.
		#
		# Bis 1.2.0 stand hier ein unbedingtes $success = 1. Fehlt die Unit
		# kodi_ng.service - postroot.sh kann genau das melden -, scheitert
		# systemctl, und der Helfer antwortete trotzdem "OK": die Oberflaeche
		# meldete "Autostart eingeschaltet", und er war es nicht. Das ist
		# woertlich dieselbe Fehlerklasse, die weiter unten bei
		# replace_str_in_file als behoben beschrieben ist.
		my $rc = system(is_enabled($value)
			? "systemctl enable kodi_ng"
			: "systemctl disable kodi_ng");
		$success = ($rc == 0) ? 1 : 0;
		print STDERR "systemctl enable/disable kodi_ng failed (rc=$rc)\n" if (!$success);
	}

	if ($success) {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "200 OK");
		print "{\"status\":\"OK\", \"error\": 0, \"key\": \"$key\", \"value\": \"$value\"}";
	} else {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "500 Error");
		print "{\"status\": \"Error\", \"error\": 1, \"key\": \"$key\", \"value\": \"$value\"}";
	}
	exit;

}

if ($action eq "query") {
	my $mpeg2lic = find_str_in_file($configtxt, "decode_MPG2=");
	# print STDERR "TEST $mpeg2lic\n";
	my $vc1lic = find_str_in_file($configtxt, "decode_WVC1=");

	my $piserial = trim(find_str_in_file("/proc/cpuinfo", "Serial\t\t:"));
	$piserial = "Not found" if (! $piserial);

	# Pi 4/5: Codec-Lizenzen gibt es nicht mehr, vcgencmd kann fehlen -> tolerant sein
	my ($dummympeg2, $mpeg2status) = split(/=/, qx { vcgencmd codec_enabled MPG2 2>/dev/null } // "");
	my ($dummyvc1, $vc1status) = split(/=/, qx { vcgencmd codec_enabled WVC1 2>/dev/null } // "");
	$mpeg2status = "n/a (ab Pi 4 nicht mehr noetig)" if (!defined $mpeg2status || $mpeg2status eq "");
	$vc1status = "n/a (ab Pi 4 nicht mehr noetig)" if (!defined $vc1status || $vc1status eq "");
	chomp ($mpeg2status);
	chomp ($vc1status);
	$mpeg2lic = "" if (!defined $mpeg2lic);
	$vc1lic = "" if (!defined $vc1lic);

	my $kodi_autostart = qx { systemctl is-enabled kodi_ng };
	my $rc = $?;
	$rc = $rc >> 8 unless ($rc == -1);
	$kodi_autostart = $rc == 0 ? 1 : 0;

	my $kodi_started = qx { systemctl is-active kodi_ng };
	$rc = $?;
	$rc = $rc >> 8 unless ($rc == -1);
	$kodi_started = $rc == 0 ? 1 : 0;

	# NEU IN 1.2.0: der Cron-Eintrag und die Addon-Datei werden nicht hier
	# beantwortet - beide liegen dort, wo auch der Benutzer loxberry
	# hinsieht bzw. haben eine eigene Aktion. Diese Antwort bleibt deshalb
	# wortgleich zu 1.1.9; die Oberflaeche stuetzt sich darauf.

	print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "200 OK");
	print '{ ' .
		'"mpeg2lic":"' . json_text($mpeg2lic) . '",' .
		'"vc1lic":"' . json_text($vc1lic) . '",' .
		'"piserial":"' . json_text($piserial) . '",' .
		'"mpeg2status":"' . json_text($mpeg2status) . '",' .
		'"vc1status":"' . json_text($vc1status) . '",' .
		'"kodiautostart":"' . $kodi_autostart . '",' .
		'"kodistarted":"' . $kodi_started . '"' .

	'}';
	exit;
}

if ($action eq "service") {
	# Ein Merker, ob ueberhaupt etwas gelaufen ist.
	#
	# Bis 1.2.0 wurde blank $? ausgewertet. Traf weder key noch value zu -
	# und value darf laut der Pruefung oben alles aus [A-Za-z0-9._-]{1,32}
	# sein -, lief KEIN Kindprozess, $? stand noch auf seinem Anfangswert 0
	# (nachgemessen), und der Helfer antwortete "200 OK". Ein Aufrufer, der
	# daraufhin "Kodi neu gestartet" meldet, sagt die Unwahrheit.
	my $gelaufen = 0;
	if ($key eq "kodi" && $value eq "stop") {
		qx { systemctl stop kodi_ng };
		$gelaufen = 1;
	}
	if ($key eq "kodi" && $value eq "start") {
		qx { systemctl start kodi_ng };
		$gelaufen = 1;
	}
	if ($key eq "kodi" && $value eq "restart") {
		qx { systemctl restart kodi_ng };
		$gelaufen = 1;
	}
	if (!$gelaufen) {
		print STDERR "Unbekannter service-Befehl: key='$key' value='$value'\n";
		print $cgi->header(-type => 'application/json;charset=utf-8', -status => "400 Bad Request");
		print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"unknown service command\"}";
		exit 1;
	}
	my $rc = $?;
	$rc = $rc >> 8 unless ($rc == -1);
	if ($rc eq "0") {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "200 OK");
		print "{\"status\":\"OK\", \"error\": 0, \"key\": \"$key\", \"value\": \"$value\"}";
	} else {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "500 Error");
		print "{\"status\": \"Error\", \"error\": 1, \"key\": \"$key\", \"value\": \"$value\"}";
	}
	exit;
}

# ------------------------------------------------------------------
# NEU IN 1.2.0: die Einstellungen des Kodi-Addons lesen und schreiben.
#
# WARUM UEBER DEN HELFER. /home/kodi traegt seit 1.1.0 chmod 750 und gehoert
# kodi:kodi - darin liegen die Zugangsdaten der Medienquellen. Der Webserver
# laeuft als loxberry und kommt dort nicht hinein. Ohne diesen Weg koennte die
# Oberflaeche die vier Felder, an denen der ganze MQTT-Weg des Addons haengt,
# weder anzeigen noch setzen - und bis 1.1.9 tat sie beides nicht: das
# MQTT-Thema stand im Plugin und im Addon getrennt, und wer es im Plugin
# aenderte, bekam eine Themen-Tabelle auf den neuen Namen, waehrend das Addon
# weiter unter dem alten sendete. Ohne jede Meldung.
# ------------------------------------------------------------------

if ($action eq "addonread") {
	my $datei = addon_datei();
	if (!defined $datei) {
		print $cgi->header(-type => 'application/json;charset=utf-8', -status => "404 Not Found");
		print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"settings.xml not found\"}";
		exit 1;
	}
	my %w = addon_lesen($datei);
	print $cgi->header(-type => 'application/json;charset=utf-8', -status => "200 OK");
	print '{"status":"OK","error":0,"datei":"' . json_text($datei) . '","werte":{'
		. join(',', map { '"' . json_text($_) . '":"' . json_text($w{$_}) . '"' } sort keys %w)
		. '}}';
	exit;
}

if ($action eq "addonwrite") {
	# Erst ALLE Beanstandungen sammeln, dann entscheiden. Wer beim ersten
	# Verstoss aussteigt, schickt den Aufrufer in eine Schleife aus je einem
	# Fund pro Anlauf - und der Aufrufer ist hier ein Programm.
	my %neu;
	my @mangel;
	foreach my $feld (sort keys %addon_muster) {
		my $v = addon_arg($feld);
		next if (!defined $v);
		# LEER IST GUELTIG und heisst "nicht gesetzt" - so legt Kodi ein nie
		# angefasstes Feld ab, und so kommt es aus einer Sicherung zurueck.
		# Ohne diese Zeile laesst sich der Auslieferungszustand eines Geraets
		# nicht zurueckspielen; die PHP-Seite kennt dieselbe Regel.
		if ($v eq '') { $neu{$feld} = ''; next; }
		if ($v !~ $addon_muster{$feld}) {
			push @mangel, $feld;
			next;
		}
		# Ein regulaerer Ausdruck kann Zeichen pruefen, keinen Wertebereich.
		# Ohne diese vier Zeilen kaemen Port 0 und Port 99999 durch - beim
		# ersten sendet das Addon ins Nichts, beim zweiten wirft Python einen
		# OverflowError, den es selbst faengt und nur protokolliert. Beides
		# sieht von aussen aus wie "das Addon meldet nichts mehr".
		if ($feld =~ /port$/ && ($v < 1 || $v > 65535)) {
			push @mangel, $feld;
			next;
		}
		if ($feld eq 'volume_on_start' && $v > 100) {
			push @mangel, $feld;
			next;
		}
		$neu{$feld} = $v;
	}
	if (@mangel) {
		print STDERR "Unzulaessige Addon-Werte: " . join(', ', @mangel) . "\n";
		print $cgi->header(-type => 'application/json;charset=utf-8', -status => "400 Bad Request");
		print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"invalid addon value\", \"felder\": \""
			. json_text(join(',', @mangel)) . "\"}";
		exit 1;
	}
	if (!%neu) {
		print $cgi->header(-type => 'application/json;charset=utf-8', -status => "400 Bad Request");
		print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"nothing to write\"}";
		exit 1;
	}

	# Laeuft Kodi noch, schreibt es beim Beenden seine eigene settings.xml
	# zurueck und macht diese Aenderung damit ungeschehen. Ein Schalter, der
	# erst beim naechsten fremden Ausloeser wirkt, ist keiner - deshalb wird
	# hier ABGEWIESEN statt still zu schreiben. Die Oberflaeche haelt Kodi
	# vorher an, wenn der Anwender das anhakt.
	my $laeuft = qx { systemctl is-active kodi_ng };
	my $rc = $?;
	$rc = $rc >> 8 unless ($rc == -1);
	if ($rc == 0) {
		print $cgi->header(-type => 'application/json;charset=utf-8', -status => "409 Conflict");
		print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"kodi running\"}";
		exit 1;
	}

	my ($datei, $heim) = addon_datei_oder_ort();
	if (!defined $datei) {
		print $cgi->header(-type => 'application/json;charset=utf-8', -status => "404 Not Found");
		print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"no kodi home found\"}";
		exit 1;
	}

	# Vorhandene Werte uebernehmen - auch die, die dieses Plugin nicht kennt.
	# Ein unbekannter Schluessel wird NICHT stillschweigend fallengelassen:
	# er koennte zu einer neueren Fassung des Addons gehoeren.
	my %alle = (-e $datei) ? addon_lesen($datei) : ();
	foreach my $k (keys %neu) { $alle{$k} = $neu{$k}; }

	# Geschrieben wird die Schreibweise ab Kodi 19 "Matrix". Dieses Addon
	# verlangt ueber <import addon="xbmc.python" version="3.0.0"/> ohnehin
	# Kodi 19 oder neuer - aeltere Fassungen koennen es gar nicht ausfuehren.
	my $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<settings version=\"2\">\n";
	foreach my $k (sort keys %alle) {
		$xml .= "    <setting id=\"" . xml_text($k) . "\">" . xml_text($alle{$k}) . "</setting>\n";
	}
	$xml .= "</settings>\n";

	my $ok = addon_schreiben($datei, $xml, $heim);
	if (!$ok) {
		print $cgi->header(-type => 'application/json;charset=utf-8', -status => "500 Error");
		print "{\"status\": \"Error\", \"error\": 1, \"reason\": \"write failed\", \"datei\": \""
			. json_text($datei) . "\"}";
		exit 1;
	}
	print $cgi->header(-type => 'application/json;charset=utf-8', -status => "200 OK");
	print '{"status":"OK","error":0,"datei":"' . json_text($datei) . '","geschrieben":'
		. scalar(keys %neu) . '}';
	exit;
}

	print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "501 Action not implemented");
	print '{"status": "Not implemented", "error":1}';

exit;


sub replace_str_in_file
{
	my ($filename, $findstr, $replacestr) = @_;

	my $newfilestr;
	my $foundstr;

	return 0 if (! $filename || ! $findstr);

	# die statt warn - sonst faengt das eval nichts.
	#
	# warn wirft in Perl KEINE Ausnahme. Das eval darum herum konnte
	# deshalb nie ausloesen: schlug das Oeffnen fehl, schrieb warn eine
	# Zeile ins Fehlerprotokoll des Webservers, und die Schleife darunter
	# las anschliessend aus einem ungueltigen Dateizeiger. $@ blieb leer,
	# die Funktion meldete 1 - also Erfolg -, und die Oberflaeche zeigte
	# an, die Codec-Lizenz sei eingetragen worden, obwohl die Datei
	# unberuehrt blieb.
	#
	# Zur Einordnung: an den RECHTEN lag es nicht. Diese Datei laeuft ueber
	# sudo als root (siehe sudoers/sudoers), sie darf die config.txt sehr
	# wohl schreiben. Der Fehler war die Fehlerbehandlung selbst.
	eval {

		open(my $fh, '<', $filename)
		  or die "Could not open file for reading: '$filename': $!\n";

		while (my $row = <$fh>) {
			if (begins_with($row, $findstr)) {
				print STDERR "Found string - rewriting it";
				$newfilestr .= "$replacestr\n";
				$foundstr = 1;
			} else {
				$newfilestr .= $row;
			}
		}
		close $fh;
		if (! $foundstr) {
			# UNTER EINEM AUSDRUECKLICHEN [all] anhaengen.
			#
			# Die config.txt von Raspberry Pi OS bookworm ist in bedingte
			# Abschnitte geteilt ([pi4], [cm4], [all] ...). Wer eine Zeile
			# blank ans Dateiende haengt, legt sie in den ZULETZT geoeffneten
			# Abschnitt - sie gilt dann nur unter dessen Bedingung, und auf
			# einem Pi 5 wirkt ein Eintrag unter [pi4] gar nicht. Ein
			# zusaetzliches [all] schadet nie: es hebt die Bedingung auf, und
			# steht es doppelt, ist die zweite Zeile wirkungslos.
			print STDERR "Adding missing string under [all]";
			$newfilestr .= "\n[all]\n$replacestr\n";
		}

		# Erst in eine Zwischendatei, dann umbenennen.
		#
		# Bis 1.0.0 wurde die config.txt unmittelbar zum Schreiben
		# geoeffnet. Faellt in diesem Augenblick der Strom aus - und ein
		# Neustart gehoert bei diesem Plugin zum Ablauf -, steht die
		# Bootkonfiguration halb geschrieben auf der Karte, und der Rechner
		# startet nicht mehr.
		my $tmp = "$filename.kodiplugin.tmp";
		open($fh, '>', $tmp)
			or die "Could not open file for writing: '$tmp': $!\n";
		print $fh $newfilestr
			or die "Could not write to '$tmp': $!\n";
		close($fh)
			or die "Could not close '$tmp': $!\n";
		rename($tmp, $filename)
			or die "Could not replace '$filename': $!\n";

	};
	if ($@) {
		print STDERR "Writing to $filename failed: $@";
		unlink("$filename.kodiplugin.tmp");
		return 0;
	}

	return 1;

}

sub find_str_in_file
{
	my ($filename, $findstr) = @_;

	my $newfilestr;
	my $foundstr;
	my $strval;

	return undef if (! $filename || ! $findstr);

		# Hier genuegt die Rueckgabe undef, der Aufrufer wertet sie aus.
		# Ein warn allein war zu wenig: es landete nur im Fehlerprotokoll
		# des Webservers, und die Schleife darunter las aus einem
		# ungueltigen Dateizeiger weiter.
		open(my $fh, '<', $filename)
		  or do {
			print STDERR "Could not open file for reading: '$filename': $!\n";
			return undef;
		  };

		while (my $row = <$fh>) {
			if (begins_with($row, $findstr)) {
				print STDERR "Found string - parsing it\n";
				print STDERR "Length of $findstr: " . length($findstr) . "\n";
				chomp $row;
				$strval = substr ($row, length($findstr));
				print STDERR "Row   : $row \n";
				print STDERR "Strval: $strval \n";
				close $fh;
				return($strval);
			}

		}
		close $fh;

#	};
	# if ($@) {
		# print STDERR "Something failed reading the the file $filename.";
		# return undef;
	# }

	return undef;

}

# ------------------------------------------------------------------
# Helfer fuer die Addon-Einstellungen (neu in 1.2.0)
# ------------------------------------------------------------------

# Ein Addon-Argument holen. Die Felder heissen a_<name>, damit sie sich von
# key/value klar unterscheiden - CGI legt sie als $R::a_<name> ab. Der Zugriff
# laeuft ueber die Parameterliste statt ueber eine symbolische Referenz: unter
# "use strict" waere die Referenz ein Fehler, und ein no-strict-Block nur dafuer
# waere eine Ausnahme, die man spaeter fuer erlaubt haelt.
sub addon_arg
{
	my ($feld) = @_;
	my $v = $cgi->param("a_$feld");
	return undef if (!defined $v);
	return $v;
}

# Die vorhandene settings.xml, oder undef.
sub addon_datei
{
	foreach my $h (@kodi_heim) {
		my $d = "$h/.kodi/userdata/addon_data/$addon_id/settings.xml";
		return $d if (-e $d);
	}
	return undef;
}

# Die settings.xml oder - wenn es sie noch nicht gibt - der Ort, an den sie
# gehoert. Zweiter Rueckgabewert ist das Heimatverzeichnis, aus dem sich
# Eigentuemer und Gruppe ergeben.
sub addon_datei_oder_ort
{
	foreach my $h (@kodi_heim) {
		my $d = "$h/.kodi/userdata/addon_data/$addon_id/settings.xml";
		return ($d, $h) if (-e $d);
	}
	foreach my $h (@kodi_heim) {
		# Nur dort anlegen, wo Kodi wirklich wohnt - .kodi muss es geben.
		# Sonst legte dieses Skript als root ein Verzeichnis in einem
		# Heimatverzeichnis an, das einem anderen Programm gehoert.
		if (-d "$h/.kodi") {
			return ("$h/.kodi/userdata/addon_data/$addon_id/settings.xml", $h);
		}
	}
	return (undef, undef);
}

# Beide Schreibweisen lesen:
#     <setting id="x">wert</setting>       ab Kodi 19 "Matrix"
#     <setting id="x" value="wert" />      bis Kodi 18
sub addon_lesen
{
	my ($datei) = @_;
	my %w;
	open(my $fh, '<', $datei) or do {
		print STDERR "Could not open '$datei': $!\n";
		return %w;
	};
	local $/ = undef;
	my $roh = <$fh>;
	close $fh;
	return %w if (!defined $roh);

	# Zwischen id="…" und dem Ende des Anfangszeichens duerfen WEITERE
	# Attribute stehen. Kodi schreibt eine Einstellung, die auf ihrem
	# Vorgabewert steht, naemlich als
	#     <setting id="mqtt_topic" default="true">kodi</setting>
	#
	# Die frueheren Muster verlangten unmittelbar nach id="…" entweder > oder
	# ein value="…". Gemessen an einer Kodi-19-Datei mit fuenf Vorgabefeldern:
	# gelesen wurden 3 von 7. Das waere fuer sich schon eine falsche Anzeige -
	# schlimmer ist die zweite Stufe: addonwrite uebernimmt in %alle nur das
	# GELESENE und schreibt die Datei neu. Was nicht gelesen wurde, waere
	# damit STILL VERLOREN gegangen. Genau das, was der Kommentar dort zu
	# verhindern beansprucht.
	#
	# [^>]* statt \s+: es frisst kein > und kann deshalb nicht ueber das Ende
	# des Anfangszeichens hinauslaufen.
	while ($roh =~ m{<setting\s+id="([^"]*)"[^>]*?>(.*?)</setting>}gs) {
		$w{$1} = xml_roh($2);
	}
	while ($roh =~ m{<setting\s+id="([^"]*)"[^>]*?\svalue="([^"]*)"[^>]*/>}g) {
		$w{$1} = xml_roh($2) if (!exists $w{$1});
	}
	# Und die Form, bei der value VOR id steht.
	while ($roh =~ m{<setting\s[^>]*?\svalue="([^"]*)"[^>]*?\sid="([^"]*)"[^>]*/>}g) {
		$w{$2} = xml_roh($1) if (!exists $w{$2});
	}
	return %w;
}

# Atomar schreiben, Rechte und Eigentuemer VOR dem Umbenennen.
sub addon_schreiben
{
	my ($datei, $inhalt, $heim) = @_;
	my $verz = $datei;
	$verz =~ s{/[^/]+$}{};

	my $uid = (getpwnam('kodi'))[2];
	my $gid = (getgrnam('kodi'))[2];

	if (! -d $verz) {
		my $bisher = "";
		foreach my $teil (split(m{/}, $verz)) {
			next if ($teil eq '');
			$bisher .= "/$teil";
			next if (-d $bisher);
			mkdir($bisher, 0755) or do {
				print STDERR "mkdir '$bisher' failed: $!\n";
				return 0;
			};
			# Alles unterhalb des Heimatverzeichnisses gehoert kodi.
			if (defined $heim && defined $uid && defined $gid
			    && index($bisher, $heim . '/') == 0) {
				chown($uid, $gid, $bisher);
			}
		}
	}

	# ---------------------------------------------------------------
	# DIE ZWISCHENDATEI IST EIN ANGRIFFSZIEL, UND ZWAR AUF ROOT.
	#
	# Ihr Name ist vorhersagbar, und das Verzeichnis, in dem sie entsteht,
	# gehoert kodi:kodi - dort darf jedes Kodi-Addon schreiben. Ein blankes
	# open('>') haette einem dort vorab angelegten Symlink FOLGT: dieses
	# Skript laeuft ueber sudo als root, haette also die Zieldatei gekuerzt,
	# den Einstellungstext hineingeschrieben und sie anschliessend an
	# kodi:kodi uebereignet. Ein Symlink auf /etc/sudoers.d/x oder
	# /root/.ssh/authorized_keys genuegt, und aus "kodi" wird "root".
	#
	# Die Auflösung hat drei Teile, und jeder einzelne traegt schon:
	#   O_EXCL     schlaegt fehl, wenn der Pfad existiert - auch als Symlink
	#   O_NOFOLLOW schlaegt fehl, wenn der letzte Bestandteil ein Symlink ist
	#   fchmod/fchown ueber den DATEIZEIGER statt ueber den Namen - danach
	#              gibt es kein Zeitfenster mehr, in dem jemand die Datei
	#              unter uns austauschen koennte.
	#
	# Eine liegengebliebene Zwischendatei aus einem abgebrochenen Lauf wuerde
	# O_EXCL blockieren. Sie wird deshalb vorher entfernt - mit unlink, das
	# einem Symlink nicht folgt, sondern ihn selbst loescht.
	# ---------------------------------------------------------------
	my $tmp = "$datei.kodiplugin.tmp";
	unlink($tmp);
	my $ok = eval {
		sysopen(my $fh, $tmp, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW, 0644)
			or die "sysopen '$tmp': $!\n";
		# Rechte und Eigentuemer VOR dem Inhalt: sonst steht die Datei fuer
		# die Dauer des Schreibens mit den Vorgaben der umask da.
		chmod(0644, $fh) or chmod(0644, $tmp);
		if (defined $uid && defined $gid) {
			chown($uid, $gid, $fh) or chown($uid, $gid, $tmp);
		}
		print $fh $inhalt or die "write '$tmp': $!\n";
		close($fh) or die "close '$tmp': $!\n";
		rename($tmp, $datei) or die "rename to '$datei': $!\n";
		1;
	};
	if (!$ok) {
		print STDERR "Writing to $datei failed: $@";
		unlink($tmp);
		return 0;
	}
	return 1;
}

sub xml_text
{
	my ($t) = @_;
	$t = defined $t ? $t : '';
	$t =~ s/&/&amp;/g;
	$t =~ s/</&lt;/g;
	$t =~ s/>/&gt;/g;
	$t =~ s/"/&quot;/g;
	return $t;
}

sub xml_roh
{
	my ($t) = @_;
	$t = defined $t ? $t : '';
	$t =~ s/^\s+|\s+$//g;
	$t =~ s/&lt;/</g;
	$t =~ s/&gt;/>/g;
	$t =~ s/&quot;/"/g;
	$t =~ s/&apos;/'/g;
	# Das kaufmaennische Und ZULETZT - sonst wuerde aus &amp;lt; erst &lt;
	# und daraus faelschlich das Zeichen <.
	$t =~ s/&amp;/&/g;
	return $t;
}

# Eine Zeichenkette fuer die JSON-Ausgabe unschaedlich machen.
#
# Diese Datei baut ihr JSON von Hand - so war es schon vor 1.2.0. Bis dahin
# ungeprueft: der Wert aus decode_MPG2= und der Dateiname kommen aus Dateien,
# die dieses Skript nicht geschrieben hat. Ein Anfuehrungszeichen darin
# zerlegte die Antwort, und der Aufrufer bekaeme einen leeren Status zu sehen,
# ohne zu erfahren warum.
sub json_text
{
	my ($t) = @_;
	$t = defined $t ? $t : '';
	$t =~ s/\\/\\\\/g;
	$t =~ s/"/\\"/g;
	$t =~ s/([\x00-\x1f])/sprintf("\\u%04x", ord($1))/ge;
	return $t;
}
