<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\WMFQueue\DonationQueueConsumer;
use SmashPig\Core\DataStores\DamagedDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\DataStores\QueueWrapper;

/**
 * @group Pipeline
 * @group DonationQueue
 * @group Queue2Civicrm
 */
class DonationQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var PendingDatabase
   */
  protected $pendingDb;

  /**
   * @var DamagedDatabase
   */
  protected $damagedDb;

  /**
   * @var DonationQueueConsumer
   */
  protected $queueConsumer;

  public function setUp(): void {
    parent::setUp();
    $this->pendingDb = PendingDatabase::get();
    $this->damagedDb = DamagedDatabase::get();
    $this->queueConsumer = new DonationQueueConsumer('test');
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testDuplicateHandling() {
    $message = new TransactionMessage();
    $message2 = new TransactionMessage(
      [
        'contribution_tracking_id' => $message->get('contribution_tracking_id'),
        'order_id' => $message->get('order_id'),
        'date' => time(),
      ]
    );

    exchange_rate_cache_set('USD', $message->get('date'), 1);
    exchange_rate_cache_set($message->get('currency'), $message->get('date'), 3);
    exchange_rate_cache_set('USD', $message2->get('date'), 1);
    exchange_rate_cache_set($message2->get('currency'), $message2->get('date'), 3);

    QueueWrapper::getQueue('test')->push($message->getBody());
    QueueWrapper::getQueue('test')->push($message2->getBody());

    $this->queueConsumer->dequeueMessages();

    $contribution = $this->callAPISuccessGetSingle('Contribution', [
      'invoice_id' => $message->get('order_id'),
    ]);
    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    $originalOrderId = $message2->get('order_id');
    $damagedPDO = $this->damagedDb->getDatabase();
    $result = $damagedPDO->query("
			SELECT * FROM damaged
			WHERE gateway = '{$message2->getGateway()}'
			AND order_id = '{$originalOrderId}'");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    $this->assertEquals(1, count($rows),
      'One row stored and retrieved.');
    $expected = [
      // NOTE: This is a db-specific string, sqlite3 in this case, and
      // you'll have different formatting if using any other database.
      'original_date' => wmf_common_date_unix_to_sql($message2->get('date')),
      'gateway' => $message2->getGateway(),
      'order_id' => $originalOrderId,
      'gateway_txn_id' => "{$message2->get('gateway_txn_id')}",
      'original_queue' => 'test',
    ];
    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $rows[0][$key], 'Stored message had expected contents');
    }

    $this->assertNotNull($rows[0]['retry_date'], 'Should retry');
    $storedMessage = json_decode($rows[0]['message'], TRUE);
    $storedInvoiceId = $storedMessage['invoice_id'];
    $storedTags = $storedMessage['contribution_tags'];
    unset($storedMessage['invoice_id']);
    unset($storedMessage['contribution_tags']);
    $this->assertEquals($message2->getBody(), $storedMessage);

    $invoiceIdLen = strlen(strval($originalOrderId));
    $this->assertEquals(
      "$originalOrderId|dup-",
      substr($storedInvoiceId, 0, $invoiceIdLen + 5)
    );
    $this->assertEquals(['DuplicateInvoiceId'], $storedTags);
  }

}
