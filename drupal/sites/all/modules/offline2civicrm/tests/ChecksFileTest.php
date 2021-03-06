<?php

use Civi\Api4\Contact;
use Civi\WMFException\EmptyRowException;

/**
 * @group Import
 * @group Offline2Civicrm
 */
class ChecksFileTest extends BaseChecksFileTest {

  function setUp(): void {
    parent::setUp();
    require_once __DIR__ . "/includes/ChecksFileProbe.php";
  }

  public function tearDown(): void {
    Contact::delete(FALSE)
      ->addWhere('first_name', '=', 'Test_first_name')
      ->execute();
    parent::tearDown();
  }

  public function testEmptyRow(): void {
    $this->expectException(EmptyRowException::class);
    // A few kinds of empty.
    $data = [
      'Original Currency' => '',
      '' => '',
    ];

    $importer = new ChecksFileProbe();
    $importer->_parseRow($data);
  }

  /**
   * Populate contribution_tracking.country
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function testImportCountry() {
    // A few kinds of empty.
    $data = [
      'Check Number' => mt_rand(),
      'City' => 'blah city',
      'Country' => 'AR',
      'Email' => 'email@phony.com',
      'External Batch Number' => mt_rand(),
      'First Name' => 'Test_first_name',
      'Gift Source' => 'Community Gift',
      'Last Name' => 'Test_last_name',
      'Original Amount' => '123',
      'Original Currency' => 'USD',
      'Payment Instrument' => 'Trilogy',
      'Postal Code' => '90210',
      'Postmark Date' => '2012-02-02',
      'Received Date' => '2017-07-07',
      'State' => 'CA',
      'Street Address' => '123 Sunset Boulevard',
      'Transaction ID' => mt_rand(),
    ];

    $importer = new ChecksFileProbe();
    $message = $importer->_parseRow($data);
    $importer->doImport($message);
    $this->consumeCtQueue();

    $contribution = $this->callAPISuccessGetSingle(
      'Contribution', ['trxn_id' => "GENERIC_IMPORT {$data['Transaction ID']}"]
    );
    $ct = db_select('contribution_tracking', 'contribution_tracking')
      ->fields('contribution_tracking')
      ->condition('contribution_id', $contribution['id'])
      ->execute()
      ->fetchAssoc();
    $this->assertEquals('AR', $ct['country']);
  }

}
