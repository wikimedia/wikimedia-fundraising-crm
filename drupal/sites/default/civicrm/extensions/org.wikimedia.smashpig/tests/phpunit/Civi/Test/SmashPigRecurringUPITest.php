<?php

namespace Civi\Test;

use Civi;
use CRM_Core_PseudoConstant;
use DateTime;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\CreatePaymentResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;

/**
 * Test recurring UPI payments.
 *
 * @group SmashPig
 * @group headless
 */
class SmashPigRecurringUPITest extends SmashPigBaseTestClass {

  /**
   * @var \SmashPig\PaymentProviders\Responses\CreatePaymentResponse
   */
  private $createPaymentResponse;

  /**
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  private $hostedCheckoutProvider;

  public function setUp() : void {
    $this->processorName = 'test-dlocal';
    parent::setUp();

    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '1'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );

    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);
    $ctx = TestingContext::get();

    $this->createPaymentResponse = (new CreatePaymentResponse())
      ->setGatewayTxnId('F-413092-b7c1e730-67dc-490b-a340-249b4ba731b8')
      ->setStatus(FinalStatus::PENDING)
      ->setSuccessful(TRUE);

    $providerConfig = TestingProviderConfiguration::createForProvider(
      'dlocal', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    $this->hostedCheckoutProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\dlocal\HostedPaymentProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance('payment-provider/bt', $this->hostedCheckoutProvider);
  }

  public function testPreNotificationRequest() : void {

    // set up recurring contribution
    $expectedOrderId = $this->generateRandomOrderId();
    $sequenceId = random_int(1, 20);
    $expectedOrderIdWithSequence = $expectedOrderId . '.' . $sequenceId;
    $expectedNextOrderIdWithSequence = $expectedOrderId . '.' . ($sequenceId + 1);

    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token, [
      'trxn_id' => 'RECURRING DLOCAL ' . $this->generateRandomOrderId(),
      'invoice_id' => $expectedOrderIdWithSequence,
      'contribution_recur_smashpig.processor_contact_id' => '123456.1',
      'contribution_recur_smashpig.rescue_reference' => NULL
    ]);
    $this->createContribution($contributionRecur, [
      'payment_instrument_id:name' => 'Bank Transfer: UPI',
      'invoice_id' => $expectedOrderIdWithSequence . '|recur-' . $this->generateRandomOrderId(),
    ]);

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => '12.34',
        'currency' => 'USD',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'country' => 'US',
        'order_id' => $expectedNextOrderIdWithSequence,
        'installment' => 'recurring',
        'description' => Civi::settings()->get('smashpig_recurring_charge_descriptor'),
        'recurring' => TRUE,
        'user_ip' => '12.34.56.78',
        'payment_submethod' => 'upi',
        'processor_contact_id' => '123456.1',
        'fiscal_number' => $contact['legal_identifier'],
      ])
      ->willReturn(
        $this->createPaymentResponse
      );

    // approve payment should not be called for UPI
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('approvePayment');

    $this->callAPISuccess('Job', 'process_smashpig_recurring', []);

    // there should be no donation messages pushed to the queue for UPI
    $queue = QueueWrapper::getQueue('donations');
    $this->assertEquals(NULL, $queue->pop());
  }

  public function testPostPreNotificationScheduledStatusSetToInProgress() : void {
    // set up recurring contribution
    $expectedOrderId = $this->generateRandomOrderId();
    $sequenceId = random_int(1, 20);
    $expectedOrderIdWithSequence = $expectedOrderId . '.' . $sequenceId;

    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token, [
      'trxn_id' => 'RECURRING DLOCAL ' . $this->generateRandomOrderId(),
      'invoice_id' => $expectedOrderIdWithSequence,
    ]);
    $this->createContribution($contributionRecur, [
      'payment_instrument_id:name' => 'Bank Transfer: UPI',
      'invoice_id' => $expectedOrderIdWithSequence . '|recur-' . $this->generateRandomOrderId(),
    ]);

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn($this->createPaymentResponse);

    // approve payment should not be called for UPI
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('approvePayment');

    $this->callAPISuccess('Job', 'process_smashpig_recurring', []);

    $contributionRecurRecord = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);

    // check the recurring record is now set as In Progress
    $this->assertEquals('In Progress',
      CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur',
        'contribution_status_id',
        $contributionRecurRecord['contribution_status_id']));
  }

  public function testPostPreNotificationScheduledDateAMonthAhead() : void {
    // set up recurring contribution
    $expectedOrderId = $this->generateRandomOrderId();
    $sequenceId = random_int(1, 20);
    $expectedOrderIdWithSequence = $expectedOrderId . '.' . $sequenceId;

    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $initialContributionRecur = $this->createContributionRecur($token, [
      'trxn_id' => 'RECURRING DLOCAL ' . $this->generateRandomOrderId(),
      'invoice_id' => $expectedOrderIdWithSequence,
    ]);
    $this->createContribution($initialContributionRecur, [
      'payment_instrument_id:name' => 'Bank Transfer: UPI',
      'invoice_id' => $expectedOrderIdWithSequence . '|recur-' . $this->generateRandomOrderId(),
    ]);

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn($this->createPaymentResponse);

    // approve payment should not be called for UPI
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('approvePayment');

    $this->callAPISuccess('Job', 'process_smashpig_recurring', []);

    $latestContributionRecurRecord = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $initialContributionRecur['id'],
    ]);

    $differenceInDays = date_diff(
      new DateTime($latestContributionRecurRecord['next_sched_contribution_date']),
      new DateTime($initialContributionRecur['next_sched_contribution_date'])
    )->days;

    // check the latest next_sched_contribution_date is at least 28 days ahead
    $this->assertGreaterThanOrEqual(27, $differenceInDays);
  }

  /**
   * Always include 12345 as that will be cleaned up.
   *
   * (It will cleanup from previous killed runs too - which
   * is an advantage over just tracking what we created).
   *
   * @return int
   * @throws \Exception
   */
  private function generateRandomOrderId() : int {
    return 12345 . random_int(1E+4, 1E+7);
  }

}
