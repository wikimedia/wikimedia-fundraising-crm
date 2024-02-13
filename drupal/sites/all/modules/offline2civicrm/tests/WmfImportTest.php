<?php

use Civi\Api4\ContributionTracking;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Import
 * @group Offline2Civicrm
 */
class WmfImportTest extends BaseChecksFileTest {

  /**
   * Existing contribution_tracking row is updated with contribution_id
   */
  public function testImportExistingTracking(): void {
    $contribution_tracking_id = $this->addContributionTracking([
      'utm_source' => 'Blah_source',
      'utm_medium' => 'civicrm',
      'utm_campaign' => 'test_campaign',
      'ts' => 'now',
    ]);

    $this->trxn_id = mt_rand();
    $this->gateway = 'globalcollect';
    $data = [
      'City' => 'blah city',
      'Contribution Tracking ID' => $contribution_tracking_id,
      'Country' => 'AR',
      'Email' => 'email@phony.com',
      'External Batch Number' => mt_rand(),
      'First Name' => 'Mickey',
      'Gift Source' => 'Online Gift',
      'Last Name' => 'Mouse',
      'Original Amount' => '123',
      'Original Currency' => 'USD',
      'Payment Gateway' => $this->gateway,
      'Payment Instrument' => 'Apple',
      'Postal Code' => '90210',
      'Postmark Date' => '2012-02-02',
      'Received Date' => '2017-07-07',
      'State' => 'CA',
      'Street Address' => '123 Sunset Boulevard',
      'Transaction ID' => $this->trxn_id,
    ];
    $importer = new WmfImportFile();
    $exposed = TestingAccessWrapper::newFromObject($importer);
    $message = $exposed->parseRow($data);
    $exposed->doImport($message);
    $this->consumeCtQueue();

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $this->gateway, $this->trxn_id
    );
    $this->assertEquals(1, count($contributions));
    $contribution = $contributions[0];
    $this->assertEquals($this->gateway, $contribution['gateway']);
    $ct = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $contribution_tracking_id)
      ->execute()->first();
    $this->assertEquals($contribution['id'], $ct['contribution_id']);
    // TODO: should update existing c_t row with country!
    // $this->assertEquals('AR', $ct['country']);
  }

}
