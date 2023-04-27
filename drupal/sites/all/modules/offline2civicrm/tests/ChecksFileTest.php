<?php

use Civi\Api4\Contact;
use Civi\WMFException\EmptyRowException;

/**
 * @group Import
 * @group Offline2Civicrm
 */
class ChecksFileTest extends BaseChecksFileTest {

  public $trxn_id = 6789;

  public function setUp(): void {
    parent::setUp();
    require_once __DIR__ . "/includes/ChecksFileProbe.php";
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
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testImportCountry(): void {
    // A few kinds of empty.
    $data = [
      'Check Number' => mt_rand(),
      'City' => 'blah city',
      'Country' => 'AR',
      'Email' => 'email@phony.com',
      'External Batch Number' => mt_rand(),
      'First Name' => 'Daisy',
      'Gift Source' => 'Community Gift',
      'Last Name' => 'Duck',
      'Original Amount' => '123',
      'Original Currency' => 'USD',
      'Payment Instrument' => 'Trilogy',
      'Postal Code' => '90210',
      'Postmark Date' => '2012-02-02',
      'Received Date' => '2017-07-07',
      'State' => 'CA',
      'Street Address' => '123 Sunset Boulevard',
      'Transaction ID' => $this->trxn_id,
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
