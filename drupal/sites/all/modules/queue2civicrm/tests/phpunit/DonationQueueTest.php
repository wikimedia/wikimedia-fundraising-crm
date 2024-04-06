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
   * Process an ordinary (one-time) donation message with an UTF campaign
   * previously disabled.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testDonationWithDisabledUTFCampaignOption() {
    civicrm_initialize();
    $optionValue = uniqid();
    $message = new TransactionMessage(['utm_campaign' => $optionValue]);
    $appealFieldID = $this->createCustomOption('Appeal', $optionValue);

    exchange_rate_cache_set('USD', $message->get('date'), 1);
    exchange_rate_cache_set($message->get('currency'), $message->get('date'), 3);

    $this->queueConsumer->processMessage($message->getBody());

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $message->getGateway(),
      $message->getGatewayTxnId()
    );
    $contribution = civicrm_api3(
      'Contribution',
      'getsingle',
      [
        'id' => $contributions[0]['id'],
        'return' => 'custom_' . $appealFieldID,
      ]
    );
    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    $this->assertEquals($optionValue, $contribution['custom_' . $appealFieldID]);
    $this->deleteCustomOption('Appeal', $optionValue);
  }

  /**
   * Process an ordinary (one-time) donation message with an UTF campaign with
   * a different label.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testDonationWithDifferentLabelUTFCampaignOption() {
    civicrm_initialize();
    $optionValue = uniqid();
    $message = new TransactionMessage(['utm_campaign' => $optionValue]);
    $appealFieldID = $this->createCustomOption('Appeal', $optionValue, uniqid());

    exchange_rate_cache_set('USD', $message->get('date'), 1);
    exchange_rate_cache_set($message->get('currency'), $message->get('date'), 3);

    $this->queueConsumer->processMessage($message->getBody());

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $message->getGateway(),
      $message->getGatewayTxnId()
    );
    $contribution = civicrm_api3(
      'Contribution',
      'getsingle',
      [
        'id' => $contributions[0]['id'],
        'return' => 'custom_' . $appealFieldID,
      ]
    );
    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    $this->assertEquals($optionValue, $contribution['custom_' . $appealFieldID]);
    $values = $this->callAPISuccess('OptionValue', 'get', ['value' => $optionValue]);
    $this->assertEquals(1, $values['count']);
    $this->deleteCustomOption('Appeal', $optionValue);
  }

  /**
   * Create a custom option for the given field.
   *
   * @param string $fieldName
   *
   * @param string $optionValue
   *
   * @param string $label
   *   Optional label (otherwise value is used)
   *
   * @return mixed
   * @throws \CiviCRM_API3_Exception
   */
  public function createCustomOption($fieldName, $optionValue, $label = '') {
    $appealField = civicrm_api3('custom_field', 'getsingle', ['name' => $fieldName]);
    wmf_civicrm_ensure_option_value_exists($appealField['option_group_id'], $optionValue);
    if ($label) {
      // This is a test specific scenario not handled by ensure_option_value_exists.
      $this->callAPISuccess('OptionValue', 'get', [
        'return' => 'id',
        'option_group_id' => $appealField['option_group_id'],
        'value' => $optionValue,
        'api.OptionValue.create' => ['label' => $label],
      ]);
    }
    return $appealField['id'];
  }

  /**
   * Cleanup custom field option after test.
   *
   * @param string $fieldName
   *
   * @param string $optionValue
   *
   * @return mixed
   * @throws \CiviCRM_API3_Exception
   */
  public function deleteCustomOption($fieldName, $optionValue) {
    $appealField = civicrm_api3('custom_field', 'getsingle', ['name' => $fieldName]);
    return $appealField['id'];
  }

  /**
   * Process a donation message with some info from pending db
   *
   * @dataProvider getSparseMessages
   *
   * @param TransactionMessage $message
   * @param array $pendingMessage
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testDonationSparseMessages($message, $pendingMessage) {
    $pendingMessage['order_id'] = $message->get('order_id');
    $this->pendingDb->storeMessage($pendingMessage);
    $appealFieldID = $this->createCustomOption('Appeal', $pendingMessage['utm_campaign']);

    exchange_rate_cache_set('USD', $message->get('date'), 1);
    exchange_rate_cache_set($message->get('currency'), $message->get('date'), 3);

    $this->queueConsumer->processMessage($message->getBody());

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $message->getGateway(),
      $message->getGatewayTxnId()
    );
    $contribution = civicrm_api3(
      'Contribution',
      'getsingle',
      [
        'id' => $contributions[0]['id'],
        'return' => 'custom_' . $appealFieldID,
      ]
    );
    $this->assertEquals($pendingMessage['utm_campaign'], $contribution['custom_' . $appealFieldID]);
    $this->deleteCustomOption('Appeal', $pendingMessage['utm_campaign']);
    $pendingEntry = $this->pendingDb->fetchMessageByGatewayOrderId(
      $message->get('gateway'),
      $pendingMessage['order_id']
    );
    $this->assertNull($pendingEntry, 'Should have deleted pending DB entry');
    civicrm_api3('Contribution', 'delete', ['id' => $contributions[0]['id']]);
    civicrm_api3('Contact', 'delete', ['id' => $contributions[0]['contact_id'], 'skip_undelete' => TRUE]);
  }

  public function getSparseMessages() {
    module_load_include('php', 'queue2civicrm', 'tests/includes/Message');
    return [
      [
        new AmazonDonationMessage(),
        json_decode(
          file_get_contents(__DIR__ . '/../data/pending_amazon.json'),
          TRUE
        ),
      ],
      [
        new DlocalDonationMessage(),
        json_decode(
          file_get_contents(__DIR__ . '/../data/pending_dlocal.json'),
          TRUE
        ),
      ],
    ];
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
