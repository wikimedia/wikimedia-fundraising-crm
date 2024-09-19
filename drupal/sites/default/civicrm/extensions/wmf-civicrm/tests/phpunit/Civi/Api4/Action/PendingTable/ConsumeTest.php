<?php

namespace Civi\Api4\Action\PendingTable;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingGlobalConfiguration;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 */
class ConsumeTest extends TestCase {

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp(): void {
    parent::setUp();

    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);
  }

  /**
   * The tearDown() method is executed after the test was executed (optional).
   *
   * This can be used for cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
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

    //clean up
    $pending->deleteMessage($message1);
    $pending->deleteMessage($message2);
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

  public function getGateways(): array {
    return [
      ['ingenico'],
      ['paypal_ec'],
    ];
  }

}
