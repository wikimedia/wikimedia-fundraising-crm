<?php
use Civi\Api4\Contribution;

class EndowmentImportMessageTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Test that endowment donation is imported with the right fields for
   * restrictions, gift_source, and financial_type_id
   * see: https://phabricator.wikimedia.org/T343756
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function testEndowmentDonationImport(): void {
    $contactID = $this->createIndividual();
    $this->callAPISuccess('Email', 'create', [
      'email' => 'Agatha@wikimedia.org',
      'on_hold' => 1,
      'location_type_id' => 1,
      'contact_id' => $contactID,
    ]);

    $msg = [
      'contact_id' => $contactID,
      'contribution_recur_id' => NULL,
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Agatha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => 2.34,
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'utm_medium' => 'endowment',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $contributionFromDB = Contribution::get(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addSelect('custom.*', '*')
      ->execute()->first();

    $this->assertEquals($contributionFromDB['Gift_Data.Fund'], "Endowment Fund");
    $this->assertEquals($contributionFromDB['Gift_Data.Campaign'], "Online Gift");
    $this->assertEquals($contributionFromDB['financial_type_id'], CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Endowment Gift"));
  }

}
