<?php

// Need this to use the traits as Civi otherwise not bootstrapped and
// include path is not yet fixed so otherwise the require_once in that file will fail.
set_include_path(__DIR__ . '/../../../civicrm' . PATH_SEPARATOR . get_include_path());
require_once __DIR__ . '/../../../civicrm/Civi/Test/Api3TestTrait.php';
require_once __DIR__ . '/../../../civicrm/Civi/Test/EntityTrait.php';
require_once __DIR__ . '/../../../../../default/civicrm/extensions/wmf-civicrm/tests/phpunit/Civi/WMFEnvironmentTrait.php';
require_once __DIR__ . '/../../../../../default/civicrm/extensions/wmf-civicrm/tests/phpunit/Civi/WMFQueueTrait.php';

use Civi\Api4\Contribution;
use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFQueue\ContributionTrackingQueueConsumer;
use Civi\WMFQueueTrait;
use SmashPig\Core\SequenceGenerators\Factory;
use Civi\Api4\Contact;
use Civi\WMFException\WMFException;
use Civi\Omnimail\MailFactory;

class BaseWmfDrupalPhpUnitTestCase extends PHPUnit\Framework\TestCase {
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
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function setUp(): void {
    parent::setUp();
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
  }

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

  public function tearDown(): void {
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

}
