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
use PHPUnit\Framework\TestCase;
use SmashPig\Core\DataStores\QueueWrapper;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use SmashPig\Core\DataStores\DamagedDatabase;

class BaseQueue extends TestCase implements HeadlessInterface, TransactionalInterface {

  use Test\EntityTrait;
  use WMFEnvironmentTrait;

  protected string $queueName = '';

  protected string $queueConsumer = '';

  /**
   * Create an contact of type Individual.
   *
   * @param array $params
   * @param string $identifier
   *
   * @return int
   */
  public function createIndividual(array $params = [], string $identifier = 'danger_mouse'): int {
    return $this->createTestEntity('Contact', array_merge([
      'first_name' => 'Danger',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
    ], $params), $identifier)['id'];
  }

  /**
   * Process the given queue.
   *
   * @param string $queueName
   * @param string $queueConsumer
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processQueue(string $queueName, string $queueConsumer): ?array {
    return WMFQueue::consume()
      ->setQueueName($queueName)
      ->setQueueConsumer($queueConsumer)
      ->execute()->first();
  }

  /**
   * @param array $exchangeRates
   * @param array $message
   *
   * @return void
   */
  public function setExchangeRatesForMessage(array $exchangeRates, array $message): void {
    if ($exchangeRates) {
      if (isset($exchangeRates['*']) && !isset($exchangeRates[$message['currency']])) {
        $exchangeRates[$message['currency']] = $exchangeRates['*'];
      }
      unset($exchangeRates['*']);
      $this->setExchangeRates($message['date'], $exchangeRates);
    }
  }

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
   *   Any values to be used instead of the loaded ones.
   * @param array $exchangeRates
   *   Exchange rates to set, defaults to setting USD to 1
   *   and the loaded currency to 3.
   *
   * @return array
   */
  public function getDonationMessage(array $values = [], array $exchangeRates = ['USD' => 1, 'PLN' => 0.5]): array {
    $message = $this->loadMessage('donation');
    $contributionTrackingID = mt_rand();
    $message += [
      'gateway_txn_id' => mt_rand(),
      'order_id' => "$contributionTrackingID.1",
      'contribution_tracking_id' => $contributionTrackingID,
    ];
    $this->setExchangeRatesForMessage($exchangeRates, $message);
    return array_merge($message, $values);
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
   * Process donation, using defaults plus any passed in values.
   *
   * @param array $values
   *
   * @return array
   */
  protected function processDonationMessage(array $values): array {
    $donation_message = $this->getDonationMessage($values);
    $this->processMessage($donation_message, 'Donation', 'test');
    return $donation_message;
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
   * Process the given queue.
   *
   * @param array $message
   * @param string|null $queueConsumer
   * @param string|null $queueName
   *
   * @return array|null
   * @noinspection PhpUnhandledExceptionInspection
   * @noinspection PhpDocMissingThrowsInspection
   */
  public function processMessage(array $message, ?string $queueConsumer = NULL, ?string $queueName = NULL): ?array {
    $queueName = $queueName ?: $this->queueName;
    $queueConsumer = $queueConsumer ?: $this->queueConsumer;
    QueueWrapper::push($queueName, $message);
    return $this->processQueue($queueName, $queueConsumer);
  }

  /**
   * Process the given queue.
   *
   * @param array $message
   * @param string|null $queueConsumer
   *   QueueConsumer if different from property. e.g 'Recurring'
   *   (QueueConsumer is appended in the function.)
   *
   * @noinspection PhpUnhandledExceptionInspection
   * @noinspection PhpDocMissingThrowsInspection
   */
  public function processMessageWithoutQueuing(array $message, ?string $queueConsumer = NULL): void {
    $queueConsumer = $queueConsumer ?: $this->queueConsumer;
    $queueConsumerClass = '\\Civi\\WMFQueue\\' . $queueConsumer . 'QueueConsumer';
    /* @var = \Civi\WMFQueue\QueueConsumer */
    $consumer = new $queueConsumerClass('test');
    $consumer->processMessage($message);
  }

  /**
   * Temporarily set foreign exchange rates to known values
   *
   * TODO: Should reset after each test.
   */
  protected function setExchangeRates(int $timestamp, array $rates): void {
    foreach ($rates as $currency => $rate) {
      exchange_rate_cache_set($currency, $timestamp, $rate);
    }
  }

  /**
   * @param array $donation_message
   *
   * @return array
   */
  public function getContributionForMessage(array $donation_message): array {
    try {
      return Contribution::get(FALSE)
        ->addSelect('*', 'contribution_status_id:name', 'contribution_recur_id.*')
        ->addWhere('contribution_extra.gateway', '=', $donation_message['gateway'])
        ->addWhere('contribution_extra.gateway_txn_id', '=', $donation_message['gateway_txn_id'])
        ->execute()->single();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('contribution lookup failed: ' . $e->getMessage());
    }
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
   * @throws \CRM_Core_Exception
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
   * @throws \CRM_Core_Exception
   */
  protected function getContributionTrackingMessage(array $values = []): array {
    $values += $this->loadMessage('contribution-tracking');
    $maxID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution_tracking');
    $values['id'] = $this->ids['ContributionTracking'][] = ($maxID + 1);
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

}
