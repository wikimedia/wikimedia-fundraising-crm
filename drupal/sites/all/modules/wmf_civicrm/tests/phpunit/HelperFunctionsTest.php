<?php

/**
 * @group WmfCivicrm
 * @group WmfCivicrmHelpers
 */
class HelperFunctionsTest extends BaseWmfDrupalPhpUnitTestCase {

    /**
     * Test wmf_ensure_language_exists
     *
     * If that ever gets fixed it may break this test - but only the test would
     * need to be altered to adapt.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testEnsureLanguageExists() {
        civicrm_initialize();
        wmf_civicrm_ensure_language_exists('en_IL');
        $language = $this->callAPISuccessGetSingle('OptionValue', [
          'option_group_name' => 'languages',
          'name' => 'en_IL',
        ]);

        $this->callAPISuccess('OptionValue', 'create', ['id' => $language['id'], 'is_active' => 0]);
        wmf_civicrm_ensure_language_exists('en_IL');

        $this->callAPISuccessGetSingle('OptionValue', [
          'option_group_name' => 'languages',
          'name' => 'en_IL',
        ]);
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
