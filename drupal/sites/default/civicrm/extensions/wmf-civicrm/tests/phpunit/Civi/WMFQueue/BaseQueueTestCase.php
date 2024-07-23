<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\WMFQueue;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use Civi\WMFEnvironmentTrait;
use Civi\WMFQueueTrait;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\DataStores\QueueWrapper;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use SmashPig\Core\DataStores\DamagedDatabase;

class BaseQueueTestCase extends TestCase implements HeadlessInterface {

  use Test\EntityTrait;
  use WMFEnvironmentTrait;
  use WMFQueueTrait;

  protected string $queueName = '';

  protected string $queueConsumer = '';

  /**
   * Helper to make getting the contact ID even shorter.
   *
   * @param string $identifier
   *
   * @return int
   */
  protected function getContactID(string $identifier = 'danger_mouse'): int {
    return $this->ids['Contact'][$identifier];
  }

  /**
   * @param string $identifier
   *
   * @return array|null
   */
  protected function getContact(string $identifier = 'danger_mouse'): ?array {
    try {
      return Contact::get(FALSE)->addWhere('id', '=', $this->ids['Contact'][$identifier])
        ->addSelect(
          'is_opt_out',
          'do_not_email',
          'Communication.do_not_solicit',
          'Communication.opt_in',
          'email_primary.email',
        )->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * Load the message from the json file.
   *
   * @param string $name
   *
   * @return mixed
   * @noinspection PhpMultipleClassDeclarationsInspection
   */
  public function loadMessage(string $name) {
    try {
      return json_decode(file_get_contents(__DIR__ . '/data/' . $name . '.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->fail('could not load json:' . $name . ' ' . $e->getMessage());
    }
  }

  /**
   * @param array $values
   * @param array $exchangeRates
   *
   * @return array
   */
  protected function getRecurringSignupMessage(array $values = [], array $exchangeRates = ['USD' => 1, '*' => 2]): array {
    $message = $this->loadMessage('recurring_signup');
    $contributionTrackingID = mt_rand();
    $message += [
      'gateway_txn_id' => mt_rand(),
      'order_id' => "$contributionTrackingID.1",
      'contribution_tracking_id' => $contributionTrackingID,
      'subscr_id' => mt_rand(),
    ];
    $this->setExchangeRatesForMessage($exchangeRates, $message);
    return array_merge($message, $values);
  }

  /**
   * @param array $values
   *
   * @return array
   */
  protected function getRecurringCancelMessage(array $values = []): array {
    $message = $this->loadMessage('recurring_cancel');
    $contributionTrackingID = mt_rand();
    $message += [
      'gateway_txn_id' => mt_rand(),
      'order_id' => "$contributionTrackingID.1",
      'contribution_tracking_id' => $contributionTrackingID,
    ];
    return array_merge($message, $values);
  }

  /**
   * @param array $values
   *
   * @return array
   */
  protected function getRecurringFailedMessage(array $values = []): array {
    $message = $this->loadMessage('recurring_fail');
    $contributionTrackingID = mt_rand();
    $message += [
      'order_id' => "$contributionTrackingID.1",
      'contribution_tracking_id' => $contributionTrackingID,
    ];
    return array_merge($message, $values);
  }

  /**
   * @param array $values
   *
   * @return array
   */
  protected function getRecurringEOTMessage(array $values = []): array {
    $message = $this->loadMessage('recurring_eot');
    $contributionTrackingID = mt_rand();
    $message += [
      'gateway_txn_id' => mt_rand(),
      'order_id' => "$contributionTrackingID.1",
      'contribution_tracking_id' => $contributionTrackingID,
    ];
    return array_merge($message, $values);
  }


  /**
   * @param array $values
   *
   * @return array
   */
  public function getRefundMessage(array $values = []): array {
    $donation_message = $this->getDonationMessage([], []);
    return array_merge($this->loadMessage('refund'),
      [
        'gateway' => $donation_message['gateway'],
        'gateway_parent_id' => $donation_message['gateway_txn_id'],
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message['gross'],
        'gross_currency' => $donation_message['original_currency'],
      ], $values
    );
  }

  /**
   * @param array $overrides
   *
   * @return array
   */
  public function processRecurringPaymentMessage(array $overrides): array {
    $message = $this->getRecurringPaymentMessage($overrides);
    $this->processMessage($message);
    $this->processContributionTrackingQueue();
    return $message;
  }

  /**
   * @param array $donation_message
   *
   * @return void
   */
  public function assertOneContributionExistsForMessage(array $donation_message): void {
    $this->getContributionForMessage($donation_message);
  }

  /**
   * @param array $message
   * @param string $status
   *
   * @return void
   */
  public function assertMessageContributionStatus(array $message, string $status): void {
    $contribution = $this->getContributionForMessage($message);
    $this->assertEquals($status, $contribution['contribution_status_id:name']);
  }

  /**
   * Add a contribution tracking record for the message.
   */
  protected function addContributionTrackingRecord($values = []): int {
    $values = $this->getContributionTrackingMessage($values);
    $this->processMessage($values, 'ContributionTracking', 'contribution-tracking');
    return $values['id'];
  }

  /**
   * @param array $values
   *
   * @return array
   */
  protected function getContributionTrackingMessage(array $values = []): array {
    try {
      $values += $this->loadMessage('contribution-tracking');
      $maxID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution_tracking');
      $values['id'] = $this->ids['ContributionTracking'][] = ($maxID + 1);
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('unexpected failure to get the next contribution tracking ID :' . $e->getMessage());
    }
    return $values;
  }

  /**
   * Create a contribution for a test.
   *
   * This will have the financial type ID of the initial recurring contribution
   * if no override is passed in.
   *
   * @param array $values
   * @param string $identifier
   *
   * @return array
   */
  protected function createContribution(array $values = [], string $identifier = 'danger'): array {
    if (empty($values['contact_id'])) {
      $values['contact_id'] = $this->createIndividual();
    }
    return $this->createTestEntity('Contribution', array_merge([
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
      'total_amount' => 60,
      'receive_date' => 'now',
    ], $values), $identifier);
  }

  /**
   * Create a contribution_recur table row for a test
   *
   * @param array $values
   * @param string $identifier
   *
   * @return array
   */
  protected function createContributionRecur(array $values = [], string $identifier = 'danger'): array {
    if (empty($values['contact_id'])) {
      $values['contact_id'] = $this->createIndividual();
    }
    return $this->createTestEntity('ContributionRecur', array_merge([
      'amount' => 10,
      'frequency_interval' => 'month',
      'cycle_day' => date('d'),
      'start_date' => 'now',
      'is_active' => TRUE,
      'contribution_status_id:name' => 'Pending',
      'trxn_id' => 1234,
    ], $values), $identifier);
  }

  /**
   * Ensure the test payment processor exists.
   */
  protected function createPaymentProcessor($params = ['name' => 'test_gateway']): void {
    $this->createTestEntity('PaymentProcessor', [
      'payment_processor_type_id:name' => 'Dummy',
      'is_default' => 1,
      'is_active' => 1,
    ] + $params, $params['name']);
  }

  /**
   * @param array $values
   *
   * @return array
   */
  public function getRecurringPaymentMessage(array $values = []): array {
    return array_merge($this->loadMessage('recurring_payment'), $values);
  }

  /**
   * Get the recurring subscription relevant to the message.
   *
   * @param array $message
   *
   * @return array|null
   */
  public function getContributionRecurForMessage(array $message): ?array {
    try {
      return ContributionRecur::get(FALSE)
        ->addWhere('trxn_id', '=', $message['subscr_id'])
        ->addSelect('*', 'contribution_status_id:name')
        ->execute()->single();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('contribution recur retrieval failure :' . $e->getMessage());
    }
  }

  /**
   * @param array $message
   *
   * @return array|false
   */
  public function getDamagedRows(array $message) {
    $damagedPDO = DamagedDatabase::get()->getDatabase();
    $result = $damagedPDO->query("
    SELECT * FROM damaged
    WHERE gateway = '{$message['gateway']}'
    AND gateway_txn_id = '{$message['gateway_txn_id']}'");
    return $result->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @param array $message
   *
   * @return void
   */
  public function assertDamagedRowExists(array $message): void {
    $rows = $this->getDamagedRows($message);
    $this->assertCount(1, $rows, 'No rows in damaged db for deadlock');
    $this->assertNotNull($rows[0]['retry_date'], 'Damaged message should have a retry date');
  }

}
