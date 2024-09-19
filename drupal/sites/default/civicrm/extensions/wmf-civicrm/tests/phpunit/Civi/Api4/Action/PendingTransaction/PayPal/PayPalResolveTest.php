<?php

namespace Civi\Api4\Action\PendingTransaction;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\PendingTransaction;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentData\DonorDetails;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\ApprovePaymentResponse;
use SmashPig\PaymentProviders\Responses\CreateRecurringPaymentsProfileResponse;
use SmashPig\PaymentProviders\Responses\PaymentDetailResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;

/**
 * @group PendingTransactionResolver
 */
class PayPalResolveTest extends TestCase {
  protected $contactId;
  /**
   * @var mixed|\PHPUnit\Framework\MockObject\MockObject|SmashPig\PaymentProviders\PayPal\PaymentProvider
   */
  protected $paymentProvider;

  public function setUp(): void {
    parent::setUp();

    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);

    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider(
      'paypal', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    // mock PayPal PaymentProvider
    $this->paymentProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\PayPal\PaymentProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance(
      'payment-provider/paypal',
      $this->paymentProvider
    );
  }

  /**
   * Reset the pending database and contact and contributions in Civi
   *
   * @throws \CRM_Core_Exception
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

  public function testResolveFailure(): void {
    $pendingMessage = $this->createTestPendingMessage();

    // getLatestPaymentStatus response set up
    $paymentStatusResponse = (new PaymentDetailResponse())
      ->setStatus(FinalStatus::TIMEOUT)
      ->setSuccessful(TRUE);

    // set configured response to mock getLatestPaymentStatus call
    $this->paymentProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($paymentStatusResponse);

    $this->paymentProvider->expects($this->never())
      ->method('approvePayment');

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pendingMessage)
      ->execute();

    $this->assertEquals(
      FinalStatus::TIMEOUT,
      $result[$pendingMessage['order_id']]['status']
    );
  }

  public function testResolveSuccess(): void {
    $pendingMessage = $this->createTestPendingMessage();

    // getLatestPaymentStatus response set up
    $paymentStatusResponse = (new PaymentDetailResponse())
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setProcessorContactID(mt_rand())
      ->setDonorDetails((new DonorDetails())
        ->setFirstName('Testy')
        ->setLastName('McTest')
        ->setEmail('testy@example.com')
      )
      ->setSuccessful(TRUE);

    // set configured response to mock getLatestPaymentStatus call
    $this->paymentProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($paymentStatusResponse);

    // approvePayment response set up
    $approvePaymentResponse = (new ApprovePaymentResponse())
      ->setStatus(FinalStatus::COMPLETE)
      ->setGatewayTxnId(mt_rand())
      ->setSuccessful(TRUE);

    // set configured response to mock approvePayment call
    $this->paymentProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn($approvePaymentResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pendingMessage)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(
      FinalStatus::COMPLETE,
      $result[$pendingMessage['order_id']]['status']
    );

    // confirm payments-init queue message added
    $paymentsInitQueueMessage = QueueWrapper::getQueue('payments-init')
      ->pop();
    $this->assertNotNull($paymentsInitQueueMessage);

    // confirm donation queue message added
    $donationQueueMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationQueueMessage);
    SourceFields::removeFromMessage($donationQueueMessage);
    $donationKeys = array_keys($donationQueueMessage);
    sort($donationKeys);
    $this->assertEquals(
      [
        'contribution_tracking_id',
        'country',
        'currency',
        'date',
        'email',
        'first_name',
        'gateway',
        'gateway_account',
        'gateway_txn_id',
        'gross',
        'last_name',
        'order_id',
        'payment_method',
        'processor_contact_id'
      ],
      $donationKeys
    );

    // confirm donation queue message data matches original pending message data
    $this->assertEquals(
      $pendingMessage['order_id'],
      $donationQueueMessage['order_id']
    );
    $this->assertEquals(
      $approvePaymentResponse->getGatewayTxnId(),
      $donationQueueMessage['gateway_txn_id']
    );
    $this->assertEquals('testy@example.com', $donationQueueMessage['email']);
    $this->assertEquals('Testy', $donationQueueMessage['first_name']);
    $this->assertEquals('McTest', $donationQueueMessage['last_name']);
  }

  public function testResolveRecurring(): void {
    $pendingMessage = $this->createTestPendingMessage();
    $pendingMessage['recurring'] = 1;

    // getLatestPaymentStatus response set up
    $paymentStatusResponse = (new PaymentDetailResponse())
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setProcessorContactID(mt_rand())
      ->setDonorDetails((new DonorDetails())
        ->setFirstName('Testy')
        ->setLastName('McTest')
        ->setEmail('testy@example.com')
      )
      ->setSuccessful(TRUE);

    // set configured response to mock getLatestPaymentStatus call
    $this->paymentProvider->expects($this->once())
      ->method('getLatestPaymentStatus')
      ->willReturn($paymentStatusResponse);

    $createProfileResponse = (new CreateRecurringPaymentsProfileResponse())
      ->setStatus(FinalStatus::COMPLETE)
      ->setGatewayTxnId(mt_rand())
      ->setProfileId(mt_rand())
      ->setSuccessful(TRUE);

    $this->paymentProvider->expects($this->once())
      ->method('createRecurringPaymentsProfile')
      ->with([
        'gateway_session_id' => $pendingMessage['gateway_session_id'],
        'description' => \Civi::settings()->get('wmf_resolved_charge_descriptor'),
        'order_id' => $pendingMessage['order_id'],
        'amount' => $pendingMessage['gross'],
        'currency' => $pendingMessage['currency'],
        'email' => 'testy@example.com',
        'date' => $pendingMessage['date'],
      ])
      ->willReturn($createProfileResponse);

    // run the pending message through PendingTransaction::resolve()
    $result = PendingTransaction::resolve()
      ->setMessage($pendingMessage)
      ->execute();

    // confirm status is now complete
    $this->assertEquals(
      FinalStatus::COMPLETE,
      $result[$pendingMessage['order_id']]['status']
    );

    // confirm payments-init queue message added
    $paymentsInitQueueMessage = QueueWrapper::getQueue('payments-init')
      ->pop();
    $this->assertNotNull($paymentsInitQueueMessage);

    // confirm no donation queue message added
    $donationQueueMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNull($donationQueueMessage);

  }

  private function createTestPendingMessage() {
    $id = mt_rand();
    return [
      'contribution_tracking_id' => $id,
      'country' => 'US',
      'gateway' => 'paypal_ec',
      'gateway_session_id' => 'EC-' . mt_rand(),
      'order_id' => "$id.1",
      'gateway_account' => 'default',
      'payment_method' => 'paypal',
      'date' => time(),
      'gross' => 10,
      'currency' => 'GBP',
    ];
  }
}
