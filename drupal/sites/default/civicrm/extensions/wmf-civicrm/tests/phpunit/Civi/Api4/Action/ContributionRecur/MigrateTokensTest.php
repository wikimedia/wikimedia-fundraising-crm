<?php

namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use PHPUnit\Framework\TestCase;

/**
 * Test that our MigrateTokens API action correctly updates token and recur rows
 */
class MigrateTokensTest extends TestCase {

  protected $contactID;

  /**
   * The tearDown() method is executed after the test was executed (optional).
   *
   * This can be used for cleanup.
   *
   * @throws \API_Exception
   */
  public function tearDown(): void {
    Contribution::delete(FALSE)
      ->addWhere('contact_id', '=', $this->contactID)->execute();
    ContributionRecur::delete(FALSE)
      ->addWhere('contact_id', '=', $this->contactID)->execute();
    PaymentToken::delete(FALSE)
        ->addWhere('contact_id', '=', $this->contactID)->execute();
    Contact::delete(FALSE)->addWhere('display_name', '=', 'Horatio Hornblower')->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /**
   * Check that we correctly migrate the tokens using data from a file.
   */
  public function testMigrateTokens(): void {
    $this->contactID = Contact::create(FALSE)
      ->setValues(['first_name' => 'Horatio', 'last_name' => 'Hornblower', 'contact_type' => 'Individual'])
      ->execute()->first()['id'];
    $ingenicoId = $this->getPaymentProcessorId('ingenico');
    $adyenId = $this->getPaymentProcessorId('adyen');
    // We make two ingenico payment_tokens with guid-style tokens as listed in the test CSV
    $ingenicoToken1 = PaymentToken::create(FALSE)
      ->setValues([
        'payment_processor_id' => $ingenicoId,
        'contact_id' => $this->contactID,
        'token' => 'a64d6ae4-20a3-4e4e-8174-3e0f03203c8f',
        'ip_address' => '123.45.67.89'
      ])->execute()->first()['id'];
    $ingenicoToken2 = PaymentToken::create(FALSE)
      ->setValues([
        'payment_processor_id' => $ingenicoId,
        'contact_id' => $this->contactID,
        'token' => '9b008d46-4b9d-4f12-a40c-ffa6a6a6a62a',
        'ip_address' => '98.76.54.32'
      ])->execute()->first()['id'];
    // first recur is attached to the first ingenico token
    $contributionRecurID1 = $this->createContributionAndRecur(10, '110011.1|recur-1234', $ingenicoId, $ingenicoToken1);
    // both second AND third recurs are attached to the second ingenico token
    $contributionRecurID2 = $this->createContributionAndRecur(2, '220022.1|recur-34566', $ingenicoId, $ingenicoToken2);
    $contributionRecurID3 = $this->createContributionAndRecur(3, '330033.1|recur-76543', $ingenicoId, $ingenicoToken2);

    // Run the code under test
    ContributionRecur::migrateTokens(FALSE)
      ->setPath(__DIR__ . '/../../../../../data/tokensToMigrate.csv' )
      ->execute();

    // Check that the contribution_recur and payment_token rows have been updated from the CSV.
    // Note that there should now be three different payment tokens.
    // int $contributionRecurID, int $adyenID, string $adyenToken, string $invoiceID
    $this->assertExpectedValues($contributionRecurID1, $adyenId, 'QWSDTF54NFTBST32', '110011.1');
    $this->assertExpectedValues($contributionRecurID2, $adyenId, 'LXSDTF54NFTBST32', '220022.1');
    $this->assertExpectedValues($contributionRecurID3, $adyenId, 'VXSDTF54NFTBST32', '330033.1');
  }

  private function getPaymentProcessorId($name): int {
    return PaymentProcessor::get(FALSE)
      ->addWhere('is_test', '=', 0)
      ->addWhere('name', '=', $name)
      ->setSelect(['id'])
      ->execute()->first()['id'];
  }

  private function createContributionAndRecur(
    int $amount, string $invoiceID, int $processorID, int $tokenID
  ): int {
    $contributionRecurID = ContributionRecur::create(FALSE)
      ->setValues([
        'amount' => $amount,
        'currency' => 'USD',
        'contact_id' => $this->contactID,
        'next_sched_contribution_date' => '+5 days',
        'contribution_status_id:name' => 'In Progress',
        'payment_processor_id' => $processorID,
        'payment_token_id' => $tokenID,
      ])
      ->execute()
      ->first()['id'];
    Contribution::create(FALSE)
      ->setValues([
        'total_amount' => $amount,
        'contact_id' => $this->contactID,
        'contribution_recur_id' => $contributionRecurID,
        'currency' => 'USD',
        'invoice_id' => $invoiceID,
        'receive_date' => '-25 days',
        'financial_type_id' => 1
      ])
    ->execute();
    return $contributionRecurID;
  }

  private function assertExpectedValues(int $contributionRecurID, int $adyenID, string $adyenToken, string $invoiceID) {
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contributionRecurID)
      ->addSelect('invoice_id', 'payment_processor_id', 'payment_token_id')->execute()->first();
    $this->assertEquals($invoiceID, $contributionRecur['invoice_id']);
    $this->assertEquals($adyenID, $contributionRecur['payment_processor_id']);

    $token = PaymentToken::get(FALSE)
      ->addWhere('id', '=', $contributionRecur['payment_token_id'])
      ->addSelect('payment_processor_id', 'token')->execute()->first();
    $this->assertEquals($adyenID, $token['payment_processor_id']);
    $this->assertEquals($adyenToken, $token['token']);
  }
}
