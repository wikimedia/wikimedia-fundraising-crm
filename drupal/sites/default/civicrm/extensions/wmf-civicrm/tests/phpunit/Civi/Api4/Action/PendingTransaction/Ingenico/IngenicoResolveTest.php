<?php

namespace Civi\Api4\Action\Ingenico;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Email;
use Civi\Api4\PendingTransaction;
use Civi\Test\EntityTrait;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\DataStores\PaymentsFraudDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentData\DonorDetails;
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
class IngenicoResolveTest extends TestCase {

  use EntityTrait;

  protected $hostedCheckoutProvider;

  protected $contactId;

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
   *
   * @throws \API_Exception
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

  public function testAntiFraudQueueMessageCreatedAfterHostedStatusCallWithNewScores() {
    $gateway = 'ingenico';
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord();
    $this->createTestPaymentFraudRecord($pending_message['contribution_tracking_id'], $pending_message['order_id'], $gateway);

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->execute();

    // confirm payments antifraud queue message added
    $payments_antifraud_queue_message = QueueWrapper::getQueue('payments-antifraud')
      ->pop();
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
   */
  public function testResolvePendingPokeToComplete(): void {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

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
      'email',
      'gateway',
      'order_id',
      'gateway_account',
      'payment_method',
      'payment_submethod',
      'date',
      'gross',
      'currency',
      'full_name',
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
    $this->assertEquals(
      'Testy McTest',
      $donation_queue_message['full_name']
    );
  }

  /**
   * Test that a pending-poke transaction doesn't resolve when we've
   * already processed one for the same email
   */
  public function testResolvePendingPokeWithAlreadyResolved(): void {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // shouldn't call approvePayment
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('approvePayment');

    // set configured response to mock cancelPayment call
    $cancelledPaymentStatusResponse = new CancelPaymentResponse();
    $cancelledPaymentStatusResponse->setGatewayTxnId($hostedPaymentStatusResponse->getGatewayTxnId())
      ->setStatus(FinalStatus::CANCELLED);

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('cancelPayment')
      ->with($hostedPaymentStatusResponse->getGatewayTxnId())
      ->willReturn($cancelledPaymentStatusResponse);

    // run the pending message through PendingTransaction::resolve()
    PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->setAlreadyResolved([
        '1' => [
          'email' => $pending_message['email'],
          'status' => FinalStatus::COMPLETE,
        ],
      ])
      ->execute();

    // confirm donation queue message added
    $donation_queue_message = QueueWrapper::getQueue('donations')->pop();
    $this->assertNull($donation_queue_message);
  }

  /**
   * Test moving PendingPoke(600) to Completed(800) with
   * recurring payment tokens
   */
  public function testResolveRecurringToComplete(): void {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord(
      ['recurring' => 1]
    );

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRecurringPaymentToken('TokenOfMyAffection')
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

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
      'email',
      'gateway',
      'order_id',
      'gateway_account',
      'payment_method',
      'payment_submethod',
      'date',
      'gross',
      'currency',
      'recurring',
      'full_name',
      'gateway_txn_id',
      'recurring_payment_token',
    ], array_keys($donation_queue_message)
    );

    $this->assertEquals(
      $hostedPaymentStatusResponse->getGatewayTxnId(),
      $donation_queue_message['gateway_txn_id']
    );
    $this->assertEquals(
      'TokenOfMyAffection', $donation_queue_message['recurring_payment_token']
    );
    $this->assertEquals(
      1, $donation_queue_message['recurring']
    );
  }

  /**
   * Test scenario where transaction is set to failed from the gateway.
   * Expectation is that the resolve method proceeds to move the transaction to
   * a "cancelled" status.
   *
   */
  public function testResolveOnFailedTransaction(): void {
    $pending_message = $this->createTestPendingRecord();

    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::FAILED)
      ->setSuccessful(FALSE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 50,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

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
    $pending_message = $this->createTestPendingRecord();
    $this->createContributionWithTrackingRecord(
      $pending_message['contribution_tracking_id'],
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );
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
   * Test scenario where contribution ID is set and duplicate in Pending
   * Database is in FAILED status expectation is that the resolver would do
   * nothing to the transaction and the row is deleted afterwards.
   *
   */
  public function testContributionIdSetAndFailedDuplicateInPendingDatabase(): void {
    $pending_message = $this->createTestPendingRecord();
    $this->createTestContributionTrackingRecord(
      $pending_message['contribution_tracking_id'],
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::FAILED)
      ->setSuccessful(FALSE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );
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
   * Database is in Completed status expectation is that the resolver would do
   * nothing to the transaction and the row is deleted afterwards.
   *
   */
  public function testContributionIdSetAndCompletedDuplicateInPendingDatabase(): void {
    $pending_message = $this->createTestPendingRecord();
    $this->createContributionWithTrackingRecord(
      $pending_message['contribution_tracking_id'],
    );
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );
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
   * Test fraud filter rejection path by returning cvv/avs
   * scores that breach the risk score upper threshhold(125).
   *
   */
  public function testResolveRejectToCancelled(): void {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord();

    // getLatestPaymentStatus response set up
    // cvv 100 & avs 100 codes represent a 'no_match' result
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 100,
        'avs' => 100,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

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
    $pending_message = $this->createTestPendingRecord();
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
    $pending_message = $this->createTestPendingRecord();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 0,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

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

  public function testReviewActionMatchesUnrefundedDonor() {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      // Default review threshold is 75
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 50,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

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
        'order_id' => $pending_message['order_id'],
        'gateway_session_id' => $pending_message['gateway_session_id'],
        'processor_contact_id' => NULL,
      ])
      ->willReturn($approvePaymentResponse);

    $contact = Contact::create(FALSE)
      ->setValues([
        'first_name' => 'Testy',
        'last_name' => 'McTest',
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

    // run the pending message through PendingTransaction::resolve()
    PendingTransaction::resolve()->setMessage($pending_message)->execute();

    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    SourceFields::removeFromMessage($donationMessage);
    unset($pending_message['gateway_session_id']);
    $this->assertEquals(array_merge($pending_message, [
      'gateway_txn_id' => $hostedPaymentStatusResponse->getGatewayTxnId(),
      'full_name' => 'Testy McTest',
    ]), $donationMessage);
  }

  /**
   * Don't try to capture a payment when we've already resolved one for the same
   * email address in this same run, as indicated in the alreadyResolved array.
   */
  public function testReviewActionMatchesUnrefundedDonorButAlreadyResolvedThisRun() {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      // Default review threshold is 75
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 50,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // should not approvePayment
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('approvePayment');

    // set configured response to mock cancelPayment call
    $cancelledPaymentStatusResponse = new CancelPaymentResponse();
    $cancelledPaymentStatusResponse->setGatewayTxnId($hostedPaymentStatusResponse->getGatewayTxnId())
      ->setStatus(FinalStatus::CANCELLED);

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('cancelPayment')
      ->with($hostedPaymentStatusResponse->getGatewayTxnId())
      ->willReturn($cancelledPaymentStatusResponse);

    $contact = Contact::create(FALSE)
      ->setValues([
        'first_name' => 'Testy',
        'last_name' => 'McTest',
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

    // run the pending message through PendingTransaction::resolve()
    PendingTransaction::resolve()
      ->setMessage($pending_message)
      ->setAlreadyResolved([
        1 => [
          'email' => $pending_message['email'],
          'status' => FinalStatus::COMPLETE,
        ],
      ])
      ->execute();

    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNull($donationMessage);
  }

  public function testReviewActionMatchesRefundedDonor() {
    // generate a pending message to test
    $pending_message = $this->createTestPendingRecord();

    // getLatestPaymentStatus response set up
    $hostedPaymentStatusResponse = new PaymentDetailResponse();
    $hostedPaymentStatusResponse->setGatewayTxnId(mt_rand() . '-txn')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      // Default review threshold is 75
      ->setRiskScores([
        'cvv' => 50,
        'avs' => 50,
      ])
      ->setDonorDetails(
        (new DonorDetails())->setFullName('Testy McTest')
      );

    // set configured response to mock getLatestPaymentStatus call
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($hostedPaymentStatusResponse);

    // should not approvePayment
    $this->hostedCheckoutProvider->expects($this->never())
      ->method('approvePayment');

    $contact = Contact::create(FALSE)
      ->setValues([
        'first_name' => 'Testy',
        'last_name' => 'McTest',
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
    Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $contact['id'],
        'total_amount' => '2.34',
        'currency' => 'GBP',
        'receive_date' => '2019-02-28',
        'financial_type_id' => 1,
        'contribution_status_id:name' => 'Refunded',
      ])
      ->execute();

    // run the pending message through PendingTransaction::resolve()
    PendingTransaction::resolve()->setMessage($pending_message)->execute();

    // Should not have a donation queue message
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNull($donationMessage);
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
      'risk_score' => 50.25,
      'score_breakdown' => [
        'getCVVResult' => 50,
        'minfraud_filter' => 0.25,
      ],
    ];

    PaymentsFraudDatabase::get()->storeMessage($message);
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
   *
   * @return void
   */
  public function createContactWithContribution(array $pending_message = []): void {
    $contact = $this->createTestEntity('Contact', [
      'first_name' => $pending_message['first_name'] ?? 'Donald',
      'last_name' => $pending_message['last_name'] ?? 'Duck',
      'email_primary.email' => $pending_message['email'] ?? 'donald@example.com',
    ]);
    $this->createTestEntity('Contribution', [
      'contact_id' => $contact['id'],
      'total_amount' => '2.34',
      'currency' => 'USD',
      'receive_date' => '2018-06-20',
      'financial_type_id' => 1,
      'contribution_status_id:name' => 'Completed',
    ]);
  }

  /**
   * @param array $additionalKeys
   *
   * @return array
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  protected function createTestPendingRecord($additionalKeys = []): array {
    $id = mt_rand();

    $message = array_merge([
      'contribution_tracking_id' => $id,
      'country' => 'US',
      'email' => 'test@example.org',
      'gateway' => 'ingenico',
      'gateway_session_id' => 'ingenico-' . mt_rand(),
      'order_id' => "order-$id",
      'gateway_account' => 'default',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'date' => time(),
      'gross' => 10,
      'currency' => 'GBP',
    ], $additionalKeys);

    PendingDatabase::get()->storeMessage($message);
    return $message;
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
    }
  }

}
