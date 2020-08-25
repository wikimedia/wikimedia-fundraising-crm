<?php
// We use our master database for our logging.
global $civirpow;
if (!empty($civirpow['masters'][0])) {
  define('CIVICRM_LOGGING_DSN', $civirpow['masters'][0]);
}
