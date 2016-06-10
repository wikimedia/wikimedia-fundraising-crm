<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class PhoneImportTest extends BaseWmfDrupalPhpUnitTestCase {

    public function testPhoneImport() {
      $this->refreshStripFunction();

        $phone = '555-555-5555';

        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',

            'phone' => $phone,
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );

        $api = civicrm_api_classapi();
        $api->Phone->Get( array(
            'contact_id' => $contribution['contact_id'],
        ) );

        $this->assertEquals( $phone, $api->values[0]->phone );
        $this->assertEquals( 1, $api->values[0]->is_primary );
        $this->assertEquals( wmf_civicrm_get_default_location_type_id(), $api->values[0]->location_type_id );
        $this->assertEquals( CRM_Core_OptionGroup::getValue('phone_type', 'phone'), $api->values[0]->phone_type_id );
    }

  /**
   * This SQL function is not created during the test at the right time & it seems the triggers ARE
   * created despite it not being present. This is not an issue on live (where the function seems to already exist).
   */
  public function refreshStripFunction() {
    civicrm_initialize();
    CRM_Core_DAO::executeQuery(CRM_Contact_BAO_Contact::DROP_STRIP_FUNCTION_43);
    CRM_Core_DAO::executeQuery(CRM_Contact_BAO_Contact::CREATE_STRIP_FUNCTION_43);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_phone SET phone_numeric = civicrm_strip_non_numeric(phone)");
  }

}
