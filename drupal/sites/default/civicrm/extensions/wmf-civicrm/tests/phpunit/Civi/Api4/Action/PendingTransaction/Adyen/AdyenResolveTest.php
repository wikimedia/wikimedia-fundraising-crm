<?php

namespace Civi\Api4\Action\PendingTransaction;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use PHPUnit\Framework\TestCase;
use Civi\Api4\PendingTransaction;
use SmashPig\Core\DataStores\PaymentsFraudDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\PaymentDetailResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;
use SmashPig\PaymentProviders\Responses\ApprovePaymentResponse;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentProviders\Responses\CancelPaymentResponse;

/**
 * @group PendingTransactionResolver
 */
class AdyenResolveTest extends TestCase {
  protected $hostedCheckoutProvider;

  protected $contactId;

  public function setUp(): void {
    parent::setUp();

    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);

    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider(
      'adyen', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    // mock HostedCheckoutProvider
    $this->hostedCheckoutProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\Adyen\CardPaymentProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance(
      'payment-provider/cc',
      $this->hostedCheckoutProvider
    );
  }

  /**
   * Reset the pending database
   *
   * @throws \API_Exception
   */
  public function tearDown(): void {
    TestingDatabase::clearStatics();
    if ($this->contactId) {
      Contribution::delete(FALSE)
        ->addWhere('contact_id', '=', $this->contactId)
        ->execute();
      Contact::delete(FALSE)
        ->addWhere('id', '=', $this->contactId)
        ->setUseTrash(FALSE)
        ->execute();
    }
  }

  /**
   * Test scenario where transaction is set to failed from the gateway.
   * Expectation is that the resolve method proceeds to move the transaction to
   * a "cancelled" status.
   *
   */
  public function testResolveOnFailedTransaction(): void {
    $pending_message = $this->createTestPendingRecord('adyen');

    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::FAILED)
      ->setSuccessful(FALSE)
      ->setRiskScores([]);

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // resolve pending trxn
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now failed
    $this->assertEquals(FinalStatus::FAILED, $result[$pending_message['order_id']]['status']);
  }

  /**
   * Test scenario where contribution ID is set and duplicate in Pending
   * Database is in PENDING_POKE status. Expectation is that the transaction
   * would be cancelled when resolved is called on the transaction.
   *
   */
  public function testContributionIdSetAndPendingPokeDuplicateInPendingDatabase(): void {
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestContributionTrackingRecord(
      $pending_message['contribution_tracking_id'],
      ['contribution_id' => mt_rand()]
    );
    $this->createTestPaymentFraudRecordProcess($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );
  
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);
    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // set configured response to mock cancelPayment call
    $cancelledPaymentStatusResponse = new CancelPaymentResponse();
    $cancelledPaymentStatusResponse->setGatewayTxnId($hostedPaymentStatusResponse->getGatewayTxnId())
      ->setStatus(FinalStatus::CANCELLED);

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('cancelPayment')
      ->with($hostedPaymentStatusResponse->getGatewayTxnId())
      ->willReturn($cancelledPaymentStatusResponse);

    // resolve pending trxn
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now cancelled
    $this->assertEquals(FinalStatus::CANCELLED, $result[$pending_message['order_id']]['status']);

    //clean up
    $this->deleteContributionIDRecord($pending_message['contribution_tracking_id']);
  }

  /**
   * Test scenario where contribution ID is set and duplicate in Pending
   * Database is in FAILED status expectation is that the resolver would do
   * nothing to the transaction and the row is deleted afterwards.
   *
   */
  public function testContributionIdSetAndFailedDuplicateInPendingDatabase(): void {
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestContributionTrackingRecord(
      $pending_message['contribution_tracking_id'],
      ['contribution_id' => mt_rand()]
    );
    $this->createTestPaymentFraudRecordProcess($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::FAILED)
      ->setSuccessful(FALSE)
      ->setRiskScores([]);
    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // resolve pending trxn
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now failed
    $this->assertEquals(FinalStatus::FAILED, $result[$pending_message['order_id']]['status']);

    //clean up
    $this->deleteContributionIDRecord($pending_message['contribution_tracking_id']);
  }

  /**
   * Test moving Adyen PendingPoke to Completed path.
   *
   */
  public function testResolveAdyenPendingPokeToComplete(): void {
    // generate a pending message to test
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordProcess($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE);

    // set configured response to mock approvePayment call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn($approvePaymentResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(
      FinalStatus::COMPLETE,
      $result[$pending_message['order_id']]['status']
    );

    // confirm payments-init queue message added
    $payments_init_queue_message = QueueWrapper::getQueue('payments-init')
      ->pop();
    $this->assertNotNull($payments_init_queue_message);

    // confirm donation queue message added
    $donation_queue_message = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donation_queue_message);
    SourceFields::removeFromMessage($donation_queue_message);
    $this->assertEquals([
      'contribution_tracking_id',
      'country',
      'first_name',
      'last_name',
      'email',
      'gateway',
      'order_id',
      'gateway_account',
      'payment_method',
      'payment_submethod',
      'date',
      'gross',
      'currency',
      'gateway_txn_id',
    ], array_keys($donation_queue_message)
    );

    // confirm donation queue message data matches original pending message data
    $this->assertEquals(
      $pending_message['order_id'],
      $donation_queue_message['order_id']
    );
    $this->assertEquals(
      $hostedPaymentStatusResponse->getGatewayTxnId(),
      $donation_queue_message['gateway_txn_id']
    );
  }

  /**
   * Test scenario where contribution ID is set and duplicate in Pending
   * Database is in Completed status expectation is that the resolver would do
   * nothing to the transaction and the row is deleted afterwards.
   *
   */
  public function testContributionIdSetAndCompletedDuplicateInPendingDatabase(): void {
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestContributionTrackingRecord(
      $pending_message['contribution_tracking_id'],
      ['contribution_id' => mt_rand()]
    );
    $this->createTestPaymentFraudRecordProcess($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);
    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // resolve pending trxn
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(FinalStatus::COMPLETE, $result[$pending_message['order_id']]['status']);

    //clean up
    $this->deleteContributionIDRecord($pending_message['contribution_tracking_id']);
  }

  /**
   * Test moving Adyen PendingPoke(600) to Completed(800) path.
   *
   */
  public function testResolveAdyenPendingPokeToCompleteWithFraudScoresInReviewAction(): void {
    // generate a pending message to test
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReview($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    $contact = Contact::create(FALSE)
      ->setValues([
        'first_name' => $pending_message['first_name'],
        'last_name' => $pending_message['last_name'],
      ])->execute()->first();
    $this->contactId = $contact['id'];
    Email::create(FALSE)
      ->setValues([
        'contact_id' => $contact['id'],
        'email' => $pending_message['email'],
      ])
      ->execute();
    Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $contact['id'],
        'total_amount' => '2.34',
        'currency' => 'USD',
        'receive_date' => '2018-06-20',
        'financial_type_id' => 1,
        'contribution_status_id:name' => 'Completed',
      ])
      ->execute();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE);

    // set configured response to mock approvePayment call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn($approvePaymentResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(
      FinalStatus::COMPLETE,
      $result[$pending_message['order_id']]['status']
    );

    // confirm payments-init queue message added
    $payments_init_queue_message = QueueWrapper::getQueue('payments-init')
      ->pop();
    $this->assertNotNull($payments_init_queue_message);

    // confirm donation queue message added
    $donation_queue_message = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donation_queue_message);
    SourceFields::removeFromMessage($donation_queue_message);
    $this->assertEquals([
      'contribution_tracking_id',
      'country',
      'first_name',
      'last_name',
      'email',
      'gateway',
      'order_id',
      'gateway_account',
      'payment_method',
      'payment_submethod',
      'date',
      'gross',
      'currency',
      'gateway_txn_id',
    ], array_keys($donation_queue_message)
    );

    // confirm donation queue message data matches original pending message data
    $this->assertEquals(
      $pending_message['order_id'],
      $donation_queue_message['order_id']
    );
    $this->assertEquals(
      $hostedPaymentStatusResponse->getGatewayTxnId(),
      $donation_queue_message['gateway_txn_id']
    );
  }

  /**
   * Test moving Adyen PendingPoke(600) to Failed path.
   *
   */
  public function testResolveAdyenPendingPokeToFailedWithFraudScoresInReviewAction(): void {
    // generate a pending message to test
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReview($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(
      FinalStatus::FAILED,
      $result[$pending_message['order_id']]['status']
    );
  }

  /**
   * Test moving Adyen PendingPoke(600) to Failed path.
   *
   */
  public function testResolveAdyenPendingPokeToFailedWithFraudScoresInRejectAction(): void {
    // generate a pending message to test
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReject($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // cancelPayment should be called when reject status determined.
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('cancelPayment')
      ->with($hostedPaymentStatusResponse->getGatewayTxnId())
      ->willReturn(
        (new CancelPaymentResponse())->setStatus(
          FinalStatus::CANCELLED
        )
      );
    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(
      FinalStatus::CANCELLED,
      $result[$pending_message['order_id']]['status']
    );
  }

  /**
   * Test moving Adyen PendingPoke(600) to Complete path.
   *
   */
  public function testResolveAdyenPendingPokeToCompleteWithFraudScoresInRejectAction(): void {
    // generate a pending message to test
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReject($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );
    $contact = Contact::create(FALSE)
      ->setValues([
        'first_name' => $pending_message['first_name'],
        'last_name' => $pending_message['last_name'],
      ])->execute()->first();
    $this->contactId = $contact['id'];
    Email::create(FALSE)
      ->setValues([
        'contact_id' => $contact['id'],
        'email' => $pending_message['email'],
      ])
      ->execute();
    Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $contact['id'],
        'total_amount' => '2.34',
        'currency' => 'USD',
        'receive_date' => '2018-06-20',
        'financial_type_id' => 1,
        'contribution_status_id:name' => 'Completed',
      ])
      ->execute();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE);

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // set configured response to mock approvePayment call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn($approvePaymentResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(
      FinalStatus::COMPLETE,
      $result[$pending_message['order_id']]['status']
    );
  }

  public function testResolveCreatesValidPaymentsInitMessage() {
    // generate a pending message to test
    $gateway = 'adyen';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordProcess($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE);

    // set configured response to mock approvePayment call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->with([
        'amount' => 10,
        'currency' => 'GBP',
        'gateway_txn_id' => $hostedPaymentStatusResponse->getGatewayTxnId(),
      ])
      ->willReturn($approvePaymentResponse);

    // run the pending message through PendingTransaction::resolve()
    PendingTransaction::resolve()->setMessage($pending_message)->execute();

    // confirm payments-init queue message added
    $paymentsInitQueueMessage = QueueWrapper::getQueue('payments-init')->pop();
    $this->assertNotNull($paymentsInitQueueMessage);

    $expectedArrayKeys = [
      'validation_action',
      'payments_final_status',
      'payment_method',
      'payment_submethod',
      'country',
      'currency',
      'amount',
      'date',
      'gateway',
      'gateway_txn_id',
      'contribution_tracking_id',
      'order_id',
      'server', // same value as 'source_host' but it's needed by payments-init qc
      // we're not too concerned about the source_* keys in this test but they'll
      // be there anyhow so we should expect them.
      'source_name',
      'source_type',
      'source_host',
      'source_run_id',
      'source_version',
      'source_enqueued_time',
    ];

    // confirm payments-init queue message contains the expected properties.
    // array_diff_key($arr1, $arr2) will compare keys across two arrays and return
    // any keys that exist in $arr1 that don't exist in $arr2. In this case we want
    // the result to be empty to confirm $expectedArrayKeys matches the queue message keys.
    $this->assertEmpty(array_diff_key($paymentsInitQueueMessage, array_flip($expectedArrayKeys)));
  }


  /**
   * @param string $contributionId
   *
   * @return void
   */
  protected function deleteContributionIDRecord($contributionId): void {
    db_delete('contribution_tracking')
      ->condition('id', $contributionId)
      ->execute();
  }

  /**
   * @param $contributionTrackingId
   * @param $order_id
   * @param $gateway
   *
   * @return void
   * @throws \Exception
   */
  protected function createTestPaymentFraudRecordProcess($contributionTrackingId, $order_id, $gateway) {
    $message = [
      'order_id' => $order_id,
      'contribution_tracking_id' => $contributionTrackingId,
      'gateway' => $gateway,
      'payment_method' => 'cc',
      'user_ip' => '127.0.0.1',
      'risk_score' => 50.25,
      'score_breakdown' => [
        'getCVVResult' => 50,
        'minfraud_filter' => 0.25,
      ],
    ];

    PaymentsFraudDatabase::get()->storeMessage($message);
  }

  /**
   * @param $contributionTrackingId
   * @param $order_id
   * @param $gateway
   *
   * @return void
   * @throws \Exception
   */
  protected function createTestPaymentFraudRecordReview($contributionTrackingId, $order_id, $gateway) {
    $message = [
      'order_id' => $order_id,
      'contribution_tracking_id' => $contributionTrackingId,
      'gateway' => $gateway,
      'payment_method' => 'cc',
      'user_ip' => '127.0.0.1',
      'risk_score' => 80.25,
      'score_breakdown' => [
        'getCVVResult' => 80,
        'minfraud_filter' => 0.25,
      ],
    ];

    PaymentsFraudDatabase::get()->storeMessage($message);
  }

  /**
   * @param $contributionTrackingId
   * @param $order_id
   * @param $gateway
   *
   * @return void
   * @throws \Exception
   */
  protected function createTestPaymentFraudRecordReject($contributionTrackingId, $order_id, $gateway) {
    $message = [
      'order_id' => $order_id,
      'contribution_tracking_id' => $contributionTrackingId,
      'gateway' => $gateway,
      'payment_method' => 'cc',
      'user_ip' => '127.0.0.1',
      'risk_score' => 125.25,
      'score_breakdown' => [
        'getCVVResult' => 125,
        'minfraud_filter' => 0.25,
      ],
    ];

    PaymentsFraudDatabase::get()->storeMessage($message);
  }

  /**
   * @param $contributionTrackingId
   * @param array $overrides
   *
   * @return array
   * @throws \Exception
   */
  protected function createTestContributionTrackingRecord($contributionTrackingId, $overrides = []): array {
    $contribution_tracking_message = array_merge([
      'id' => $contributionTrackingId,
      'contribution_id' => NULL,
      'country' => 'US',
      'usd_amount' => 10,
      'note' => 'test',
      'form_amount' => 10,
    ], $overrides);

    db_insert('contribution_tracking')
      ->fields($contribution_tracking_message)
      ->execute();

    return $contribution_tracking_message;
  }

  /**
   * @param string $gateway
   * @param array $additionalKeys
   * @return array
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  protected function createTestPendingRecord($gateway = 'test', $additionalKeys = []): array {
    $id = mt_rand();
    $payment_method = ($gateway == 'paypal_ec') ? 'paypal' : 'cc';
    $payment_submethod = ($gateway == 'paypal_ec') ? '' : 'visa';

    $message = array_merge([
      'contribution_tracking_id' => $id,
      'country' => 'US',
      'first_name' => 'Testy',
      'last_name' => 'McTester',
      'email' => 'test@example.org',
      'gateway' => $gateway,
      'gateway_session_id' => $gateway . "-" . mt_rand(),
      'order_id' => "order-$id",
      'gateway_account' => 'default',
      'payment_method' => $payment_method,
      'payment_submethod' => $payment_submethod,
      'date' => time(),
      'gross' => 10,
      'currency' => 'GBP',
    ], $additionalKeys);

    PendingDatabase::get()->storeMessage($message);
    return $message;
  }
}