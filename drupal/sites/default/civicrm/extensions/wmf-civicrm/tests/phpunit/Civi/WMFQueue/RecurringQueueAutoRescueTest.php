<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Api4\MessageTemplate;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFException\WMFException;

/**
 * @group queues
 * @group Recurring
 */
class RecurringQueueAutoRescueTest extends BaseQueueTestCase {

  protected string $queueName = 'recurring';

  protected string $queueConsumer = 'Recurring';

  /**
   * Test use of Auto Rescue message consumption
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function testRecurringQueueConsumeAutoRescueMessage(): void {
    $rescueReference = 'MT6S49RV4HNG5S82';
    $orderId = "279.2";
    $recur = $this->createContributionRecur([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'contribution_recur_smashpig.rescue_reference' => $rescueReference,
      'invoice_id' => $orderId,
    ]);

    $this->createContribution([
      'total_amount' => $recur['amount'],
      'payment_instrument_id:name' => "Credit Card: Visa",
      'contribution_recur_id' => $recur['id'],
      'amount' => $recur['amount'],
      'currency' => $recur['currency'],
      'contact_id' => $recur['contact_id'],
      'receive_date' => date('Y-m-d H:i:s', strtotime('-1 month')),
      'gateway' => 'adyen',
      'trxn_id' => $recur['trxn_id'],
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
    ]);

    $date = time();
    $message = $this->getAutoRescueMessage($date, $rescueReference, $orderId);
    $this->processMessage($message);

    $updatedRecur = ContributionRecur::get(FALSE)
      ->addSelect('*', 'contribution_status_id:name')
      ->addWhere('id', '=', $recur['id'])
      ->execute()
      ->first();

    $this->assertEquals('In Progress', $updatedRecur['contribution_status_id:name']);

    // check that the generated next_sched date is between 27 and 31 days away
    $today = \DateTime::createFromFormat("U", $date);
    $nextMonth = new \DateTime($updatedRecur['next_sched_contribution_date']);
    $difference = $nextMonth->diff($today)->days;
    $this->assertGreaterThanOrEqual(27, $difference);
    $this->assertLessThanOrEqual(31, $difference);
    $this->assertStringContainsString($orderId, $this->getContributionForMessage($message)['invoice_id']);
  }

  public function testRecurringQueueConsumeAutoRescueMessageNoPriorContribution(): void {
    $rescueReference = 'MT6S49RV4HNG5S82';
    $orderId = "279.2";
    $recur = $this->createContributionRecur([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'contribution_recur_smashpig.rescue_reference' => $rescueReference,
      'invoice_id' => $orderId,
      'payment_instrument_id:name' => "Credit Card: Visa",
    ]);

    $date = time();
    $message = $this->getAutoRescueMessage($date, $rescueReference, $orderId);
    $this->processMessage($message);

    $updatedRecur = ContributionRecur::get(FALSE)
      ->addSelect('*', 'contribution_status_id:name')
      ->addWhere('id', '=', $recur['id'])
      ->execute()
      ->first();

    $this->assertEquals('In Progress', $updatedRecur['contribution_status_id:name']);

    // check that the generated next_sched date is between 27 and 31 days away
    $today = \DateTime::createFromFormat("U", $date);
    $nextMonth = new \DateTime($updatedRecur['next_sched_contribution_date']);
    $difference = $nextMonth->diff($today)->days;
    $this->assertGreaterThanOrEqual(27, $difference);
    $this->assertLessThanOrEqual(31, $difference);
    $this->assertStringContainsString($orderId, $this->getContributionForMessage($message)['invoice_id']);
  }

  public function testAutoRescueNoPredecessor(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionMessage('INVALID_MESSAGE No payment type found for message');
    $this->createContributionRecur([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'contribution_recur_smashpig.rescue_reference' => 12345,
      'invoice_id' => 6789,
    ]);
    $message = $this->getAutoRescueMessage(time(), 12345, 6789);
    $this->processMessageWithoutQueuing($message);
  }

  public function testAutoRescueCancellation(): void {
    // setup example recurring
    $rescueReference = 'MT6S49RV4HNG5S82';
    $orderId = "279.2";
    $recur = $this->createContributionRecur([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'contribution_recur_smashpig.rescue_reference' => $rescueReference,
      'invoice_id' => $orderId,
      'payment_instrument_id:name' => "Credit Card: Visa",
    ]);
    // cancel the recurring
    $cancel = $this->getRecurringCancelMessage();
    $cancel['rescue_reference'] = $rescueReference;
    $cancel['is_autorescue'] = 'true';
    $cancel['cancel_reason'] = 'Payment cannot be rescued: maximum failures reached';
    $this->processMessage($cancel);

    $updatedRecur = ContributionRecur::get(FALSE)
      ->addSelect('*', 'contribution_status_id:name')
      ->addWhere('id', '=', $recur['id'])
      ->execute()
      ->first();

    $this->assertEquals('Cancelled', $updatedRecur['contribution_status_id:name']);
  }

  /**
   * @param int $date
   * @param string $rescueReference
   * @param string $orderId
   *
   * @return array
   */
  public function getAutoRescueMessage(int $date, string $rescueReference, string $orderId): array {
    return $this->getRecurringPaymentMessage(
      [
        'txn_type' => 'subscr_payment',
        'gateway' => 'adyen',
        'gateway_txn_id' => 'L4X6T3WDS8NK3GK82',
        'date' => $date,
        'is_successful_autorescue' => TRUE,
        'rescue_reference' => $rescueReference,
        'currency' => 'USD',
        'amount' => 10,
        'order_id' => $orderId,
        'source_name' => 'CiviCRM',
        'source_type' => 'direct',
        'source_host' => '051a7ac1b08d',
        'source_run_id' => 10315,
        'source_version' => 'unknown',
        'source_enqueued_time' => 1694530827,
      ]
    );
  }

}
