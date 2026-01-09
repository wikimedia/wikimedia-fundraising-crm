<?php

namespace phpunit\Civi\Api4\Action\PendingTransaction\Gravy;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Test\EntityTrait;
use PHPUnit\Framework\TestCase;
use Civi\Api4\PendingTransaction;
use SmashPig\Core\DataStores\PaymentsFraudDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\PaymentProviderExtendedResponse;
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
class GravyResolveTest extends TestCase {

  use EntityTrait;

  protected $hostedCheckoutProvider;

  public function setUp(): void {
    parent::setUp();

    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);

    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider(
      'gravy', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    // mock HostedCheckoutProvider
    $this->hostedCheckoutProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\Gravy\CardPaymentProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance(
      'payment-provider/cc',
      $this->hostedCheckoutProvider
    );
  }

  /**
   * Scenario: transaction is set to failed at the gateway.
   * Expectation: the transaction is marked as "cancelled" when resolved.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolveOnFailedTransaction(): void {
    $pending_message = $this->createTestPendingRecord('gravy');

    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
   * Scenario: transaction is set to PendingPoke at the gateway.
   * Expectation: the transaction is marked as COMPLETED when resolved.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolvePendingPokeToComplete(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudDatabaseRecord($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
      ->setGatewayTxnId(mt_rand())
      ->setSuccessful(TRUE)
      ->setBackendProcessor('gravy')
      ->setBackendProcessorTransactionId('ABC-123-XYZ')
      ->setPaymentOrchestratorReconciliationId('abc123zyzzz');

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
      'backend_processor',
      'backend_processor_txn_id',
      'payment_orchestrator_reconciliation_id',
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

    // confirm donation queue message contains gravy backend processor data
    $this->assertEquals('gravy', $donation_queue_message['backend_processor']);
    $this->assertEquals('ABC-123-XYZ', $donation_queue_message['backend_processor_txn_id']);
    $this->assertEquals('abc123zyzzz', $donation_queue_message['payment_orchestrator_reconciliation_id']);
  }

  /**
   * Scenario: contribution was recorded but a duplicate record is in
   * pending database which then returns a PENDING_POKE status from the
   * gateway.
   *
   * Expectation: the duplicate transaction is cancelled.
   *
   * @throws \CRM_Core_Exception
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testContributionIdSetAndPendingPokeDuplicateInPendingDatabase(): void {
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createContributionWithTrackingRecord($pending_message['contribution_tracking_id']);
    $this->createTestPaymentFraudDatabaseRecord($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
  }

  /**
   * Scenario: contribution was recorded but a duplicate record is in
   * pending database with FAILED status.
   *
   * Expectation: Resolver will do nothing and the row is deleted afterwards.
   *
   * @throws \CRM_Core_Exception
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testContributionIdSetAndFailedDuplicateInPendingDatabase(): void {
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createContributionWithTrackingRecord($pending_message['contribution_tracking_id']);
    $this->createTestPaymentFraudDatabaseRecord($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
   * Scenario: contribution was recorded but a duplicate record is in
   * pending database with COMPLETED status.
   *
   * Expectation: Resolver will do nothing to the transaction and the row is
   * deleted afterward.
   *
   * @throws \CRM_Core_Exception
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testContributionIdSetAndCompletedDuplicateInPendingDatabase(): void {
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createContributionWithTrackingRecord($pending_message['contribution_tracking_id']);
    $this->createTestPaymentFraudDatabaseRecord($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
  }

  /**
   * Scenario: transaction is set to PendingPoke at the gateway and
   * ValidationAction::REVIEW
   *
   * Expectation: transaction is marked as COMPLETED when resolved.
   *
   * @throws \CRM_Core_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolvePendingPokeToCompleteWithFraudScoresInReviewAction(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReview($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    $this->createContactWithContribution($pending_message);

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
      ->setGatewayTxnId(mt_rand())
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
   * Scenario: transaction is set to PENDING_POKE at the gateway and
   * ValidationAction::REVIEW
   *
   * Expectation: the transaction is still in PENDING_POKE when resolved.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolvePendingPokeWithFraudScoresInReviewAction(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReview($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
      FinalStatus::PENDING_POKE,
      $result[$pending_message['order_id']]['status']
    );
  }

  /**
   * Scenario: transaction is set to PendingPoke at the gateway and
   * ValidationAction::REJECT
   *
   * Expectation: the transaction is marked as CANCELLED when resolved.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolvePendingPokeToFailedWithFraudScoresInRejectAction(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReject($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
   * Scenario: transaction is set to PendingPoke at the gateway and
   * ValidationAction::PROCESS but donor has recent recurring donation.
   *
   * Expectation: the transaction is marked as resolved since recent recurring donations shouldn't count.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolvePendingPokeToCompleteWithRecentRecurringContribution(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);

    $isRecurring = TRUE;
    $this->createContactWithContribution($pending_message, [
      'receive_date' =>gmdate("Y-m-d", time()),
    ], $isRecurring);

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 0,
        'avs' => 0,
      ]);
    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // cancelPayment should be called when reject status determined.
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('cancelPayment')
      ->with($hostedPaymentStatusResponse->getGatewayTxnId())
      ->willReturn(
        (new CancelPaymentResponse())->setStatus(
          FinalStatus::CANCELLED
        )
      );

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE)
      ->setGatewayTxnId(mt_rand())
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
      'gateway_txn_id'
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
   * Scenario: transaction is set to PendingPoke at the gateway and
   * ValidationAction::PROCESS but donor has recent donation.
   *
   * Expectation: the transaction is cancelled since recent donations is not recurring.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolvePendingPokeToCancelledWithNoRecentRecurringContribution(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);

    $this->createContactWithContribution($pending_message, [
      'receive_date' =>gmdate("Y-m-d", time()),
    ]);

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 0,
        'avs' => 0,
      ]);
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

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE)
      ->setGatewayTxnId(mt_rand())
      ->setSuccessful(TRUE);

    // set configured response to mock approvePayment call
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('approvePayment')
      ->willReturn($approvePaymentResponse);

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
   * Scenario: transaction is set to COMPLETE at the gateway and
   * ValidationAction::REJECT
   *
   * Expectation: the transaction is marked as COMPLETE when resolved.
   * (do we really do this?)
   *
   * @throws \CRM_Core_Exception
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testResolvePendingPokeToCompleteWithFraudScoresInRejectAction(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudRecordReject($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );
    $this->createContactWithContribution($pending_message);

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([]);

    // approvePayment response set up
    $approvePaymentResponse = new ApprovePaymentResponse();
    $approvePaymentResponse->setStatus(FinalStatus::COMPLETE)
      ->setGatewayTxnId(mt_rand())
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

  /**
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\SmashPigException
   * @throws \CRM_Core_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  public function testResolveCreatesValidPaymentsInitMessage(): void {
    // generate a pending message to test
    $gateway = 'gravy';
    $pending_message = $this->createTestPendingRecord($gateway);
    $this->createTestPaymentFraudDatabaseRecord($pending_message['contribution_tracking_id'],
      $pending_message['order_id'],
      $gateway,
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentProviderExtendedResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId($pending_message['gateway_txn_id'])
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
      ->setGatewayTxnId(mt_rand())
      ->setSuccessful(TRUE);

    // set configured response to mock approvePayment call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->with([
        'amount' => 10,
        'currency' => 'GBP',
        'gateway_txn_id' => $hostedPaymentStatusResponse->getGatewayTxnId(),
        'order_id' => $pending_message['order_id'],
        'gateway_session_id' => $pending_message['gateway_session_id'],
        'processor_contact_id' => NULL,
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
      // same value as 'source_host' but it's needed by payments-init qc
      'server',
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
   * @param int $contributionTrackingId
   * @param int $order_id
   * @param string $gateway
   *
   * @return void
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function createTestPaymentFraudDatabaseRecord(int $contributionTrackingId, string $order_id, string $gateway): void {
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
   * @param int $contributionTrackingId
   * @param int $order_id
   * @param string $gateway
   *
   * @return void
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function createTestPaymentFraudRecordReview(int $contributionTrackingId, string $order_id, string $gateway): void {
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
   * @param int $contributionTrackingId
   * @param int $order_id
   * @param string $gateway
   *
   * @return void
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function createTestPaymentFraudRecordReject(int $contributionTrackingId, string $order_id, string $gateway): void {
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
   * @param int $contributionTrackingId
   * @param array $overrides
   *
   * @return array
   */
  protected function createTestContributionTrackingRecord(int $contributionTrackingId, array $overrides = []): array {
    try {
      $contribution_tracking_message = array_merge([
        'id' => $contributionTrackingId,
        'contribution_id' => NULL,
        'country' => 'US',
        'usd_amount' => 10,
        'note' => 'test',
        'form_amount' => 10,
      ], $overrides);
      $record = ContributionTracking::save(FALSE)->setRecords([$contribution_tracking_message])->execute()->first();
      $this->ids['ContributionTracking']['default'] = $record['id'];
      return $contribution_tracking_message;
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('failed to create Contribution Tracking record');
      return [];
    }
  }

  /**
   * @param string $gateway
   * @param array $overrides
   *
   * @return array
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  protected function createTestPendingRecord(string $gateway = 'test', array $overrides = []): array {
    $id = mt_rand();
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
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'date' => time(),
      'gross' => 10,
      'currency' => 'GBP',
      'gateway_txn_id' => mt_rand(),
    ], $overrides);

    PendingDatabase::get()->storeMessage($message);
    return $message;
  }

  /**
   * @param int $contribution_tracking_id
   *
   * @return void
   */
  public function createContributionWithTrackingRecord(int $contribution_tracking_id): void {
    $this->createContactWithContribution();
    $this->createTestContributionTrackingRecord(
      $contribution_tracking_id,
      ['contribution_id' => $this->ids['Contribution']['default']]
    );
  }

  /**
   * @param array $pending_message
   * @param array $contribution_overrides
   * @param bool $initial_contribution_is_recurring flag to signify the contribution record created is a recurring transaction
   *
   * @return void
   */
  public function createContactWithContribution(array $pending_message = [], array $contribution_overrides = [], bool $initial_contribution_is_recurring = false): void {
    $contact = $this->createTestEntity('Contact', [
      'first_name' => $pending_message['first_name'] ?? 'Donald',
      'last_name' => $pending_message['last_name'] ?? 'Duck',
      'email_primary.email' => $pending_message['email'] ?? 'donald@example.com',
    ]);
    $contribution = array_merge([
      'contact_id' => $contact['id'],
      'total_amount' => '2.34',
      'currency' => 'USD',
      'receive_date' => '2018-06-20',
      'financial_type_id' => 1,
      'contribution_status_id:name' => 'Completed',
    ], $contribution_overrides);
    if ($initial_contribution_is_recurring) {
        $contribution_recur = $this->createTestEntity('ContributionRecur', [
          'contact_id' => $contribution['contact_id'],
          'amount' => $contribution['total_amount'],
        ]);
        $contribution['contribution_recur_id'] = $contribution_recur['id'];
    }
    $this->createTestEntity('Contribution', $contribution);
  }

  /**
   * Reset the pending database
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    TestingDatabase::clearStatics();
    Contribution::delete(FALSE)
      ->addWhere('contact_id.first_name', 'IN', ['Testy', 'Donald'])
      ->execute();
    Contact::delete(FALSE)
      ->addWhere('first_name', 'IN', ['Testy', 'Donald'])
      ->setUseTrash(FALSE)
      ->execute();
    if (!empty($this->ids['Contact'])) {
      Contribution::delete(FALSE)
        ->addWhere('contact_id', 'IN', $this->ids['Contact'])
        ->execute();
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contact'])
        ->setUseTrash(FALSE)
        ->execute();
    }
    if (!empty($this->ids['ContributionTracking'])) {
      ContributionTracking::delete(FALSE)->addWhere('id', 'IN', $this->ids['ContributionTracking'])->execute();
    }
  }

}
