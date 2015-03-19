#!/bin/bash

if [ -z "$BUILD_TAG" ]; then
	echo "BUILD_TAG environment variable was not set, exiting."
	exit 1
fi

# MYSQL database name can't use spaces or dashes:
JOB_ID="${BUILD_TAG//-/_}"

CIVICRM_SCHEMA_PREFIX="civicrm_${JOB_ID}_"

CIVICRM_MYSQL_CLIENT="localhost"
# MySQL username is limited to 16 chars, use build number as an identifier:
CIVICRM_MYSQL_USERNAME="civitest_${BUILD_NUMBER}"

CIVICRM_MYSQL_PASSWORD="pw_${JOB_ID}"
