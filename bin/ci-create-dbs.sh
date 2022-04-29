#!/bin/bash -eu
# this file creates the databases for the jenkins CI job.
# on our dev installs they are created by buildkit
# the reason for doing it differently on jenkins is historical
# - in the past different ci jobs had not clash in their db names
# @todo remove this file / approach so we can unfork buildkit
# and use the same method locally and here.
BASEDIR=$(dirname "$0")
# shellcheck source=ci-settings.sh
. $BASEDIR/ci-settings.sh

mysql -u root <<EOS
	SET GLOBAL innodb_file_format='Barracuda';
	SET GLOBAL innodb_default_row_format='dynamic';
  SET GLOBAL innodb_file_per_table = 1;
  SET GLOBAL innodb_large_prefix = ON;
EOS
