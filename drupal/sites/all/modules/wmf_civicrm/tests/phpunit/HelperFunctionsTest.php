<?php

use Civi\Api4\OptionValue;

/**
 * @group WmfCivicrm
 * @group WmfCivicrmHelpers
 */
class HelperFunctionsTest extends BaseWmfDrupalPhpUnitTestCase {

  public function tearDown(): void {
    OptionValue::delete(FALSE)
      ->addWhere('option_group_id:name', '=', 'languages')
      ->addWhere('name', '=', 'en_IL')
      ->execute();
    parent::tearDown();
  }

  /**
   * Test that the payment instrument is converted to an id.
   *
   * Use a high number to ensure the default 25 limit does not hurt us.
   *
   * @throws \Civi\WMFException\WMFException
   */
    public function testGetCiviID() {
      civicrm_initialize();
      $paymentMethodID = wmf_civicrm_get_civi_id('payment_instrument_id', 'Trilogy');
      $this->assertTrue(is_numeric($paymentMethodID));
    }

  /**
   * Test that the payment instrument is converted to an id.
   *
   * Use a high number to ensure the default 25 limit does not hurt us.
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function testGetInvalidCiviID() {
    civicrm_initialize();
    $paymentMethodID = wmf_civicrm_get_civi_id('payment_instrument_id', 'Monopoly money');
    $this->assertEquals(FALSE, $paymentMethodID);
  }

}
