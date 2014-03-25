<?php

class BaseWmfDrupalPhpUnitTestCase extends PHPUnit_Framework_TestCase {
    public function setUp() {
        parent::setUp();

        if ( !defined( 'DRUPAL_ROOT' ) ) {
            throw new Exception( "Define DRUPAL_ROOT somewhere before running unit tests." );
        }

        // Argh.  Crib from _drush_bootstrap_drupal_site_validate
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        chdir( DRUPAL_ROOT );
        require_once( "includes/bootstrap.inc" );
        drupal_bootstrap( DRUPAL_BOOTSTRAP_FULL );
    }
}
