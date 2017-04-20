#!/bin/bash

BASEDIR=$(dirname $0)
. $BASEDIR/ci-settings.sh

echo "Populating databases with the prefix '${CIVICRM_SCHEMA_PREFIX}'"

export PRECREATED_DSN_PATTERN="mysql://${CIVICRM_MYSQL_USERNAME}:${CIVICRM_MYSQL_PASSWORD}@${CIVICRM_MYSQL_CLIENT}/${CIVICRM_SCHEMA_PREFIX}{{db_seq}}"
export AMPHOME="${WORKSPACE}/.amp-${BUILD_NUMBER}"
export NO_SAMPLE_DATA=1

#FIXME: --web-root="$WORKSPACE/src/crm"

$WORKSPACE/src/wikimedia/fundraising/civicrm-buildkit/bin/civi-download-tools

$WORKSPACE/src/wikimedia/fundraising/civicrm-buildkit/bin/amp config:set \
	--db_type=mysql_precreated \
	--httpd_type=none \
	--perm_type=none

rm -rf $WORKSPACE/src/wikimedia/fundraising/civicrm-buildkit/build/wmff
mkdir -p $WORKSPACE/src/wikimedia/fundraising/civicrm-buildkit/build
ln -s $WORKSPACE/src/wikimedia/fundraising/crm $WORKSPACE/src/wikimedia/fundraising/civicrm-buildkit/build/wmff

$WORKSPACE/src/wikimedia/fundraising/civicrm-buildkit/bin/civibuild reinstall wmff
