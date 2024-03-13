<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Civi\Api4\WMFQueue;
use Civi\Omnimail\MailFactory;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;

class BaseQueue extends TestCase implements HeadlessInterface, TransactionalInterface {

  use Test\EntityTrait;

  protected string $queueName = '';

  protected string $queueConsumer = '';

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

  /**
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    // Since we can't kill jobs on jenkins this prevents a loop from going
    // on for too long....
    set_time_limit(180);
    MailFactory::singleton()->setActiveMailer('test');
    // Initialize SmashPig with a fake context object
    $config = TestingGlobalConfiguration::create();
    TestingContext::init($config);
  }

  public function tearDown(): void {
    $this->cleanupNamedContact(['last_name' => 'McTest']);
    $this->cleanupNamedContact(['last_name' => 'Mouse']);
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    // Nullify the context for next run.
    Context::set();
    parent::tearDown();
  }

  protected function cleanupNamedContact(array $contact): void {
    try {
      $where = [];
      foreach ($contact as $key => $value) {
        $where[] = ['contact_id.' . $key, '=', $value];
      }
      ContributionRecur::delete(FALSE)
        ->setWhere($where)
        ->execute();
      Contribution::delete(FALSE)
        ->setWhere($where)
        ->execute();
      PaymentToken::delete(FALSE)
        ->setWhere($where)
        ->execute();
      $where = [];
      foreach ($contact as $key => $value) {
        $where[] = [$key, '=', $value];
      }
      Contact::delete(FALSE)->setUseTrash(FALSE)->setWhere($where)->execute();
    }
    catch (\CRM_Core_Exception $e) {
      // do not fail in cleanup.
    }
  }

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
   * @param array $values
   *
   * @return array
   */
  protected function getContributionTrackingMessage(array $values = []): array {
    $values += $this->loadMessage('contribution-tracking');
    $maxID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution_tracking');
    $values['id'] = $this->ids['ContributionTracking'][] = $maxID + 1;
    return $values;
  }

}
