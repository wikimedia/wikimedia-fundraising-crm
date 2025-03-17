<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFQueueTrait;

class BaseChecksFileTest extends PHPUnit\Framework\TestCase {
  use WMFEnvironmentTrait;
  use Api3TestTrait;
  use EntityTrait;
  use WMFQueueTrait;

  protected $startTimestamp;

  /**
   * @var int
   */
  protected $trackingCount = 0;

  /**
   * Ids created for test purposes.
   *
   * @var array
   */
  protected $ids = [];

  /**
   * CHeck that contributions that recur have the right financial type.
   *
   * This runs after the test but before tearDown starts.
   *
   * Note this test is useful for finding existing code places where this is not
   * correct. It is probably not worth porting to out extensions when we
   * discontinue this class.
   */
  protected function assertPostConditions(): void {
    $contributions = Contribution::get(FALSE)
      ->addSelect('financial_type_id')
      ->addSelect('contribution_recur_id')
      ->addWhere('contribution_recur_id', 'IS NOT EMPTY')
      ->addWhere('id', '>', $this->maxContributionID)
      ->addOrderBy('receive_date')
      ->execute();
    $recurringRecords = [];
    foreach ($contributions as $contribution) {
      if (empty($recurringRecords[$contribution['contribution_recur_id']])) {
        $this->assertEquals(ContributionRecur::getFinancialTypeForFirstContribution(), $contribution['financial_type_id']);
        $recurringRecords[$contribution['contribution_recur_id']] = TRUE;
      }
      else {
        $this->assertEquals(ContributionRecur::getFinancialTypeForSubsequentContributions(), $contribution['financial_type_id']);
      }
    }
  }

  /**
   * Get the highest contact ID in the database.
   *
   * @return int
   */
  protected function getHighestContactID(): int {
    return (int) $this->callAPISuccessGetValue('Contact', [
      'return' => 'id',
      'is_deleted' => '',
      'options' => ['limit' => 1, 'sort' => 'id DESC'],
    ]);
  }

  /**
   * Create a test contact and store the id to the $ids array.
   *
   * @param array $params
   *
   * @return int
   */
  public function createTestContact($params): int {
    $id = (int) $this->createTestEntity('Contact', $params)['id'];
    $this->ids['Contact'][$id] = $id;
    return $id;
  }

  /**
   * Create an contact of type Individual.
   *
   * @params array $params
   *
   * @return int
   */
  public function createIndividual($params = []): int {
    return $this->createTestContact(array_merge([
      'first_name' => 'Danger',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
    ], $params));
  }

  /**
   * Clean up any payment processor rows
   *
   * @param int $processorID
   */
  public function cleanupPaymentProcessor($processorID) {
    $contributionRecurs = $this->callAPISuccess('ContributionRecur', 'get', [
      'payment_processor_id' => $processorID,
    ])['values'];
    if (!empty($contributionRecurs)) {
      foreach ($contributionRecurs as $id => $details) {
        $contributions = $this->callAPISuccess('Contribution', 'get', [
          'contribution_recur_id' => $id,
        ])['values'];
        if (!empty($contributions)) {
          foreach (array_keys($contributions) as $contributionID) {
            $this->cleanupContribution($contributionID);
          }
        }
        $this->callAPISuccess('ContributionRecur', 'delete', ['id' => $id]);
      }
    }
    $paymentTokens = $this->callAPISuccess('PaymentToken', 'get', [
      'payment_processor_id' => $processorID,
    ])['values'];
    if (!empty($paymentTokens)) {
      foreach ($paymentTokens as $id => $details) {
        $this->callAPISuccess('PaymentToken', 'delete', ['id' => $id]);
      }
    }
    $this->callAPISuccess('PaymentProcessor', 'delete', ['id' => $processorID]);
  }

  /**
   * Gateway.
   *
   * eg. benevity, engage etc.
   *
   * @var string
   */
  protected $gateway = 'generic_import';

  /**
   * Transaction id being worked with. This is combined with the gateway for
   * the civi trxn_id.
   *
   * @var string
   */
  protected $trxn_id;

  protected $epochtime;

  /**
   * ID of the database anonymous contact.
   *
   * This contact can be regarded as site metadata
   * so does not need to be removed afterwards.
   *
   * It is used during imports.
   *
   * @var int
   */
  protected $anonymousContactID;

  public function setUp(): void {
    $this->ensureAnonymousContactExists();
    parent::setUp();
    civicrm_initialize();
    $this->setUpWMFEnvironment();

    if (!defined('DRUPAL_ROOT')) {
      throw new Exception("Define DRUPAL_ROOT somewhere before running unit tests.");
    }

    global $user, $_exchange_rate_cache;
    $_exchange_rate_cache = [];

    $user = new stdClass();
    $user->name = "foo_who";
    $user->uid = "321";
    $user->roles = [DRUPAL_AUTHENTICATED_RID => 'authenticated user'];
    $this->startTimestamp = time();
    civicrm_initialize();
    Civi::settings()->set('logging_no_trigger_permission', FALSE);
    Civi::settings()->set('logging', TRUE);
    $this->trackingCount = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contribution_tracking');
    $this->epochtime = strtotime('2016-09-15');
  }

  /**
   * Test and remove some dynamic fields, to simplify test fixtures.
   */
  protected function stripSourceData(&$msg) {
    $this->assertEquals('direct', $msg['source_type']);
    $importerClass = str_replace('Test', 'Probe', get_class($this));
    $this->assertEquals("Offline importer: {$importerClass}", $msg['source_name']);
    $this->assertNotNull($msg['source_host']);
    $this->assertGreaterThan(0, $msg['source_run_id']);
    $this->assertNotNull($msg['source_version']);
    $this->assertGreaterThan(0, $msg['source_enqueued_time']);

    unset($msg['source_type']);
    unset($msg['source_name']);
    unset($msg['source_host']);
    unset($msg['source_run_id']);
    unset($msg['source_version']);
    unset($msg['source_enqueued_time']);
  }

  /**
   * Clean up after test runs.
   */
  public function tearDown(): void {
    $this->doCleanUp();
    // Employer contact ids are cached in statics.
    unset(\Civi::$statics['wmf_contact']);
    // Clean up generated files
    foreach (['all_missed', 'all_not_matched', 'errors', 'ignored', 'skipped'] as $suffix) {
      $files = glob(__DIR__ . "/../data/*_$suffix.*.csv");
      foreach ($files as $file) {
        unlink($file);
      }
    }

    foreach ($this->ids as $entity => $entityIDs) {
      foreach ($entityIDs as $entityID) {
        try {
          if ($entity === 'PaymentProcessor') {
            $this->cleanupPaymentProcessor($entityID);
          }
          elseif ($entity === 'Contribution') {
            $this->cleanupContribution($entityID);
          }
          else {
            civicrm_api3($entity, 'delete', [
              'id' => $entityID,
            ]);
          }
        }
        catch (CRM_Core_Exception $e) {
          // No harm done - it was a best effort cleanup
        }
      }
    }
    $this->tearDownWMFEnvironment();
    parent::tearDown();
    // Check we cleaned up properly. This check exists to ensure we don't add tests that
    // leave test contacts behind as over time they cause problems in our dev dbs.
    if (!$this->maxContactID == $this->getHighestContactID()) {
      $junkContactDisplayName = $this->callAPISuccessGetValue('Contact', [
        'return' => 'display_name',
        'is_deleted' => '',
        'options' => ['limit' => 1, 'sort' => 'id DESC'],
      ]);
      $this->fail("Test contact left behind with display name $junkContactDisplayName");
    }
    // Another test cleanup check...
    $this->assertEquals($this->trackingCount, CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contribution_tracking'));
    drupal_static_reset('large_donation_get_minimum_threshold');
    drupal_static_reset('large_donation_get_notification_thresholds');
  }

  protected function getCsvDirectory(): string {
    return __DIR__ . '/../../../../../default/civicrm/extensions/wmf-civicrm/tests/data/';
  }

  /**
   * Clean up transactions from previous test runs.
   */
  public function doCleanUp(): void {
    if ($this->trxn_id) {
      ContributionTracking::delete(FALSE)->addWhere('contribution_id.contribution_extra.gateway_txn_id', '=', $this->trxn_id)->execute();
      Contribution::delete(FALSE)
        ->addWhere('contribution_extra.gateway_txn_id', '=', $this->trxn_id)
        ->execute();
    }
    elseif ($this->gateway) {
      $contributions = Contribution::get(FALSE)
        ->addWhere('contact_id', '>', $this->maxContactID)
        ->addWhere('contribution_extra.gateway', '=', $this->gateway)->execute();
      if ($contributions) {
        foreach ($contributions as $contribution) {
          $this->cleanupContribution($contribution['id']);
        }
      }
    }
    $this->doMouseHunt();
  }

  /**
   * Clean up previous runs.
   *
   * Also get rid of the nest.
   */
  protected function doMouseHunt(): void {
    $traditionalMouseNames = [
      'mickey@mouse.com',
      'Mickey Mouse',
      'foo@example.com',
      // This anonymous is created in the wmf_civicrm module,
      // not to be confused with import-specific anonymous
      // who might be understood as site metadata.
      'Anonymous',
      // Ducks are mice too.
      'Daisy Duck',
      // As are paranormal investigators
      'Fox Mulder',
      'Satoshi Nakamoto',
      'fox.mulder.doppelganger@pm.me',
      // It is well known mice that evolved from scientists
      'Charles Darwin',
      'Marie Currie',
    ];
    CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_contact WHERE display_name IN ("'
      . implode('","', $traditionalMouseNames)
      . '")'
    );
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_prevnext_cache');
  }

  /**
   * Make sure we have the anonymous contact - like the live DB.
   */
  protected function ensureAnonymousContactExists() {
    $anonymousParams = array(
      'first_name' => 'Anonymous',
      'last_name' => 'Anonymous',
      'email' => 'fakeemail@wikimedia.org',
      'contact_type' => 'Individual',
    );
    $contacts = $this->callAPISuccess('Contact', 'get', $anonymousParams);
    if ($contacts['count'] == 0) {
      $this->callAPISuccess('Contact', 'create', $anonymousParams);
    }
    $contacts = $this->callAPISuccess('Contact', 'get', $anonymousParams);
    $this->assertEquals(1, $contacts['count']);
    $this->anonymousContactID = $contacts['id'];
  }

}
