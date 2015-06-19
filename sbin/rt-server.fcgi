#!/usr/bin/perl -w
# BEGIN BPS TAGGED BLOCK {{{
#
# COPYRIGHT:
#
# This software is Copyright (c) 1996-2015 Best Practical Solutions, LLC
#                                          <sales@bestpractical.com>
#
# (Except where explicitly superseded by other copyright notices)
#
#
# LICENSE:
#
# This work is made available to you under the terms of Version 2 of
# the GNU General Public License. A copy of that license should have
# been provided with this software, but in any event can be snarfed
# from www.gnu.org.
#
# This work is distributed in the hope that it will be useful, but
# WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
# General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
# 02110-1301 or visit their web page on the internet at
# http://www.gnu.org/licenses/old-licenses/gpl-2.0.html.
#
#
# CONTRIBUTION SUBMISSION POLICY:
#
# (The following paragraph is not intended to limit the rights granted
# to you to modify and distribute this software under the terms of
# the GNU General Public License and is only of importance to you if
# you choose to contribute your changes and enhancements to the
# community by submitting them to Best Practical Solutions, LLC.)
#
# By intentionally submitting any modifications, corrections or
# derivatives to this work, or any other work intended for use with
# Request Tracker, to Best Practical Solutions, LLC, you confirm that
# you are the copyright holder for those contributions and you grant
# Best Practical Solutions,  LLC a nonexclusive, worldwide, irrevocable,
# royalty-free, perpetual, license to use, copy, create derivative
# works based on those contributions, and sublicense and distribute
# those contributions and any derivatives thereof.
#
# END BPS TAGGED BLOCK }}}
use warnings;
use strict;

BEGIN {
    die <<EOT if ${^TAINT};
RT does not run under Perl's "taint mode".  Remove -T from the command
line, or remove the PerlTaintCheck parameter from your mod_perl
configuration.
EOT
}

# fix lib paths, some may be relative
BEGIN { # BEGIN RT CMD BOILERPLATE
    require File::Spec;
    require Cwd;
    my @libs = ("/var/www/localhost/rt-4.2.11/lib", "/var/www/localhost/rt-4.2.11/local/lib");
    my $bin_path;

    for my $lib (@libs) {
        unless ( File::Spec->file_name_is_absolute($lib) ) {
            $bin_path ||= ( File::Spec->splitpath(Cwd::abs_path(__FILE__)) )[1];
            $lib = File::Spec->catfile( $bin_path, File::Spec->updir, $lib );
        }
        unshift @INC, $lib;
    }

}

use Getopt::Long;
no warnings 'once';

if (grep { m/help/ } @ARGV) {
    require Pod::Usage;
    print Pod::Usage::pod2usage( { verbose => 2 } );
    exit;
}

require RT;
die "Wrong version of RT $RT::VERSION found; need 4.2.*"
    unless $RT::VERSION =~ /^4\.2\./;

RT->LoadConfig();
RT->InitPluginPaths();
RT->InitLogging();

require RT::Handle;
my ($integrity, $state, $msg) = RT::Handle->CheckIntegrity;

unless ( $integrity ) {
    print STDERR <<EOF;

RT couldn't connect to the database where tickets are stored.
If this is a new installation of RT, you should visit the URL below
to configure RT and initialize your database.

If this is an existing RT installation, this may indicate a database
connectivity problem.

The error RT got back when trying to connect to your database was:

$msg

EOF

    require RT::Installer;
    # don't enter install mode if the file exists but is unwritable
    if (-e RT::Installer->ConfigFile && !-w _) {
        die 'Since your configuration exists ('
          . RT::Installer->ConfigFile
          . ") but is not writable, I'm refusing to do anything.\n";
    }

    RT->Config->Set( 'LexiconLanguages' => '*' );
    RT::I18N->Init;

    RT->InstallMode(1);
} else {
    RT->Init( Heavy => 1 );

    my ($status, $msg) = RT::Handle->CheckCompatibility( $RT::Handle->dbh, 'post');
    unless ( $status ) {
        print STDERR $msg, "\n\n";
        exit -1;
    }
}

# we must disconnect DB before fork
if ($RT::Handle) {
    $RT::Handle->dbh->disconnect if $RT::Handle->dbh;
    $RT::Handle->dbh(undef);
    undef $RT::Handle;
}

require RT::PlackRunner;
# when used as a psgi file
if (caller) {
    return RT::PlackRunner->app;
}


my $r = RT::PlackRunner->new( RT->InstallMode    ? ( server => 'Standalone' ) :
                              $0 =~ /standalone/ ? ( server => 'Standalone' ) :
                              $0 =~ /fcgi$/      ? ( server => 'FCGI',    env => "deployment" )
                                                 : ( server => 'Starlet', env => "deployment" ) );
$r->parse_options(@ARGV);

# Try to clean up wrong-permissions var/
$SIG{INT} = sub {
    local $@;
    system("chown", "-R", "apache:apache", "/var/www/localhost/rt-4.2.11/var");
    exit 0;
} if $> == 0;

$r->run;

__END__

=head1 NAME

rt-server - RT standalone server

=head1 SYNOPSIS

    # runs prefork server listening on port 8080, requires Starlet
    rt-server --port 8080

    # runs server listening on port 8080
    rt-server --server Standalone --port 8080
    # or
    standalone_httpd --port 8080

    # runs other PSGI server on port 8080
    rt-server --server Starman --port 8080