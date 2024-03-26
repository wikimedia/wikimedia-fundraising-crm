<?php

namespace Civi\WMFQueue;

use Civi\Api4\ContributionRecur;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
/**
 * @group queues
 * @group Recurring
 */
class RecurringQueueAutoRescueTest extends BaseQueue {

  protected string $queueName = 'recurring';

  protected string $queueConsumer = 'Recurring';

  /**
   * Test use of Auto Rescue message consumption
   *
   * @throws \CRM_Core_Exception
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
    $this->ids['ContributionRecur'][$recur['id']] = $recur['id'];

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
    $message = $this->getRecurringPaymentMessage(
      [
        'txn_type' => 'subscr_payment',
        'gateway' => 'adyen',
        'gateway_txn_id' => 'L4X6T3WDS8NKGK82',
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
    $this->processMessage($message);

    // importMessage adds the id to cleanup but it had already been added in getTestContributionRecurRecords
    unset($this->ids['Contact'][$recur['contact_id']]);

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

}
