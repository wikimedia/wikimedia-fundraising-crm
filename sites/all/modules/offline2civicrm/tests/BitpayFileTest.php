<?php

/**
 * @group Import
 * @group Offline2Civicrm
 */
class BitpayFileTest extends BaseChecksFileTest {

  protected $sourceFileUri = '';
  protected $importClass = 'BitpayFile';

  function setUp() {
    parent::setUp();
    $this->ensureAnonymousContactExists();
  }

  public function testImport() {
    civicrm_initialize();
    $messages = $this->importFile('bitpay.csv');
    $contact = $this->callAPISuccess('Contact', 'get', array(
      'email' => 'fox.mulder@pm.me',
      'sequential' => 1,
    ));
    $this->assertEquals('90210', $contact['values'][0]['postal_code']);
    $this->assertEquals('fox.mulder@pm.me', $contact['values'][0]['email']);
    $this->assertEquals('Fox', $contact['values'][0]['first_name']);
    $this->assertEquals('Mulder', $contact['values'][0]['last_name']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['contact_id' => $contact['id']]);
    $this->assertEquals(10, $contribution['total_amount']);
    $this->assertEquals('2018-03-06 00:00:00', $contribution['receive_date']);
    $this->assertEquals('BITPAY 5GV8V8IZB3PJTMCFJ26AOQ', $contribution['trxn_id']);
    $this->assertEquals('USD', $contribution['currency']);
    $this->assertEquals('EUR 10', $contribution['contribution_source']);
  }

  /**
   * Do the file import.
   *
   * @param string $filename
   * @param array $additionalFields
   *
   * @return array
   */
  protected function importFile($filename, $additionalFields = array()) {
    $importer = new $this->importClass(__DIR__ . "/data/" . $filename, $additionalFields);
    $importer->import();
    return $importer->getMessages();
  }

}
