<?php

/**
 * NASTY but manually loaded to get around autoload nonsense.
 *
 * Injects statically set vars into instance testing fields.
 */
class TestingRecurringStubAdapter extends TestingGlobalCollectAdapter {
	public static $singletonDummyGatewayResponseCode;

	public function __construct( $options = array() ) {
		parent::__construct( $options );
		
		if ( self::$singletonDummyGatewayResponseCode ) {
			$this->setDummyGatewayResponseCode( self::$singletonDummyGatewayResponseCode );
		}
	}
}
