<?php

/**
 * Helps us not break the DonationInterfaceFactory gateway adapter wrapper
 *
 * @group WmfCivicrm
 */
class DonationInterfaceFactoryTest extends BaseWmfDrupalPhpUnitTestCase {

	/**
	 * Do we blow up on the launch pad?
	 */
	public function testCreateAdapter() {
		$values = array(
			'amount' => 9.99,
			'effort_id' => 1,
			'order_id' => mt_rand(),
			'currency_code' => 'USD',
			'payment_product' => '',
			'language' => 'en',
			'contribution_tracking_id' => mt_rand(),
			'referrer' => 'dummy',
		);
		$adapter = DonationInterfaceFactory::createAdapter( 'globalcollect', $values );
		// see FIXME in recurring globalcollect
		$adapter->addRequestData( array(
			'effort_id' => 1,
		) );
		$data = $adapter->getData_Unstaged_Escaped();
		foreach ( $values as $key => $value ) {
			$this->assertEquals( $value, $data[$key], "$key is being mangled in adapter construction" );
		}
	}
}
