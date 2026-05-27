<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;

class DonationModifyQueueTest extends BaseQueueTestCase {

  public function testRetryableACHFailure(): void {
    $this->createIndividual(['hash' => 'mousy_mouse']);
    $this->createPaymentProcessor();
    $msg = $this->getInitialContributionMessage();
    $this->processDonationMessage($msg, FALSE);
    $this->processDonationModifyMessage([
      'contribution_status_id:name' => 'Cancelled',
      'gateway_txn_id' => '338b9bc1-ff9f-48b9-a66c-742380770e96',
      'payment_method' => 'ach',
      'order_id' => '1234.1',
      'gross_currency' => 'USD',
      'gross' => 25.00,
      'backend_processor' => 'trustly',
      'backend_processor_txn_id' => '567890',
      'date' => 1784939264,
      'gateway' => 'gravy',
      'reason' => 'insufficient_funds',
      'can_retry' => true,
      'is_suspected_fraud' => false,
    ]);
    $contribution = $this->getContributionForMessage($msg);
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contribution['contribution_recur_id'])
      ->setSelect(['failure_count', 'contribution_status_id:name', 'cancel_date'])
      ->execute()
      ->first();
    $this->assertEquals('Cancelled', $contribution['contribution_status_id:name']);
    $this->assertEquals('2026-07-25 00:27:44', $contribution['cancel_date']);
    $this->assertEquals('insufficient_funds', $contribution['cancel_reason']);
    $this->assertEquals('Failing', $contributionRecur['contribution_status_id:name']);
    $this->assertEquals(1, $contributionRecur['failure_count']);
    $this->assertNull($contributionRecur['cancel_date']);
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $contribution['contribution_recur_id'])
      ->addWhere('activity_type_id:name', '=', 'Recurring Failure')
      ->execute()
      ->first();
    $this->assertEquals(
      'Payment of 25 USD failed with insufficient_funds', $activity['subject']
    );
  }

  public function testUnretryableACHFailure(): void {
    $this->createIndividual(['hash' => 'mousy_mouse']);
    $this->createPaymentProcessor();
    $msg = $this->getInitialContributionMessage();
    $this->processDonationMessage($msg, FALSE);
    $this->processDonationModifyMessage([
      'contribution_status_id:name' => 'Cancelled',
      'gateway_txn_id' => '338b9bc1-ff9f-48b9-a66c-742380770e96',
      'payment_method' => 'ach',
      'order_id' => '1234.1',
      'gross_currency' => 'USD',
      'gross' => 25.00,
      'backend_processor' => 'trustly',
      'backend_processor_txn_id' => '567890',
      'date' => 1784939264,
      'gateway' => 'gravy',
      'reason' => 'canceled_payment_method',
      'can_retry' => false,
      'is_suspected_fraud' => true,
    ]);
    $contribution = $this->getContributionForMessage($msg);
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contribution['contribution_recur_id'])
      ->setSelect(['failure_count', 'contribution_status_id:name', 'cancel_date'])
      ->execute()
      ->first();
    $this->assertEquals('Cancelled', $contribution['contribution_status_id:name']);
    $this->assertEquals('2026-07-25 00:27:44', $contribution['cancel_date']);
    $this->assertEquals('canceled_payment_method', $contribution['cancel_reason']);
    $this->assertEquals('Failed', $contributionRecur['contribution_status_id:name']);
    $this->assertNotNull($contributionRecur['cancel_date']);
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $contribution['contribution_recur_id'])
      ->addWhere('activity_type_id:name', '=', 'Recurring Failure')
      ->execute()
      ->first();
    $this->assertEquals(
      'Payment of 25 USD failed with canceled_payment_method', $activity['subject']
    );
  }

  protected function getInitialContributionMessage(): array {
    return [
      'first_name' => 'Lex',
      'last_name' => 'Mouse',
      'currency' => 'USD',
      'date' => '2026-06-07 01:02:03',
      'invoice_id' => '1234.1',
      'email' => 'testy@example.com',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'gravy',
      'gateway_txn_id' => '338b9bc1-ff9f-48b9-a66c-742380770e96',
      'backend_processor' => 'trustly',
      'backend_processor_txn_id' => '567890',
      'gross' => '25.00',
      'payment_method' => 'dd',
      'payment_submethod' => 'ach',
      'recurring' => 1,
      'recurring_payment_token' => mt_rand(),
      'user_ip' => '123.232.232.4',
    ];
  }

  /**
   * Process donation modify message
   */
  protected function processDonationModifyMessage(array $message): void {
    $this->processMessage($message, 'DonationModify', 'test');
  }
}
