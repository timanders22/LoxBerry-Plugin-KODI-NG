#!/usr/bin/perl

use LoxBerry::System;
use CGI;
use warnings;
use strict;

our $cgi = CGI->new;
$cgi->import_names('R');
my  $version = "1.1.0";

# LoxBerry 4 / Raspberry Pi OS bookworm: config.txt liegt unter /boot/firmware/
my $configtxt = -f "/boot/firmware/config.txt" ? "/boot/firmware/config.txt" : "/boot/config.txt";

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
	print $cgi->header(-type => 'application/json;charset=utf-8', -status => "400 Bad Request");
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
		if (is_enabled($value)) {
			system("systemctl enable kodi_ng");
		} else {
			system("systemctl disable kodi_ng");
		}
		$success = 1;
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
	
	
	print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "200 OK");
	print '{ ' . 
		'"mpeg2lic":"' . $mpeg2lic . '",' . 
		'"vc1lic":"' . $vc1lic . '",' . 
		'"piserial":"' . $piserial . '",' . 
		'"mpeg2status":"' . $mpeg2status . '",' . 
		'"vc1status":"' . $vc1status . '",' . 
		'"kodiautostart":"' . $kodi_autostart . '",' . 
		'"kodistarted":"' . $kodi_started . '"' . 
	
	'}';
	exit;
}

if ($action eq "service") {
	if ($key eq "kodi" && $value eq "stop") {
		qx { systemctl stop kodi_ng };
	} 
	if ($key eq "kodi" && $value eq "start") {
		qx { systemctl start kodi_ng };
	} 
	if ($key eq "kodi" && $value eq "restart") {
		qx { systemctl restart kodi_ng };
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
			print STDERR "Adding missing string";
			$newfilestr .= "$replacestr\n";
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

