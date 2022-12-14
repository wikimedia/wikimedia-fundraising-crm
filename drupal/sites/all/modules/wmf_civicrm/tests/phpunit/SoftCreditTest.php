<?php

use Civi\WMFException\WMFException;

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
      'email' => 'somebody@wikimedia.org',
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

  public function testBadSoftCreditTarget(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionMessage("Bad soft credit target");
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'soft_credit_to' => 'Not a thing',
    ];
    $this->messageImport($msg);
  }

}
