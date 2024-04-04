<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;

/**
 * @group queues
 */
class DonationQueueTest extends BaseQueue {

  protected string $queueName = 'test';

  protected string $queueConsumer = 'Donation';

  /**
   * Process an ordinary (one-time) donation message
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonation(): void {
    $message = $this->processDonationMessage([
      'gross' => 400,
      'original_gross' => 400,
      'original_currency' => 'USD',
    ]);
    $message2 = $this->processDonationMessage();
    $this->processContributionTrackingQueue();
    $expected = [
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Mouse, Mickey',
      'contact_id.display_name' => 'Mickey Mouse',
      'contact_id.first_name' => 'Mickey',
      'contact_id.last_name' => 'Mouse',
      'currency' => 'USD',
      'total_amount' => '400.00',
      'fee_amount' => '0.00',
      'net_amount' => '400.00',
      'trxn_id' => 'GLOBALCOLLECT ' . $message['gateway_txn_id'],
      'source' => 'USD 400.00',
      'financial_type_id:label' => 'Cash',
      'contribution_status_id:label' => 'Completed',
      'payment_instrument_id:label' => 'Credit Card: Visa',
      'invoice_id' => $message['order_id'],
      'Gift_Data.Campaign' => 'Online Gift',
    ];
    $this->assertExpectedContributionValues($expected, $message['gateway_txn_id'], $message['contribution_tracking_id']);

    $expected = [
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Mouse, Mickey',
      'contact_id.display_name' => 'Mickey Mouse',
      'contact_id.first_name' => 'Mickey',
      'contact_id.last_name' => 'Mouse',
      'currency' => 'USD',
      'total_amount' => '476.17',
      'fee_amount' => '0.00',
      'net_amount' => '476.17',
      'trxn_id' => 'GLOBALCOLLECT ' . $message2['gateway_txn_id'],
      'source' => 'PLN 952.34',
      'financial_type_id:label' => 'Cash',
      'contribution_status_id:label' => 'Completed',
      'payment_instrument_id:label' => 'Credit Card: Visa',
      'invoice_id' => $message2['order_id'],
      'Gift_Data.Campaign' => 'Online Gift',
    ];
    $this->assertExpectedContributionValues($expected, $message2['gateway_txn_id']);
  }

  /**
   * If 'invoice_id' is in the message, don't stuff that field with order_id
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonationInvoiceId(): void {
    $message = $this->processDonationMessage(
      ['invoice_id' => mt_rand()]
    );

    $expected = [
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Mouse, Mickey',
      'contact_id.display_name' => 'Mickey Mouse',
      'contact_id.first_name' => 'Mickey',
      'contact_id.last_name' => 'Mouse',
      'currency' => 'USD',
      'total_amount' => '476.17',
      'fee_amount' => '0.00',
      'net_amount' => '476.17',
      'trxn_id' => 'GLOBALCOLLECT ' . $message['gateway_txn_id'],
      'source' => 'PLN 952.34',
      'financial_type_id:label' => 'Cash',
      'contribution_status_id:label' => 'Completed',
      'payment_instrument_id:label' => 'Credit Card: Visa',
      'invoice_id' => $message['invoice_id'],
      'Gift_Data.Campaign' => 'Online Gift',
    ];
    $this->assertExpectedContributionValues($expected, $message['gateway_txn_id']);
  }

  /**
   * @param array $expected
   * @param int $gatewayTxnID
   * @param int|null $contributionTrackingID
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function assertExpectedContributionValues(array $expected, int $gatewayTxnID, ?int $contributionTrackingID = NULL): void {
    $returnFields = array_keys($expected);
    $returnFields[] = 'contact_id';

    $contribution = Contribution::get(FALSE)
      ->setSelect($returnFields)
      ->addWhere('contribution_extra.gateway_txn_id', '=', $gatewayTxnID)
      ->execute()->first();

    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $contribution[$key], 'unexpected value for ' . $key);
    }
    if ($contributionTrackingID) {
      $tracking = ContributionTracking::get(FALSE)
        ->addWhere('contribution_id', '=', $contribution['id'])
        ->execute()->first();
      $this->assertEquals($tracking['id'], $contributionTrackingID);
    }
  }

}
