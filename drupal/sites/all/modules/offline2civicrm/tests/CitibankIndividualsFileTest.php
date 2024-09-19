<?php

/**
 * @group Import
 * @group Offline2Civicrm
 */
class CitibankIndividualsFileTest extends BaseChecksFileTest {

  public function setUp(): void {
    $params = [
      'contact_type' => 'Individual',
      'last_name' => 'Citibank',
      'email' => 'fakecitibankemail@wikimedia.org',
    ];
    $citibankContact = $this->callAPISuccess('Contact', 'get', $params);
    if (!$citibankContact['count']) {
      $this->callAPISuccess('Contact', 'create', $params);
    }
    parent::setUp();
    $this->setExchangeRates(strtotime('2019-01-02'), ['USD' => 1, 'EUR' => 3]);
  }

  public function tearDown(): void {
    $contributions = civicrm_api3('Contribution', 'get', ['trxn_id' => 'CITIBANK 5739498974'])['values'];
    foreach ($contributions as $contribution) {
      $this->cleanUpContribution($contribution['id']);
    }
    parent::tearDown();
  }

  /**
   * Test basic import.
   *
   * @throws \CRM_Core_Exception
   * @throws \League\Csv\Exception
   * @throws \Civi\WMFException\WMFException
   */
  function testImport() {
    civicrm_initialize();

    $importer = new CitibankIndividualsFile($this->getCsvDirectory() . "citibank_individuals.csv");
    $messages = $importer->import();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['trxn_id' => 'CITIBANK 5739498974']);
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];
    $this->assertEquals('EUR 3000.00', $contribution['contribution_source']);
    $this->assertEquals('Citibank International', $contribution['payment_instrument']);
    $this->assertEquals(9000, $contribution['total_amount']);
  }

}
