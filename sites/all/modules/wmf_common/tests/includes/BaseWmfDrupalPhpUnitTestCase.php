<?php

class BaseWmfDrupalPhpUnitTestCase extends PHPUnit_Framework_TestCase {
    public function setUp() {
        parent::setUp();

        if ( !defined( 'DRUPAL_ROOT' ) ) {
            throw new Exception( "Define DRUPAL_ROOT somewhere before running unit tests." );
        }
    }
}
