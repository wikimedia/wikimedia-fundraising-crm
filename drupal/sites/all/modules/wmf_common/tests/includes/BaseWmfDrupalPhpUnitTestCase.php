<?php

// Need this to use the traits as Civi otherwise not bootstrapped and
// include path is not yet fixed so otherwise the require_once in that file will fail.
set_include_path(__DIR__ . '/../../../civicrm' . PATH_SEPARATOR . get_include_path());
require_once __DIR__ . '/../../../civicrm/Civi/Test/Api3TestTrait.php';

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Test\Api3TestTrait;
use Civi\WMFHelpers\ContributionRecur;
use queue2civicrm\contribution_tracking\ContributionTrackingQueueConsumer;
use SmashPig\Core\Context;
use SmashPig\Core\SequenceGenerators\Factory;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use Civi\Api4\Contact;
use Civi\WMFException\WMFException;
use Civi\Omnimail\MailFactory;

class BaseWmfDrupalPhpUnitTestCase extends PHPUnit\Framework\TestCase {

  use Api3TestTrait;

  protected $startTimestamp;

  /**
   * @var int
   */
  protected $maxContactID;

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
   * @throws \CiviCRM_API3_Exception
   * @throws \Exception
   */
  public function setUp(): void {
    parent::setUp();
    // Since we can't kill jobs on jenkins this prevents a loop from going
    // on for too long....
    set_time_limit(180);

    // Initialize SmashPig with a fake context object
    $config = TestingGlobalConfiguration::create();
    TestingContext::init($config);
    $this->setUpCtSequence();

    if (!defined('DRUPAL_ROOT')) {
      throw new Exception("Define DRUPAL_ROOT somewhere before running unit tests.");
    }

    global $user, $_exchange_rate_cache;
    $_exchange_rate_cache = array();

    $user = new stdClass();
    $user->name = "foo_who";
    $user->uid = "321";
    $user->roles = array(DRUPAL_AUTHENTICATED_RID => 'authenticated user');
    $this->startTimestamp = time();
    civicrm_initialize();
    MailFactory::singleton()->setActiveMailer('test');
    Civi::settings()->set( 'logging_no_trigger_permission', FALSE);
    Civi::settings()->set( 'logging', TRUE);
    $this->maxContactID = $this->getHighestContactID();
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
          if ($entity === 'Contact') {
            $this->cleanUpContact($entityID);
          }
          elseif ($entity === 'PaymentProcessor') {
            $this->cleanupPaymentProcessor($entityID);
          }
          elseif ($entity === 'ContributionTracking') {
            db_delete('contribution_tracking')
              ->condition('id', $entityID)
              ->execute();
            db_delete('contribution_source')
              ->condition('contribution_tracking_id', $entityID)
              ->execute();
            ContributionTracking::delete(FALSE)->addWhere('id', '=', $entityID)->execute();
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
        catch (CiviCRM_API3_Exception $e) {
          // No harm done - it was a best effort cleanup
        }
      }
    }
    db_delete('contribution_source')
      ->condition('contribution_tracking_id', 12345)
      ->execute();

    TestingDatabase::clearStatics();
    Context::set(NULL); // Nullify any SmashPig context for the next run
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
   * @throws \CRM_Core_Exception
   */
  public function createTestContact($params): int {
    $id = (int) $this->callAPISuccess('Contact', 'create', $params)['id'];
    $this->ids['Contact'][$id] = $id;
    return $id;
  }

  /**
   * Create an contact of type Individual.
   *
   * @params array $params
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
   * Temporarily set foreign exchange rates to known values
   *
   * TODO: Should reset after each test.
   */
  protected function setExchangeRates($timestamp, $rates) {
    foreach ($rates as $currency => $rate) {
      exchange_rate_cache_set($currency, $timestamp, $rate);
    }
  }

  /**
   * Create a temporary directory and return the name
   *
   * @return string|boolean directory path if creation was successful, or false
   */
  protected function getTempDir() {
    $tempFile = tempnam(sys_get_temp_dir(), 'wmfDrupalTest_');
    if (file_exists($tempFile)) {
      unlink($tempFile);
    }
    mkdir($tempFile);
    if (is_dir($tempFile)) {
      return $tempFile . '/';
    }
    return FALSE;
  }

  public function cleanUpContact($contactId) {
    $contributions = $this->callAPISuccess('Contribution', 'get', array(
      'contact_id' => $contactId,
    ));
    if (!empty($contributions['values'])) {
      foreach ($contributions['values'] as $id => $details) {
        $this->cleanupContribution($id);
      }
    }
    $this->callAPISuccess('Contact', 'delete', [
      'id' => $contactId,
      'skip_undelete' => TRUE,
    ]);
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
   * Assert custom values are as expected.
   *
   * @param int $contactID
   * @param array $expected
   *   Array in format name => value eg. ['total_2017_2018' => 50]
   *
   * @deprecated uses apiv3 - use assertContactValues()
   */
  protected function assertCustomFieldValues($contactID, $expected) {
    $return = [];
    foreach (array_keys($expected) as $key) {
      $return[] = wmf_civicrm_get_custom_field_name($key);
    }

    $contact = civicrm_api3('contact', 'getsingle', [
      'id' => $contactID,
      'return' => $return,
    ]);

    foreach ($expected as $key => $value) {
      if ($key === 'last_donation_date') {
        // Compare by date only.
        $this->assertEquals($value, substr($contact[wmf_civicrm_get_custom_field_name($key)], 0, 10));
      }
      else {
        $this->assertEquals($value, $contact[wmf_civicrm_get_custom_field_name($key)], "wrong value for $key");
      }
    }
  }

  /**
   * Asset the specified fields match those on the given contact.
   *
   * @param int $contactID
   * @param array $expected
   *
   * @throws \CRM_Core_Exception
   */
  protected function assertContactValues(int $contactID, array $expected) {
    $contact = Contact::get(FALSE)->setSelect(
      array_keys($expected)
    )->addWhere('id', '=', $contactID)->execute()->first();

    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $contact[$key], "wrong value for $key");
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
    $trackingRows = db_query('SELECT id, contribution_id, utm_source FROM contribution_tracking
WHERE contribution_id = :contribution_id', [
      ':contribution_id' => $id,
    ]);
    if ($trackingRows->rowCount() > 0) {
      $row = $trackingRows->fetchAssoc();
      db_delete('contribution_source')
        ->condition('contribution_tracking_id', $row['id'])
        ->execute();
      db_delete('contribution_tracking')
        ->condition('contribution_id', $id)
        ->execute();
    }
    ContributionTracking::delete(FALSE)->addWhere('contribution_id', '=', $id)->execute();
    Contribution::delete(FALSE)->addWhere('id', '=', $id)->execute();
  }

  protected function setUpCtSequence() {
    $ctInitial = db_query('SELECT MAX(id) as maxId from contribution_tracking')->fetchField();
    $generator = Factory::getSequenceGenerator('contribution-tracking');
    $generator->initializeSequence($ctInitial);
  }

  protected function consumeCtQueue() {
    $consumer = new ContributionTrackingQueueConsumer('contribution-tracking');
    $consumer->dequeueMessages();
  }

  protected function addContributionTracking($values = []) {
    $ctId = wmf_civicrm_insert_contribution_tracking($values);
    $this->ids['ContributionTracking'][] = $ctId;
    $this->consumeCtQueue();
    return $ctId;
  }

  /**
   * Import message, tracking the created contact id for cleanup.
   *
   * @param array $msg
   *
   * @return array
   */
  protected function messageImport($msg) : array {
    try {
      $contribution = wmf_civicrm_contribution_message_import($msg);
      $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
      return $contribution;
    }
    catch (WMFException $e) {
      $created = (array) Contact::get(FALSE)->setWhere([
        ['display_name', '=', rtrim($msg['first_name'] . ' ' . $msg['last_name'])],
      ])->setSelect(['id'])->execute()->indexBy('id');
      foreach (array_keys($created) as $contactID) {
        $this->ids['Contact'][$contactID] = $contactID;
      }
      throw $e;
    }
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

}
