<?php

if ( count( $argv ) !== 6 ) {
	$usage = <<<EOT
Usage: php ${argv[0]} SITE_NAME CIVICRM_DB DRUPAL_DB DB_USER DB_PASS

Example: php ${argv[0]} civi.localhost.net civicrm drupal civiUser civiPass

EOT;
	die( $usage );
}

$basedir = __DIR__ . '/..';

$SITE_NAME = $argv[1];
$CIVICRM_DB = $argv[2];
$DRUPAL_DB = $argv[3];
$DB_USER = $argv[4];
$DB_PASS = $argv[5];

$config = array(
    'site_dir' => 'default',
    'base_url' => "http://${SITE_NAME}/",
    'mysql' => array(
        'username' => $DB_USER,
        'password' => $DB_PASS,
        'server' => 'localhost',
        'database' => $CIVICRM_DB,
    ),
    'drupal' => array(
        'username' => $DB_USER,
        'password' => $DB_PASS,
        'server' => 'localhost',
        'database' => $DRUPAL_DB,
    ),
);

global $cmsPath, $crmPath, $installType;
$cmsPath = "${basedir}/drupal";
$crmPath = "${basedir}/civicrm";
$installType = 'drupal';

define( 'VERSION', '7.0' );
define( 'DB_USER', $config['drupal']['username'] );
define( 'DB_PASSWORD', $config['drupal']['password'] );
define( 'DB_HOST', $config['drupal']['server'] );
define( 'DB_NAME', $config['drupal']['database'] );

require_once "${basedir}/civicrm/install/civicrm.php";

civicrm_main( $config );
