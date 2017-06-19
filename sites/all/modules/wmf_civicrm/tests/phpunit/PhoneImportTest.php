<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class PhoneImportTest extends BaseWmfDrupalPhpUnitTestCase {

    public function testPhoneImport() {
      $this->refreshStripFunction();

        $phoneNumber = '555-555-5555';

        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',
            'phone' => $phoneNumber,
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );

        $phones = $this->callAPISuccess('Phone', 'get', array('contact_id' => $contribution['contact_id'], 'sequential' => 1));
        $phone = $phones['values'][0];

        $this->assertEquals($phoneNumber, $phone['phone']);
        $this->assertEquals(1, $phone['is_primary']);
        $this->assertEquals(wmf_civicrm_get_default_location_type_id(), $phone['location_type_id']);
        $this->assertEquals(CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Phone', 'phone_type_id', 'Phone'), $phone['phone_type_id']);
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
