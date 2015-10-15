<?php

class BaseWmfDrupalPhpUnitTestCase extends PHPUnit_Framework_TestCase {
    public function setUp() {
        parent::setUp();

        if ( !defined( 'DRUPAL_ROOT' ) ) {
            throw new Exception( "Define DRUPAL_ROOT somewhere before running unit tests." );
        }

        global $user, $_exchange_rate_cache;
        $_exchange_rate_cache = array();

        $user = new stdClass();
        $user->name = "foo_who";
        $user->uid = "321";
        $user->roles = array( DRUPAL_AUTHENTICATED_RID => 'authenticated user' );
    }

	/**
	 * Temporarily set foreign exchange rates to known values
	 *
	 * TODO: Should reset after each test.
	 */
	protected function setExchangeRates( $timestamp, $rates ) {
		foreach ( $rates as $currency => $rate ) {
			exchange_rate_cache_set( $currency, $timestamp, $rate );
		}
	}

	/**
	 * Create a temporary directory and return the name
	 * @return string|boolean directory path if creation was successful, or false
	 */
	protected function getTempDir() {
		$tempFile = tempnam( sys_get_temp_dir(), 'wmfDrupalTest_' );
		if ( file_exists( $tempFile ) ) {
			unlink( $tempFile );
		}
		mkdir( $tempFile );
		if ( is_dir( $tempFile ) ) {
			return $tempFile . '/';
		}
		return false;
	}
}
