<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class SoftCreditTest extends BaseWmfDrupalPhpUnitTestCase {

  public function testSoftCredit() {
    $organizationID = $this->createTestContact([
      'contact_type' => 'Organization',
      'organization_name' => 'Big Pharma',
    ]);
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'soft_credit_to' => 'Big Pharma',
    ];

    $contribution = $this->messageImport($msg);

    $retrievedContribution = $this->callAPISuccessGetSingle('Contribution', [
      'id' => $contribution['id'],
      'return' => [
        'soft_credit_to' => 1,
      ],
    ]);

    $this->assertEquals($organizationID, $retrievedContribution['soft_credit_to']);
  }

  /**
   * @expectedException WmfException
   * @expectedExceptionMessage Bad soft credit target
   */
  public function testBadSoftCreditTarget() {
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'soft_credit_to' => 'Not a thing',
    ];

    $contribution = $this->messageImport($msg);
  }

}
