<?php

namespace Civi;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\PaymentToken;
use Civi\Omnimail\MailFactory;
use SmashPig\Core\Context;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Core\SequenceGenerators\Factory;

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
    $this->cleanupContact(['last_name' => 'McTest']);
    $this->cleanupContact(['last_name' => 'Mouse']);
    $this->cleanupContact(['last_name' => 'Russ']);
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    // Nullify the context for next run.
    Context::set();
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