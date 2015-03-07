#!/bin/bash

BASEDIR=$(dirname $0)
. $BASEDIR/ci-settings.sh

echo "Dropping databases with the prefix '${CIVICRM_SCHEMA_PREFIX}'"

for i in 1 2 3; do
	mysql -u root <<EOS
	drop database if exists ${CIVICRM_SCHEMA_PREFIX}${i};
EOS
done

mysql -u root <<EOS
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${CIVICRM_MYSQL_USERNAME}'@'${CIVICRM_MYSQL_CLIENT}';
DROP USER '${CIVICRM_MYSQL_USERNAME}'@'${CIVICRM_MYSQL_CLIENT}';
EOS
