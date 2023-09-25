<?php

namespace Civi\Queue;

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;

class UpiDonationsQueueConsumerTest extends TestCase implements HeadlessInterface, TransactionalInterface {

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
  }

  public function tearDown(): void {
    $this->deleteTestData();
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    // Nullify the context for next run.
    Context::set();
    parent::tearDown();
  }

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): Test\CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function testInitialDonation(): void {
    // Test that an initial donation IPN correctly sets up contact,
    // token, and contribution_recur record, and passes message along
    // to donation queue.
    // Initial message has info from pending table plus info from IPN
    $message = json_decode(
      file_get_contents(__DIR__ . '/../../../data/upiInitialDonation.json'), TRUE
    );
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
    $this->assertEquals('2023-04-08 00:00:00', $recurRecord['next_sched_contribution_date']);
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
    $message = json_decode(
      file_get_contents(__DIR__ . '/../../../data/upiSubsequentDonation.json'), TRUE
    );
    QueueWrapper::push('upi-donations', $message);

    $processed = (new UpiDonationsQueueConsumer('upi-donations'))->dequeueMessages();
    $this->assertEquals(1, $processed, 'Did not process exactly 1 message');
    $donationMessage = QueueWrapper::getQueue('donations')->pop();
    $this->assertNotNull($donationMessage, 'Did not push a donation queue message');
    $this->assertEquals($recur['id'], $donationMessage['contribution_recur_id']);
    $this->assertEquals($contact['id'], $donationMessage['contact_id']);
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
    $recur = $this->createTestContributionRecurRecord($contact['id'], $token['id']);

    // push a test rejection queue message to the upi donations queue
    $rejectionQueueMessage = json_decode(
      file_get_contents(__DIR__ . '/../../../data/upiRejectionWalletDisabled.json'), TRUE
    );
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
  private function deleteTestData() : void {
    $testContact = Contact::get(FALSE)
      ->addWhere('display_name', '=', 'Testy McTest')
      ->setSelect(['id'])
      ->execute()
      ->first();

    if ($testContact) {
      ContributionRecur::delete(FALSE)
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
  protected function createTestContributionRecurRecord(int $contactId, int $paymentTokenId): ?array {
    return ContributionRecur::create(FALSE)
      ->setValues([
        'currency' => 'INR',
        'amount' => 505,
        'cycle_day' => 5,
        'contact_id' => $contactId,
        'payment_token_id' => $paymentTokenId,
      ])
      ->execute()
      ->first();
  }

}
