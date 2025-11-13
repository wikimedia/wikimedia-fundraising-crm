<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Email;
use Civi\Api4\Queue;
use Civi\Api4\QueueItem;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFException\WMFException;

/**
 * @group queues
 * @group Recurring
 */
class RecurringQueueAutoRescueTest extends BaseQueueTestCase {

  protected string $queueName = 'recurring';

  protected string $queueConsumer = 'Recurring';

  public function tearDown(): void {
    QueueItem::delete(FALSE)
      ->addWhere('queue_name', '=', 'email')
      ->execute();
    parent::tearDown();
  }

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
      ->addSelect('*', 'contribution_status_id:name')->addWhere('id', '=', $recur['id'])
      ->execute()
      ->first();

    $this->assertEquals('Cancelled', $updatedRecur['contribution_status_id:name']);
  }

  /**
   * Verifies that recurring failure email is sent when a user has no other active recurring contributions.
   */
  public function testAutoRescueCancellationEmailSent(): void {
    // Add recurring contribution to be cancelled
    $rescueReference = 'ABC-123';
    $orderId = "1001.1";
    $recur = $this->createContributionRecur([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'contribution_recur_smashpig.rescue_reference' => $rescueReference,
      'invoice_id' => $orderId,
      'payment_instrument_id:name' => "Credit Card: Visa",
    ]);

    // Create an email record (needed to send Failure Email)
    $testEmail = 'test@example.org';
    Email::create(FALSE)
      ->setValues([
        'contact_id' => $recur['contact_id'],
        'email' => $testEmail,
        'is_primary' => TRUE,
      ])->execute();

    $cancelMessage = $this->getRecurringCancelMessage([
      'rescue_reference' => $rescueReference,
      'is_autorescue' => 'true',
      'cancel_reason' => 'Payment cannot be rescued: maximum failures reached',
      'contact_id' => $recur['contact_id'],
      'order_id' => $orderId,
      'recurring_payment_id' => $recur['id'],
      'email' => $testEmail,
    ]);

    // Count email activities before processing cancellation
    $preCancellationActivities = Activity::get(FALSE)
      ->addSelect('*', 'activity_type_id:name')
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('source_record_id', '=', $recur['id'])
      ->execute();

    $preCancellationActivitiesCount = $preCancellationActivities->count();

    // Cancel recurring contribution
    $this->processMessage($cancelMessage);

    // Verify contribution was cancelled
    $updatedRecur = ContributionRecur::get(FALSE)
      ->addSelect('*', 'contribution_status_id:name')
      ->addSelect('contribution_recur_smashpig.rescue_reference')
      ->addWhere('id', '=', $recur['id'])
      ->execute()
      ->first();
    $this->assertEquals('Cancelled', $updatedRecur['contribution_status_id:name']);

    // Verify that the rescue reference was cleared
    $this->assertEquals('', $updatedRecur['contribution_recur_smashpig.rescue_reference']);

    $item = QueueItem::get(FALSE)
      ->addWhere('queue_name', '=', 'email')
      ->execute()->first();

    $this->assertNotNull($item);

    // Run the queue task to send the email
    Queue::run(FALSE)
      ->setQueue('email')
      ->execute();

    // Verify failure email was sent (check for new Email activity)
    $postCancellationActivities = Activity::get(FALSE)
      ->addSelect('*', 'activity_type_id:name')
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('source_record_id', '=', $recur['id'])
      ->execute();

    $postActivitiesCount = $postCancellationActivities->count();

    // 1 new failure email activity should be created
    $this->assertEquals($preCancellationActivitiesCount + 1, $postActivitiesCount);

    // Additionally, verify the activity subject matches recurring failure email
    $emailFailureActivity = $postCancellationActivities->last();
    $this->assertStringContainsString('Recur fail message :', $emailFailureActivity['subject']);

    // clean up email
    Email::delete(FALSE)
      ->addWhere('id', '=', $recur['contact_id'])
      ->execute();
  }

  /**
   * Verifies that failure email is not sent when a user has other active recurring contributions.
   */
  public function testAutoRescueCancellationEmailNotSentWhenAnotherActiveRecurring(): void {
    // Setup first recurring contribution (will be cancelled)
    $rescueReference = 'ABC-123';
    $orderId = "1001.1";
    $recur1 = $this->createContributionRecur([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'contribution_recur_smashpig.rescue_reference' => $rescueReference,
      'invoice_id' => $orderId,
      'payment_instrument_id:name' => "Credit Card: Visa",
    ]);

    // Create payment token
    $paymentToken = \Civi\Api4\PaymentToken::create(FALSE)
      ->addValue('contact_id', $recur1['contact_id'])
      ->addValue('token', 'tok_test123')
      ->addValue('created_date', date('Y-m-d H:i:s'))
      ->addValue('expiry_date', date('Y-m-d H:i:s', strtotime('+1 year')))
      ->addValue('email', 'test@example.com')
      ->addValue('payment_processor_id.name', 'adyen')
      ->execute()
      ->first();

    // Setup second recurring contribution (will remain active)
    $recur2 = $this->createContributionRecur([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'invoice_id' => "1002.1",
      'payment_instrument_id:name' => "Credit Card: Visa",
      'trxn_id' => '1234.1',
      'payment_token_id' => $paymentToken['id'],
      'contact_id' => $recur1['contact_id'],
      'contribution_status_id:name' => 'In Progress',
    ]);

    // Create an email record (needed to send Failure Email)
    $testEmail = 'test@example.org';
    Email::create(FALSE)
      ->setValues([
        'contact_id' => $recur1['contact_id'],
        'email' => $testEmail,
        'is_primary' => TRUE,
      ])->execute();

    // Cancel the first recurring contribution
    $cancel = $this->getRecurringCancelMessage([
      'rescue_reference' => $rescueReference,
      'is_autorescue' => 'true',
      'cancel_reason' => 'Payment cannot be rescued: maximum failures reached',
      'contact_id' => $recur1['contact_id'],
      'order_id' => $orderId,
      'recurring_payment_id' => $recur1['id'],
      'email' => $testEmail,
    ]);

    // Count email activities before processing
    $preCancellationActivities = Activity::get(FALSE)
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('source_record_id', '=', $recur1['id'])
      ->execute();

    $preCancellationActivitiesCount = $preCancellationActivities->count();

    $this->processMessage($cancel);

    // Verify the first recurring contribution was cancelled
    $updatedRecur1 = ContributionRecur::get(FALSE)
      ->addSelect('*', 'contribution_status_id:name')
      ->addSelect('contribution_recur_smashpig.rescue_reference')
      ->addWhere('id', '=', $recur1['id'])
      ->execute()
      ->first();
    $this->assertEquals('Cancelled', $updatedRecur1['contribution_status_id:name']);
    // Verify that the rescue reference was cleared
    $this->assertEquals('', $updatedRecur1['contribution_recur_smashpig.rescue_reference']);

    // Verify the second contribution is still active
    $updatedRecur2 = ContributionRecur::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $recur2['id'])
      ->execute()
      ->first();
    $this->assertEquals('In Progress', $updatedRecur2['contribution_status_id:name']);

    // Verify no failure email was sent (check for new activities)
    $postCancellationActivities = Activity::get(FALSE)
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('source_record_id', '=', $recur1['id'])
      ->execute();

    $postCancellationActivitiesCount = $postCancellationActivities->count();

    // No new failure email activities should be created
    $this->assertEquals($preCancellationActivitiesCount, $postCancellationActivitiesCount, 'No failure email should be sent when contact has another active recurring contribution');

    // clean up email
    Email::delete(FALSE)
      ->addWhere('id', '=', $recur1['contact_id'])
      ->execute();

    // clean up payment token
    \Civi\Api4\PaymentToken::delete(FALSE)
      ->addWhere('id', '=', $paymentToken['id'])
      ->execute();
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
