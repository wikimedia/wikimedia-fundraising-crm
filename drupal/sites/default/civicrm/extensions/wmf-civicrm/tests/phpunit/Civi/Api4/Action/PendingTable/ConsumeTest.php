<?php

namespace Civi\Api4\Action\PendingTable;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\DataStores\PaymentsFraudDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\ApprovePaymentResponse;
use SmashPig\PaymentProviders\Responses\CancelPaymentResponse;
use SmashPig\PaymentProviders\Responses\PaymentProviderExtendedResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;

class ConsumeTest extends TestCase {

  private $mockPaymentProvider;

  public function setUp(): void {
    parent::setUp();
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);

    // set up mockPaymentProvider
    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider(
      'adyen', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;
    $this->mockPaymentProvider = $this->createMock(TestPaymentProvider::class);
    $providerConfig->overrideObjectInstance('payment-provider/cc', $this->mockPaymentProvider);
  }

  public function tearDown(): void {
    TestingDatabase::clearStatics();
    Contribution::delete(FALSE)->addWhere('contact_id.display_name', '=', 'Testy McTester')->execute();
    Contact::delete(FALSE)->addWhere('display_name', '=', 'Testy McTester')->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /**
   *
   * @dataProvider getGateways
   *
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testGetOldestPendingMessage(string $gateway): void {
    // setup. $message1 this should be the oldest!
    $message1 = $this->createTestPendingRecord($gateway);
    $message2 = $this->createTestPendingRecord($gateway);

    // get oldest record from pending db
    $pending = PendingDatabase::get();
    $oldest_db_record = $pending->fetchMessageByGatewayOldest($gateway);

    // oldest record should match $message1;
    $this->assertEquals($message1['contribution_tracking_id'], $oldest_db_record['contribution_tracking_id']);

    // clean up
    $pending->deleteMessage($message1);
    $pending->deleteMessage($message2);
  }

  /**
   * @dataProvider getGateways
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  public function testMultipleMatchingEmailAddressesWillBeOnlyCapturedOnce(string $gateway) : void {

    // create two pending records which share the same email address
    $pendingMessages[] = $this->createTestPendingRecord($gateway);
    $pendingMessages[] = $this->createTestPendingRecord($gateway);

    // create two payment fraud messages which will trigger ValidationAction::PROCESS
    foreach ($pendingMessages as $pendingMessage) {
      $this->createTestPaymentFraudRecord(
        $pendingMessage['contribution_tracking_id'],
        $pendingMessage['order_id'],
        $pendingMessage['gateway'],
      );
    }

    // setup stub responses for pending transaction resolver API calls

    // - stub PaymentProviderExtendedResponse
    $paymentDetailResponse = new PaymentProviderExtendedResponse();
    $paymentDetailResponse->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE)
      ->setRiskScores([])
      ->setGatewayTxnId('txn123');

    $this->mockPaymentProvider->expects($this->exactly(2))
      ->method('getLatestPaymentStatus')
      ->willReturn($paymentDetailResponse);

    // - stub approvePayment
    $approvePaymentResult = new ApprovePaymentResponse();
    $approvePaymentResult->setSuccessful(TRUE)
      ->setStatus(FinalStatus::COMPLETE)
      ->setGatewayTxnId('txn123');

    // first payment should be approved
    $this->mockPaymentProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn($approvePaymentResult);

    // - stub cancelPayment
    $cancelPaymentResult = new CancelPaymentResponse();
    $cancelPaymentResult->setSuccessful(TRUE)
      ->setStatus(FinalStatus::CANCELLED)
      ->setGatewayTxnId('txn123');

    // second payment should be cancelled due to duplicate email
    $this->mockPaymentProvider->expects($this->once())
      ->method('cancelPayment')
      ->willReturn($cancelPaymentResult);

    // run the Consume API action (pending transaction resolver)
    $consume = new Consume(__CLASS__, __FUNCTION__);
    $consume->setCheckPermissions(FALSE);
    $consume->setGateway($gateway);
    $consume->setMinimumAge(0);
    $consume->setDebug(TRUE);
    $result = $consume->execute();

    // two transactions should have been resolved
    $this->assertEquals(2, $result[0]['transactions_resolved']);

    // only one should have completed with the email 'test@example.org'
    // the second should have been cancelled
    $this->assertCount(1, $consume->getEmailsWithResolvedTransactions());
    $this->assertEquals('test@example.org', array_keys($consume->getEmailsWithResolvedTransactions())[0]);
  }

  /**
   * @param string $gateway
   *
   * @return array
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
   */
  protected function createTestPendingRecord(string $gateway = 'test'): array {
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
    // Set date to 10 seconds in the past so it's always considered "old"
      'date' => time() - 10,
      'gross' => 10,
      'currency' => 'GBP',
    ];

    PendingDatabase::get()->storeMessage($message);
    return $message;
  }

  /**
   * @param $contributionTrackingId
   * @param $order_id
   * @param $gateway
   *
   * @return void
   * @throws \Exception
   */
  protected function createTestPaymentFraudRecord($contributionTrackingId, $order_id, $gateway) : void {
    $message = [
      'order_id' => $order_id,
      'contribution_tracking_id' => $contributionTrackingId,
      'gateway' => $gateway,
      'payment_method' => 'cc',
      'user_ip' => '127.0.0.1',
      'risk_score' => 50.25,
      'date' => time(),
      'score_breakdown' => [
        'getCVVResult' => 50,
        'minfraud_filter' => 0.25,
      ],
    ];

    PaymentsFraudDatabase::get()->storeMessage($message);
  }

  public function getGateways(): array {
    return [
      ['adyen'],
      ['paypal'],
      ['gravy'],
    ];
  }

}
