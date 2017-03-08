<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class AddressImportTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Contact ID
   *
   * @var int
   */
  protected $contactID;

  public function setUp() {
    civicrm_initialize();
    $contact = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org')
    );
    $this->contactID = $contact['id'];
  }

  public function tearDown() {
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_contact WHERE last_name = 'Mouse'");
  }

  /**
   * Test creating an address with void data does not create an address.
   */
  public function testAddressImportVoidData() {
    $msg = array(
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'street_address' => 'N0NE PROVIDED',
      'postal_code' => 0,
    );

    $contribution = wmf_civicrm_contribution_message_import($msg);
    $addresses = $this->callAPISuccess('Address', 'get', array('contact_id' => $contribution['contact_id']));
    $this->assertEquals(0, $addresses['count']);
  }

  /**
   * Test creating an address with void data does not create an address.
   *
   * In this case the contact already exists.
   */
  public function testAddressImportVoidDataContactExists() {
    $msg = array(
      'contact_id' => $this->contactID,
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'street_address' => 'N0NE PROVIDED',
      'postal_code' => 0,
    );

    $contribution = wmf_civicrm_contribution_message_import($msg);
    $addresses = $this->callAPISuccess('Address', 'get', array('contact_id' => $contribution['contact_id']));
    $this->assertEquals(0, $addresses['count']);
  }

}
