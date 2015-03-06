#!/bin/bash

if [ "x${JOB_NAME}" = "x" ]; then
	echo "JOB_NAME environment variable was not set, exiting."
	exit 1
fi
if [ "x${BUILD_NUMBER}" = "x" ]; then
	echo "BUILD_NUMBER environment variable was not set, exiting."
	exit 1
fi

# MYSQL database name cant use spaces or dashes:
JOB_ID="${JOB_NAME// /_}_${BUILD_NUMBER}"
JOB_ID="${JOB_ID//-/_}"

CIVICRM_SCHEMA_PREFIX="civicrm_${JOB_ID}_"

BUILD_HOST=`hostname`
# MySQL username is limited to 16 chars, use build number as an identifier:
CIVICRM_MYSQL_USERNAME="civitest_${BUILD_NUMBER}"

CIVICRM_MYSQL_PASSWORD="pw_${JOB_ID}"
