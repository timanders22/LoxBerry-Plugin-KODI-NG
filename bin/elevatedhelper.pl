#!/usr/bin/perl

use LoxBerry::System;
use CGI;
use warnings;
use strict;

our $cgi = CGI->new;
$cgi->import_names('R');
my  $version = "1.0.0";

# LoxBerry 4 / Raspberry Pi OS bookworm: config.txt liegt unter /boot/firmware/
my $configtxt = -f "/boot/firmware/config.txt" ? "/boot/firmware/config.txt" : "/boot/config.txt";

if ($R::action eq "change") {
	my $success;
	if ($R::key eq "licvc1") {
		$success = replace_str_in_file($configtxt, "decode_WVC1=", "decode_WVC1=$R::value");
	}
	if ($R::key eq "licmpeg2") {
		$success = replace_str_in_file($configtxt, "decode_MPG2=", "decode_MPG2=$R::value");
	}

	if ($R::key eq "kodiautostart") {
		if (is_enabled($R::value)) {
			system("systemctl enable kodi");
		} else {
			system("systemctl disable kodi");
		}
		$success = 1;
	}
	
	if ($success) {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "200 OK");
		print "{\"status\":\"OK\", \"error\": 0, \"key\": \"$R::key\", \"value\": \"$R::value\"}";
	} else {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "500 Error");
		print "{\"status\": \"Error\", \"error\": 1, \"key\": \"$R::key\", \"value\": \"$R::value\"}";
	}
	exit;

}

if ($R::action eq "query") {
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
	
	my $kodi_autostart = qx { systemctl is-enabled kodi };
	my $rc = $?;
	$rc = $rc >> 8 unless ($rc == -1);
	$kodi_autostart = $rc == 0 ? 1 : 0;
	
	my $kodi_started = qx { systemctl is-active kodi };
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

if ($R::action eq "service") {
	if ($R::key eq "kodi" && $R::value eq "stop") {
		qx { systemctl stop kodi };
	} 
	if ($R::key eq "kodi" && $R::value eq "start") {
		qx { systemctl start kodi };
	} 
	if ($R::key eq "kodi" && $R::value eq "restart") {
		qx { systemctl restart kodi };
	} 
	my $rc = $?;
	$rc = $rc >> 8 unless ($rc == -1);
	if ($rc eq "0") {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "200 OK");
		print "{\"status\":\"OK\", \"error\": 0, \"key\": \"$R::key\", \"value\": \"$R::value\"}";
	} else {
		print $cgi->header(-type => 'application/json;charset=utf-8',
					-status => "500 Error");
		print "{\"status\": \"Error\", \"error\": 1, \"key\": \"$R::key\", \"value\": \"$R::value\"}";
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
	
	eval {

		open(my $fh, '<', $filename)
		  or warn "Could not open file for reading: '$filename' $!";
		  
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
		
		open($fh, '>', $filename)
			or warn "Could not open file for writing: '$filename' $!";
		print $fh $newfilestr;
		close $fh;
		
	};
	if ($@) {
		print STDERR "Something failed writing the new entry to file $filename.";
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
	
#	eval {

		open(my $fh, '<', $filename)
		  or warn "Could not open file for reading: '$filename' $!";
		  
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

