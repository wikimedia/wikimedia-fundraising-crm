<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\RefundPaymentResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;

/**
 * @group WMFQueue
 */
class UpiDonationsQueueConsumerTest extends BaseQueueTest {

  /**
   * @var PHPUnit_Framework_MockObject_MockObject
   */
  private $hostedPaymentProvider;

  /** @var \SmashPig\PaymentProviders\Responses\RefundPaymentResponse */
  private $refundPaymentResponse;

  public function setUp(): void {
    parent::setUp();
    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);
    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider(
      'dlocal', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    $this->hostedPaymentProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\dlocal\BankTransferPaymentProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance('payment-provider/bt', $this->hostedPaymentProvider);
  }

  public function tearDown(): void {
    $this->deleteTestData();
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    // Nullify the context for next run.
    Context::set();
    parent::tearDown();
  }

  public function testInitialDonation(): void {
    // Test that an initial donation IPN correctly sets up contact,
    // token, and contribution_recur record, and passes message along
    // to donation queue.
    // Initial message has info from pending table plus info from IPN
    $message = $this->loadMessage('upiInitialDonation');
    QueueWrapper::push('upi-donations', $message);
    $processed = (new UpiDonationsQueueConsumer('upi-donations'))->dequeueMessages();
    $this->assertEquals(1, $processed, 'Did not process exactly 1 message');
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationMessage, 'Did not push a donation queue message');
    $this->assertArrayHasKey('contribution_recur_id', $donationMessage);
    $contributionRecurId = $donationMessage['contribution_recur_id'];
    unset($donationMessage['contribution_recur_id']);
    unset($donationMessage['contact_id']);
    SourceFields::removeFromMessage($donationMessage);
    SourceFields::removeFromMessage($message);
    $this->assertEquals($message, $donationMessage, 'Did not push same message to donation queue');
    $recurRecord = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contributionRecurId)
      ->execute()
      ->first();
    $this->assertEquals('2023-04-09 00:00:00', $recurRecord['next_sched_contribution_date']);
    $this->assertEquals('9', $recurRecord['cycle_day']);
    $tokenRecord = PaymentToken::get(FALSE)
      ->addWhere('id', '=', $recurRecord['payment_token_id'])
      ->execute()
      ->first();
    $this->assertEquals($message['recurring_payment_token'], $tokenRecord['token']);
    $this->assertEquals($message['user_ip'], $tokenRecord['ip_address']);
    $contactRecord = Contact::get(FALSE)
      ->addWhere('id', '=', $recurRecord['contact_id'])
      ->execute()
      ->first();
    $this->assertEquals($message['first_name'], $contactRecord['first_name']);
    $this->assertEquals($message['last_name'], $contactRecord['last_name']);
  }

  public function testSuccessfulInstallment(): void {
    // set up test records to link queue message with
    $contact = $this->createTestContactRecord();
    $token = $this->createTestPaymentToken($contact['id']);
    $recur = $this->createTestContributionRecurRecord($contact['id'], $token['id']);

    // Test that a donation IPN with a pre-existing token and recurring
    // record will set the donation message IDs correctly
    $message = $this->loadMessage('upiSubsequentDonation');
    QueueWrapper::push('upi-donations', $message);

    $processed = (new UpiDonationsQueueConsumer('upi-donations'))->dequeueMessages();
    $this->assertEquals(1, $processed, 'Did not process exactly 1 message');
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationMessage, 'Did not push a donation queue message');
    $this->assertEquals($recur['id'], $donationMessage['contribution_recur_id']);
    $this->assertEquals($contact['id'], $donationMessage['contact_id']);
  }

  public function testInstallmentAfterCancelledRecurRecord(): void {
    // set up test records to link queue message with
    $contact = $this->createTestContactRecord();
    $token = $this->createTestPaymentToken($contact['id']);
    $recur = $this->createTestContributionRecurRecord($contact['id'], $token['id']);
    $this->cancelTestContributionRecurRecord($recur['id']);
    // Test that a donation IPN with a pre-existing token and recurring
    // record will set the donation message IDs correctly even if cancelled
    $message = $this->loadMessage('upiSubsequentDonation');

    $this->hostedPaymentProvider->expects($this->once())
      ->method('refundPayment')
      ->with([
        'gross' => $message['gross'],
        'currency' => $message['currency'],
        'gateway_txn_id' => $message['gateway_txn_id'],
      ])
      ->willReturn(
        (new RefundPaymentResponse())
          ->setGatewayTxnId($message['gateway_txn_id'])
          ->setStatus(FinalStatus::REFUNDED)
          ->setSuccessful(TRUE)
      );

    QueueWrapper::push('upi-donations', $message);

    // Process UPI message
    $processed = (new UpiDonationsQueueConsumer('upi-donations'))->dequeueMessages();
    $this->assertEquals(1, $processed, 'Did not process exactly 1 message');

    // Process donation
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationMessage, 'Did not push a donation queue message');
    (new DonationQueueConsumer('test'))->processMessage($donationMessage);

    // Process refund
    $refundMessage = QueueWrapper::getQueue('refund')->pop();
    $this->assertNotNull($refundMessage, 'Did not push a message to refund queue');
    (new RefundQueueConsumer('refund'))->processMessage($refundMessage);

    // confirm donation is refunded
    $contribution = Contribution::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', '%' . $message['gateway_txn_id'])
      ->addSelect('contribution_status_id:name')->execute()->first();
    $this->assertEquals('Refunded', $contribution['contribution_status_id:name']);

    // confirm that the subscription remains cancelled
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);
    $this->assertEquals('Subscription cancelled at gateway', $contributionRecur['cancel_reason']);

    // Confirm no new subscription
    $newContributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', "%" . $message['gateway_txn_id'])
      ->execute()->first();
    $this->assertNull($newContributionRecur, 'It should not create a new contribution recur row');
  }

  public function testInstallmentAfterMultipleDifferentRecurRecord(): void {
    // set up test records to link queue message with
    $messageAmount = 1000;

    // Test that a donation IPN with a pre-existing token and recurring
    // record with the right amount will set the donation message IDs correctly
    $message = $this->loadMessage('upiSubsequentDonation');
    $message['gross'] = $messageAmount;
    $contact = $this->createTestContactRecord();
    $token = $this->createTestPaymentToken($contact['id']);
    $recur1 = $this->createTestContributionRecurRecord($contact['id'], $token['id']);
    $recur2 = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $messageAmount);
    $recur3 = $this->createTestContributionRecurRecord($contact['id'], $token['id']);

    $this->cancelTestContributionRecurRecord($recur3['id']);

    QueueWrapper::push('upi-donations', $message);

    $processed = (new UpiDonationsQueueConsumer('upi-donations'))->dequeueMessages();
    $this->assertEquals(1, $processed, 'Did not process exactly 1 message');
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationMessage, 'Did not push a donation queue message');
    $this->assertEquals($recur2['id'], $donationMessage['contribution_recur_id']);

    // confirm that the subscription remains pending
    $contributionRecur1 = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur1['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    $this->assertEquals('Pending', $contributionRecur1['contribution_status_id:name']);

    // confirm that the subscription remains cancelled
    $contributionRecur3 = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur3['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    $this->assertEquals('Cancelled', $contributionRecur3['contribution_status_id:name']);

    // Confirm no new subscription
    $newContributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', "%" . $message['gateway_txn_id'])
      ->execute()->first();
    $this->assertNull($newContributionRecur, 'It should not create a new contribution recur row');
  }

  public function testInstallmentAfterMultipleSimilarRecurRecord(): void {
    // set up test records to link queue message with
    $messageAmount = 1000;
    $contact = $this->createTestContactRecord();
    $token = $this->createTestPaymentToken($contact['id']);
    // Test that a donation IPN with a pre-existing token and recurring
    // record set to "In Progress" will set the donation message IDs correctly
    $message = $this->loadMessage('upiSubsequentDonation');

    $next_day_date = wmf_common_date_unix_to_civicrm(strtotime('+1 day', $message['date']));
    $recur1 = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $messageAmount, $next_day_date);
    $recur2 = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $messageAmount, $next_day_date);
    $recur3 = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $messageAmount, $next_day_date);

    $this->cancelTestContributionRecurRecord($recur3['id']);
    $this->setContributionRecurRecordInProgress($recur2['id']);

    $message['gross'] = $messageAmount;
    QueueWrapper::push('upi-donations', $message);

    $processed = (new UpiDonationsQueueConsumer('upi-donations'))->dequeueMessages();
    $this->assertEquals(1, $processed, 'Did not process exactly 1 message');
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationMessage, 'Did not push a donation queue message');
    $this->assertEquals($recur2['id'], $donationMessage['contribution_recur_id']);

    // confirm that the subscriptions remain cancelled
    $contributionRecur1 = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur1['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    $contributionRecur3 = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur3['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    $this->assertEquals('Pending', $contributionRecur1['contribution_status_id:name']);
    $this->assertEquals('Cancelled', $contributionRecur3['contribution_status_id:name']);

    // Confirm no new subscription
    $newContributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', "%" . $message['gateway_txn_id'])
      ->execute()->first();
    $this->assertNull($newContributionRecur, 'It should not create a new contribution recur row');
  }

  public function testInstallmentAfterMultipleCancelledRecurRecord(): void {
    // set up test records to link queue message with
    $messageAmount = 1000;
    // Test that a donation IPN with a pre-existing token and recurring
    // record will set the donation message IDs correctly
    $message = $this->loadMessage('upiSubsequentDonation');

    $message['gross'] = $messageAmount;

    $this->hostedPaymentProvider->expects($this->once())
      ->method('refundPayment')
      ->with([
        'gross' => $messageAmount,
        'currency' => $message['currency'],
        'gateway_txn_id' => $message['gateway_txn_id'],
      ])
      ->willReturn(
        (new RefundPaymentResponse())
          ->setGatewayTxnId($message['gateway_txn_id'])
          ->setStatus(FinalStatus::REFUNDED)
          ->setSuccessful(TRUE)
      );

    $contact = $this->createTestContactRecord();
    $token = $this->createTestPaymentToken($contact['id']);

    $recur1 = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $messageAmount);
    $recur2 = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $messageAmount);
    $recur3 = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $messageAmount);

    $this->cancelTestContributionRecurRecord($recur1['id']);
    $this->cancelTestContributionRecurRecord($recur2['id']);
    $this->cancelTestContributionRecurRecord($recur3['id']);

    QueueWrapper::push('upi-donations', $message);

    $processed = (new UpiDonationsQueueConsumer('upi-donations'))->dequeueMessages();
    $this->assertEquals(1, $processed, 'Did not process exactly 1 message');

    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationMessage, 'Did not push a donation queue message');
    $this->assertEquals($recur1['id'], $donationMessage['contribution_recur_id']);
    (new DonationQueueConsumer('test'))->processMessage($donationMessage);

    // Process refund
    $refundMessage = QueueWrapper::getQueue('refund')->pop();
    $this->assertNotNull($refundMessage, 'Did not push a message to refund queue');
    (new RefundQueueConsumer('refund'))->processMessage($refundMessage);

    // confirm donation is refunded
    $contribution = Contribution::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', '%' . $message['gateway_txn_id'])
      ->addSelect('contribution_status_id:name')->execute()->first();
    $this->assertEquals('Refunded', $contribution['contribution_status_id:name']);

    // confirm that the subscription remains cancelled
    $contributionRecur1 = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur1['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    // confirm that the subscription remains cancelled
    $contributionRecur2 = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur2['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    // confirm that the subscription remains cancelled
    $contributionRecur3 = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur3['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    $this->assertEquals('Cancelled', $contributionRecur1['contribution_status_id:name']);
    $this->assertEquals('Cancelled', $contributionRecur2['contribution_status_id:name']);
    $this->assertEquals('Cancelled', $contributionRecur3['contribution_status_id:name']);

    // Confirm no new subscription
    $newContributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', "%" . $message['gateway_txn_id'])
      ->execute()->first();
    $this->assertNull($newContributionRecur, 'It should not create a new contribution recur row');
  }

  /**
   *
   * For dLocal 'Wallet disabled' rejections we need to cancel the recurring
   * subscription. These messages come in first via the Smashpig IPN listener
   * and then end up on the 'upi-donations' queue as it's the closet process
   * with access to the CiviCRM DB.
   *
   */
  public function testRejectionMessage(): void {
    // set up test records to link queue message with
    $contact = $this->createTestContactRecord();
    $token = $this->createTestPaymentToken($contact['id']);

    $rejectionQueueMessage = $this->loadMessage('upiRejectionWalletDisabled');

    $recur = $this->createTestContributionRecurRecord($contact['id'], $token['id'], $rejectionQueueMessage['gross']);

    // push a test rejection queue message to the upi donations queue
    QueueWrapper::push('upi-donations', $rejectionQueueMessage);

    // process the message
    $upiDonationsQueueConsumer = new UpiDonationsQueueConsumer('upi-donations');
    $messageCount = $upiDonationsQueueConsumer->dequeueMessages();

    // confirm that only one message is processed
    $this->assertEquals(1, $messageCount, 'Did not process exactly 1 message');

    // confirm that no donation messages are pushed to the queue for rejections
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNull($donationMessage, 'Nothing should get pushed on the donations queue for rejection messages');

    // confirm that the subscription was cancelled
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addSelect('contribution_status_id:name', 'cancel_reason')->execute()->first();
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);
    $this->assertEquals('Subscription cancelled at gateway', $contributionRecur['cancel_reason']);
  }

  /**
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function deleteTestData(): void {
    $testContact = Contact::get(FALSE)
      ->addWhere('display_name', '=', 'Testy McTest')
      ->setSelect(['id'])
      ->execute()
      ->first();

    if ($testContact) {
      ContributionRecur::delete(FALSE)
        ->addWhere('contact_id', '=', $testContact['id'])
        ->execute();
      Contribution::delete(FALSE)
        ->addWhere('contact_id', '=', $testContact['id'])
        ->execute();
      PaymentToken::delete(FALSE)
        ->addWhere('contact_id', '=', $testContact['id'])
        ->execute();
      Contact::delete(FALSE)
        ->addWhere('id', '=', $testContact['id'])
        ->setUseTrash(FALSE)
        ->execute();
    }
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createTestContactRecord(): ?array {
    return Contact::create(FALSE)
      ->setValues([
        'first_name' => 'Testy',
        'last_name' => 'McTest',
        'contact_type' => 'Individual',
      ])
      ->execute()
      ->first();
  }

  /**
   * @param int $id
   */
  protected function createTestPaymentToken(int $id): ?array {
    return PaymentToken::create(FALSE)
      ->setValues([
        'token' => '5dff49d4-462c-432e-a12a-9f4c997e34c3',
        'contact_id' => $id,
        'payment_processor_id' => wmf_civicrm_get_payment_processor_id('dlocal'),
        'ip_address' => '192.168.1.1',
      ])
      ->execute()
      ->first();
  }

  /**
   * @param int $contactId
   * @param int $paymentTokenId
   */
  protected function createTestContributionRecurRecord(int $contactId, int $paymentTokenId, ?int $amount = 505, ?string $next_sched_date = NULL): ?array {
    $params = [
      'currency' => 'INR',
      'amount' => $amount,
      'cycle_day' => 5,
      'contact_id' => $contactId,
      'payment_token_id' => $paymentTokenId,
    ];
    if ($next_sched_date) {
      $params['next_sched_contribution_date'] = $next_sched_date;
    }
    return ContributionRecur::create(FALSE)
      ->setValues($params)
      ->execute()
      ->first();
  }

  /**
   * @param int $contactId
   * @param int $paymentTokenId
   */
  protected function cancelTestContributionRecurRecord(int $id): ?array {
    $params = [];
    $params['id'] = $id;
    $params['contribution_status_id:name'] = 'Cancelled';
    $params['cancel_date'] = UtcDate::getUtcDatabaseString();
    $params['cancel_reason'] = 'Subscription cancelled at gateway';
    return ContributionRecur::update(FALSE)
      ->setValues($params)
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
  }

  /**
   * @param int $contactId
   * @param int $paymentTokenId
   */
  protected function setContributionRecurRecordInProgress(int $id): ?array {
    $params = [];
    $params['id'] = $id;
    $params['contribution_status_id:name'] = 'In Progress';
    return ContributionRecur::update(FALSE)
      ->setValues($params)
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
  }

}
