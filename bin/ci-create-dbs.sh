#!/bin/bash -eu

BASEDIR=$(dirname $0)
. $BASEDIR/ci-settings.sh

echo "Creating databases with the prefix '${CIVICRM_SCHEMA_PREFIX}'"

for i in 1 2 3; do
	mysql -u root <<EOS
	drop database if exists ${CIVICRM_SCHEMA_PREFIX}${i};
	create database ${CIVICRM_SCHEMA_PREFIX}${i};
	grant all on ${CIVICRM_SCHEMA_PREFIX}${i}.* to '${CIVICRM_MYSQL_USERNAME}'@'${CIVICRM_MYSQL_CLIENT}' identified by '${CIVICRM_MYSQL_PASSWORD}';
EOS
done
