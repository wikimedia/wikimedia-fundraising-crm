<?php

namespace Civi;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\OptionValue;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use Civi\Api4\TransactionLog;
use Civi\MonoLog\MonologManager;
use Civi\Omnimail\MailFactory;
use Civi\WMFStatistic\DonationStatsCollector;
use Civi\WMFStatistic\ImportStatsCollector;
use SmashPig\Core\Context;
use SmashPig\Core\SequenceGenerators\Factory;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;

trait WMFEnvironmentTrait {


  /**
   * @var int
   */
  protected $maxContactID;

  /**
   * @var int
   */
  protected int $maxContributionID;

  /**
   * @var array
   */
  private array $originalSettings = [];

  /**
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    $this->setUpWMFEnvironment();
    parent::setUp();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    $this->tearDownWMFEnvironment();
    parent::tearDown();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function setUpWMFEnvironment(): void {
    // Since we can't kill jobs on jenkins this prevents a loop from going
    // on for too long....
    set_time_limit(210);
    MailFactory::singleton()->setActiveMailer('test');
    // Initialize SmashPig with a fake context object
    TestingContext::init(TestingGlobalConfiguration::create());
    if (!file_exists(\Civi::settings()->get('metrics_reporting_prometheus_path'))) {
      \Civi::settings()->set('metrics_reporting_prometheus_path', \CRM_Core_Config::singleton()->configAndLogDir);
    }
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'abc123');
    }
    $this->maxContactID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contact');
    $this->maxContributionID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution');
    $this->initializeSequenceGenerator();
  }

  /**
   * Do generic cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  protected function tearDownWMFEnvironment() : void {
    if (!empty($this->ids['ContributionTracking'])) {
      $contributionTracking = (array) ContributionTracking::get(FALSE)->addWhere('id', 'IN', $this->ids['ContributionTracking'])->execute()->indexBy('id');
      if (!empty($contributionTracking)) {
        foreach ($contributionTracking as $item) {
          TransactionLog::delete(FALSE)
            ->addWhere('message', 'LIKE', "%contribution_tracking_id:" . $item['id'] . '%')
            ->execute();
          if ($item['contribution_id']) {
            $this->cleanupContribution($item['contribution_id']);
          }
        }
        ContributionTracking::delete(FALSE)->addWhere('id', 'IN', $this->ids['ContributionTracking'])->execute();
      }
    }
    if (!empty($this->ids['TransactionLog'])) {
      TransactionLog::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['TransactionLog'])
        ->execute();
    }
    if (!empty($this->ids['Contribution'])) {
      Contribution::delete(FALSE)->addWhere('id', 'IN', $this->ids['Contribution'])->execute();
    }
    foreach ($this->ids['Contact'] ?? [] as $id) {
      $this->cleanupContact(['id' => $id]);
    }
    if (!empty($this->ids['OptionValue'])) {
      OptionValue::delete(FALSE)->addWhere('id', 'IN', $this->ids['OptionValue'])->execute();
    }
    OptionValue::delete(FALSE)->addWhere('value', '=', 'made-up-option-value')->execute();
    $this->cleanupContact(['last_name' => 'McTest']);
    $this->cleanupContact(['last_name' => 'Mouse']);
    $this->cleanupContact(['email_primary.email' => 'mouse@wikimedia.org']);
    $this->cleanupContact(['last_name' => 'Russ']);
    $this->cleanupContact(['organization_name' => 'The Firm']);
    $this->cleanUpContact(['display_name' => 'Anonymous']);
    if (!empty($this->ids['PaymentProcessor'])) {
      PaymentProcessor::delete(FALSE)->addWhere('id', 'IN', $this->ids['PaymentProcessor'])->execute();
    }
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
    PaymentProcessor::delete(FALSE)->addWhere('name', '=', 'test_gateway')->execute();
    ImportStatsCollector::tearDown();
    DonationStatsCollector::tearDown();
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    // Nullify the context for next run.
    Context::set();
    // Remove any function override for time handling (e.g. with \CRM_Utils_Time::setTime()).
    \CRM_Utils_Time::resetTime();
    foreach ($this->originalSettings as $key => $value) {
      \Civi::settings()->set($key, $value);
    }
  }

  /**
   * Set a CiviCRM setting, storing the original value for tearDown.
   *
   * @param string $name
   * @param mixed $value
   */
  protected function setSetting(string $name, mixed $value): void {
    $this->originalSettings[$name] = \Civi::settings()->get($name);
    \Civi::settings()->set($name, $value);
  }

  /**
   * @return \Monolog\Handler\TestHandler
   */
  public function getLogger(): \Monolog\Handler\TestHandler {
    return MonologManager::testHandlerSingleton();
  }

  public function assertLoggedCriticalThatContains($contains): void {
    $result = $this->getLogger()->hasCriticalThatContains($contains);
    $this->assertTrue($result, $contains . ' not in ' . $this->getLoggerRecordsAsString());
  }

  public function assertLoggedAlertThatContains($contains): void {
    $result = $this->getLogger()->hasAlertThatContains($contains);
    $this->assertTrue($result, $contains . ' not in ' . $this->getLoggerRecordsAsString());
  }

  public function assertLoggedErrorThatContains($contains): void {
    $result = $this->getLogger()->hasErrorThatContains($contains);
    $this->assertTrue($result, $contains . ' not in ' . $this->getLoggerRecordsAsString());
  }

  public function assertLoggedWarningThatContains($contains): void {
    $result = $this->getLogger()->hasWarningThatContains($contains);
    $this->assertTrue($result, $contains . ' not in ' . $this->getLoggerRecordsAsString());
  }

  public function assertLoggedNoticeThatContains($contains): void {
    $result = $this->getLogger()->hasNoticeThatContains($contains);
    $this->assertTrue($result, $contains . ' not in ' . $this->getLoggerRecordsAsString());
  }

  public function assertLoggedInfoThatContains($contains): void {
    $result = $this->getLogger()->hasInfoThatContains($contains);
    $this->assertTrue($result, $contains . ' not in ' . $this->getLoggerRecordsAsString());
  }

  public function assertLoggedDebugThatContains($contains): void {
    $result = $this->getLogger()->hasDebugThatContains($contains);
    $this->assertTrue($result, $contains . ' not in ' . $this->getLoggerRecordsAsString());
  }

  public function getLoggerRecordsAsString(): string {
    return print_r($this->getLogger()->getRecords(), TRUE);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function initializeSequenceGenerator(): void {
    $highestContributionTrackingID = \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) as maxId from civicrm_contribution_tracking');
    $generator = Factory::getSequenceGenerator('contribution-tracking');
    $generator->initializeSequence($highestContributionTrackingID);
  }

  protected function cleanupContact(array $contact): void {
    try {
      $where = $contributionTrackingWhere = $contactWhere = [];
      foreach ($contact as $key => $value) {
        $where[] = ['contact_id.' . $key, '=', $value];
        $contributionTrackingWhere[] = ['contribution_id.contact_id.' . $key, '=', $value];
        $contactWhere[] = [$key, '=', $value];
        $contactWhere[] = ['is_deleted', 'IN', [TRUE, FALSE]];
      }
      ContributionTracking::delete(FALSE)
        ->setWhere($contributionTrackingWhere)
        ->execute();
      ContributionRecur::delete(FALSE)
        ->setWhere($where)
        ->execute();
      Contribution::delete(FALSE)
        ->setWhere($where)
        ->execute();
      PaymentToken::delete(FALSE)
        ->setWhere($where)
        ->execute();
      Contact::delete(FALSE)->setUseTrash(FALSE)->setWhere($contactWhere)->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('clean up failed ' . $e->getMessage());
    }
  }

  /**
   * Create a contact of type Individual.
   *
   * This contact will be automatically removed after the test
   * by virtue of having a magic name (last_name = 'Mouse').
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
   * Create a contact of type Individual.
   *
   * This contact will be automatically removed after the test
   * by virtue of having a magic name (last_name = 'Mouse').
   *
   * @param array $params
   * @param string $identifier
   *
   * @return int
   */
  public function createOrganization(array $params = [], string $identifier = 'organization'): int {
    return $this->createTestEntity('Contact', array_merge([
      'contact_type' => 'Organization',
      'organization_name' => 'The Firm',
    ], $params), $identifier)['id'];
  }

  /**
   * Clean up a contribution
   *
   * @param int $id
   *
   * @throws \CRM_Core_Exception
   */
  protected function cleanupContribution(int $id): void {
    ContributionTracking::delete(FALSE)->addWhere('contribution_id', '=', $id)->execute();
    Contribution::delete(FALSE)->addWhere('id', '=', $id)->execute();
  }

  protected function createContributionTracking(array $values = [], string $identifier = 'default'): array {
    if (empty($values['id'])) {
      $values['id'] = Factory::getSequenceGenerator('contribution-tracking')->getNext();
    }
    try {
      $contributionTracking = ContributionTracking::save(FALSE)
        ->addRecord($values)
        ->execute()->first();
      $this->ids['ContributionTracking'][$identifier] = $contributionTracking['id'];
      return $contributionTracking;
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('unable to create ContributionTracking record');
    }
  }

  /**
   * Get the number of mailings sent in the test.
   *
   * @return int
   */
  public function getMailingCount(): int {
    return MailFactory::singleton()->getMailer()->count();
  }

  /**
   * Get the content on the sent mailing.
   *
   * @param int $index
   *
   * @return array
   */
  public function getMailing(int $index): array {
    return MailFactory::singleton()->getMailer()->getMailings()[$index];
  }

  /**
   * @param string $currency
   * @param float $rate
   * @param string $dateString
   *
   * @return void
   */
  public function setExchangeRate(string $currency, float $rate, string $dateString = 'now'): void {
    try {
      \CRM_ExchangeRates_BAO_ExchangeRate::addToCache(
        $currency, (new \DateTime($dateString))->format('YmdHis'), $rate
      );
    }
    catch (\Exception $e) {
      $this->fail('Failed to set Exchange rate ' . $e->getMessage());
    }
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

  /**
   * Get the most recent email sent with the CiviCRM mail method.
   *
   * Note this is emails sent using CRM_Utils_Mail, not our WMF specific method.
   *
   * @return array
   */
  protected function getMostRecentEmail(): array {
    return \CRM_Core_DAO::executeQuery('SELECT headers, body FROM civicrm_mailing_spool ORDER BY id DESC LIMIT 1')->fetchAll()[0];
  }

}
