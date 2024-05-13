<?php

namespace Civi;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\OptionValue;
use Civi\Api4\PaymentToken;
use Civi\Omnimail\MailFactory;
use SmashPig\Core\Context;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Core\SequenceGenerators\Factory;
use Civi\WMFStatistic\ImportStatsCollector;

trait WMFEnvironmentTrait {

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
    set_time_limit(180);
    MailFactory::singleton()->setActiveMailer('test');
    // Initialize SmashPig with a fake context object
    TestingContext::init(TestingGlobalConfiguration::create());
    if (!file_exists(\Civi::settings()->get('metrics_reporting_prometheus_path'))) {
      \Civi::settings()->set('metrics_reporting_prometheus_path', \CRM_Core_Config::singleton()->configAndLogDir);
    }
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
          if ($item['contribution_id']) {
            $this->cleanupContribution($item['contribution_id']);
          }
        }
        ContributionTracking::delete(FALSE)->addWhere('id', 'IN', $this->ids['ContributionTracking'])->execute();
      }
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
    ImportStatsCollector::tearDown();
    \DonationStatsCollector::tearDown();
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    // Nullify the context for next run.
    Context::set();
    // Remove any function override for time handling (e.g. with \CRM_Utils_Time::setTime()).
    \CRM_Utils_Time::resetTime();
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

  /**
   * Get the number of mailings sent in the test.
   *
   * @return int
   */
  public function getMailingCount(): int {
    return MailFactory::singleton()->getMailer()->countMailings();
  }

  /**
   * Get the content on the sent mailing.
   *
   * @param int $index
   *
   * @return array
   */
  public function getMailing(int $index): array {
    return MailFactory::singleton()->getMailer()->getMailing($index);
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

}
