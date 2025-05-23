<?php

// This can be run like
//
// drush -r /var/www/htdocs -l example.com -u 1 civicrm-api extendedmailingstats.cron auth=0 -y > /dev/null

function civicrm_api3_extendedmailingstats_cron($params) {
  _extendedmailingstats_cron($params);
}
