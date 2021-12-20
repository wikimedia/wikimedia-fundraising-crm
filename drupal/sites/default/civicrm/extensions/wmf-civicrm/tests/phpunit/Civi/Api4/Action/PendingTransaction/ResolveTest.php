<?php

use Civi\Api4\PendingTransaction;
use SmashPig\Core\DataStores\PaymentsFraudDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\PaymentDetailResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;
use SmashPig\PaymentProviders\ApprovePaymentResponse;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentProviders\CancelPaymentResponse;

/**
 * @group PendingTransactionResolver
 */
class Civi_Api4_Action_PendingTransaction_ResolveTest extends \PHPUnit\Framework\TestCase {

  protected $hostedCheckoutProvider;

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp(): void {
    parent::setUp();

    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);

    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider(
      'ingenico', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    // mock HostedCheckoutProvider
    $this->hostedCheckoutProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\Ingenico\HostedCheckoutProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance(
      'payment-provider/cc',
      $this->hostedCheckoutProvider
    );
  }

  /**
   * Reset the pending database
   */
  public function tearDown(): void {
    TestingDatabase::clearStatics();
  }

  public function testAntiFraudQueueMessageCreatedAfterHostedStatusCallWithNewScores() {
    $gateway = 'ingenico';
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecord($pending_message['contribution_tracking_id'], $pending_message['order_id'], $gateway);

    // getHostedPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ]);

    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm payments antifraud queue message added
    $payments_antifraud_queue_message = QueueWrapper::getQueue('payments-antifraud')->pop();
    $this->assertNotNull($payments_antifraud_queue_message);

    // confirm payments antifraud queue message data matches original pending message data
    $this->assertEquals(
      $payments_antifraud_queue_message['order_id'],
      $pending_message['order_id']
    );

    //clean up
    PendingDatabase::get()->deleteMessage($pending_message);
  }

  /**
   * Test moving PendingPoke(600) to Completed(800) path.
   *
   */
  public function testResolvePendingPokeToComplete(): void {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord('ingenico');

    // getHostedPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ]);

    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE);

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
    $payments_init_queue_message = QueueWrapper::getQueue('payments-init')->pop();
    $this->assertNotNull($payments_init_queue_message);

    // confirm donation queue message added
    $donation_queue_message = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donation_queue_message);

    // confirm donation queue message data matches original pending message data
    $this->assertEquals(
      $donation_queue_message['order_id'],
      $pending_message['order_id']
    );
  }

  /**
   * Test scenario where transaction is set to failed from the gateway.
   * Expectation is that the resolve method proceeds to move the transaction to
   * a "cancelled" status.
   *
   */
  public function testResolveOnFailedTransaction(): void {
    $pending_message = $this->createTestPendingRecord('ingenico');

    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::FAILED)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 50,
      ]);

    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // resolve pending trxn
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now failed
    $this->assertEquals(FinalStatus::FAILED, $result[$pending_message['order_id']]['status']);
  }

  /**
   * Test scenario where contribution ID is set and duplicate in Pending Database is in PENDING_POKE status.
   * Expectation is that the transaction would be cancelled when resolved is called on the transaction.
   *
   */
  public function testContributionIdSetAndPendingPokeDuplicateInPendingDatabase(): void {
    $pending_message = $this->createTestPendingRecord('ingenico');
    $this->createTestContributionTrackingRecord(
      $pending_message['contribution_tracking_id'],
      ['contribution_id' => mt_rand()]
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ]);
    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // set configured response to mock cancelPayment call
    $cancelledPaymentStatusResponse = new CancelPaymentResponse();
    $cancelledPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::CANCELLED);

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('cancelPayment')
      ->with($pending_message['gateway_txn_id'])
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
   * Test scenario where contribution ID is set and duplicate in Pending Database is in FAILED status
   * expectation is that the resolver would do nothing to the transaction and the row is deleted afterwards.
   *
   */
  public function testContributionIdSetAndFailedDuplicateInPendingDatabase(): void {
    $pending_message = $this->createTestPendingRecord('ingenico');
    $this->createTestContributionTrackingRecord(
      $pending_message['contribution_tracking_id'],
      ['contribution_id' => mt_rand()]
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::FAILED)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ]);
    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
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
   * Test scenario where contribution ID is set and duplicate in Pending Database is in Completed status
   * expectation is that the resolver would do nothing to the transaction and the row is deleted afterwards.
   *
   */
  public function testContributionIdSetAndCompletedDuplicateInPendingDatabase(): void {
    $pending_message = $this->createTestPendingRecord('ingenico');
    $this->createTestContributionTrackingRecord(
      $pending_message['contribution_tracking_id'],
      ['contribution_id' => mt_rand()]
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::COMPLETE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ]);
    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
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
   * Test fraud filter rejection path by returning cvv/avs
   * scores that breach the risk score upper threshhold(125).
   *
   */
  public function testResolveRejectToCancelled(): void {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord('ingenico');

    // getHostedPaymentStatus response set up
    // cvv 100 & avs 100 codes represent a 'no_match' result
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setRiskScores([
        'cvv' => 100,
        'avs' => 100,
      ]);

    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // cancelPayment should be called when reject status determined.
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('cancelPayment')
      ->with($pending_message['gateway_txn_id'])
      ->willReturn(
        (new CancelPaymentResponse())->setStatus(
          FinalStatus::CANCELLED
        )
      );

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now cancelled
    $this->assertEquals(
      FinalStatus::CANCELLED,
      $result[$pending_message['order_id']]['status']
    );
  }

  /**
   * Test unset ct_id path. This will result in the message
   * being unresolvable and should return FAILED
   *
   */
  public function testContributionTrackingIdIsNotSet(): void {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord('ingenico');
    // unset contribution_tracking_id
    unset($pending_message['contribution_tracking_id']);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm status is now failed
    $this->assertEquals(
      FinalStatus::FAILED,
      $result[$pending_message['order_id']]['status']
    );
  }

  public function testResolveCreatesValidPaymentsInitMessage() {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord('ingenico');

    // getHostedPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ]);

    // set configured response to mock getHostedPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getHostedPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE);

    // set configured response to mock approvePayment call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
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
    $this->assertEmpty(array_diff_key(array_flip($expectedArrayKeys), $paymentsInitQueueMessage));
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
  protected function createTestPaymentFraudRecord($contributionTrackingId, $order_id, $gateway) {
    $message = [
      'order_id' => $order_id,
      'contribution_tracking_id' => $contributionTrackingId,
      'gateway' => $gateway,
      'payment_method' => 'cc',
      'user_ip' => '127.0.0.1',
      'risk_score' => 80.25,
      'score_breakdown' => array(
            'getCVVResult' => 80,
            'minfraud_filter' => 0.25,
        )
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
   *
   * @return array
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  protected function createTestPendingRecord($gateway = 'test'): array {
    $id = mt_rand();
    $payment_method = ($gateway == 'paypal_ec') ? 'paypal_ec' : 'cc';
    $payment_submethod = ($gateway == 'paypal_ec') ? 'paypal_ec' : 'visa';

    $message = [
      'contribution_tracking_id' => $id,
      'country' => 'US',
      'first_name' => 'Testy',
      'last_name' => 'McTester',
      'email' => 'test@example.org',
      'gateway' => $gateway,
      'gateway_txn_id' => "txn-$id",
      'gateway_session_id' => $gateway . "-" . mt_rand(),
      'order_id' => "order-$id",
      'gateway_account' => 'default',
      'payment_method' => $payment_method,
      'payment_submethod' => $payment_submethod,
      'date' => time(),
      'gross' => 10,
      'currency' => 'GBP',
    ];

    PendingDatabase::get()->storeMessage($message);
    return $message;
  }

}
