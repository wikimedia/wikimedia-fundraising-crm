<?php

use Civi\Api4\Contact;

/**
 * @group Import
 * @group Offline2Civicrm
 */
class CitibankFileTest extends BaseChecksFileTest {

  public function setUp():void {
    parent::setUp();
    $this->trxn_ids = array('S1234123445401', 'F123412349E701');
    $this->gateway = 'citibank';
  }

  public function tearDown(): void {
    Contact::delete(FALSE)->addWhere('source', '=', 'citibank import')->execute();
    parent::tearDown();
  }

  /**
   * Test basic import.
   *
   * @throws \CRM_Core_Exception
   * @throws \League\Csv\Exception
   * @throws \WmfException
   */
  function testImport() {
    civicrm_initialize();

    $importer = new CitibankFile(__DIR__ . "/data/citibank.csv");
    $messages = $importer->import();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contribution = wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $this->trxn_ids[0])[0];
    $this->assertEquals('284620.85', $contribution['total_amount']);
    $this->assertEquals('USD', $contribution['currency']);

    $contact = $this->callAPISuccessGetSingle('Contact', array('id' => $contribution['contact_id']));
    $this->assertEquals('Organization Name', $contact['display_name']);
    $this->assertEquals('Organization', $contact['contact_type']);
  }

}
