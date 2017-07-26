#!/bin/bash -eu

if [ $# -ne 3 ]; then
	app_name=$(basename "$0")
	cat <<-EOS
		Usage: $app_name DB_URL SITE_NAME ADMIN_PASSWORD

		Example: $app_name mysql://DB_USER:DB_PASS@localhost/DRUPAL_DB crm.localhost.net ADMIN123
	EOS

	exit 1
fi

DB_URL=$1
SITE_NAME=$2
ADMIN_PASSWORD=$3

drush \
	--root="$(dirname "$0")"/../drupal \
    site-install standard \
    --db-url="$DB_URL" \
    --site-name="$SITE_NAME" \
    --account-name=admin --account-pass="$ADMIN_PASSWORD" \
    install_configure_form.update_status_module='array(FALSE,FALSE)'
