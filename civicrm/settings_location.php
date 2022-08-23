<?php
/**
 * The directory name where civicrm.settings.php file is located.
 * Used where CiviCRM is part of an install profile like CiviCRM Starterkit.
 */
if (!defined('CIVICRM_CONFDIR')) {
  define( 'CIVICRM_CONFDIR', realpath( dirname( __FILE__ )) . "/../drupal/sites/default" );
}
