<?php

// Need this to use the traits as Civi otherwise not bootstrapped and
// include path is not yet fixed so otherwise the require_once in that file will fail.
set_include_path(__DIR__ . '/../../../civicrm' . PATH_SEPARATOR . get_include_path());
require_once __DIR__ . '/../../../civicrm/Civi/Test/Api3TestTrait.php';
use queue2civicrm\contribution_tracking\ContributionTrackingQueueConsumer;
use SmashPig\Core\Context;
use SmashPig\Core\SequenceGenerators\Factory;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use Civi\Api4\Contact;

class BaseWmfDrupalPhpUnitTestCase extends PHPUnit\Framework\TestCase {

  use \Civi\Test\Api3TestTrait;

  protected $startTimestamp;

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
  public function setUp() {
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
    Civi::settings()->set( 'logging_no_trigger_permission', FALSE);
    Civi::settings()->set( 'logging', TRUE);
  }

  public function tearDown() {
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
    TestingDatabase::clearStatics();
    Context::set(NULL); // Nullify any SmashPig context for the next run
    parent::tearDown();
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

  public function onNotSuccessfulTest(Throwable $t) {
    if (!defined('PRINT_WATCHDOG_ON_TEST_FAIL') || !PRINT_WATCHDOG_ON_TEST_FAIL) {
      throw $t;
    }
    $output = "\nWatchdog messages:\n";

    // show watchdog messages since the start of this test
    $rsc = db_select('watchdog', 'wd')
      ->condition('timestamp', $this->startTimestamp, '>=')
      ->fields('wd')
      ->orderBy('wid', 'ASC')
      ->execute();

    while ($result = $rsc->fetchAssoc()) {
      if (isset ($result['variables'])) {
        $vars = unserialize($result['variables']);
      }
      else {
        $vars = NULL;
      }
      $message = strip_tags(
        is_array($vars)
          ? strtr($result['message'], $vars)
          : $result['message']
      );
      $output .= "{$result['timestamp']}, lvl {$result['severity']}, {$result['type']}: $message\n";
    }

    if (method_exists($t, 'getMessage')) {
      $accessible = \Wikimedia\TestingAccessWrapper::newFromObject($t);
      $accessible->message = $t->getMessage() . $output;
    }
    else {
      echo $output;
    }

    throw $t;
  }

  /**
   * Clean up a contribution
   *
   * @param int $id
   */
  protected function cleanupContribution($id) {
    $this->callAPISuccess('Contribution', 'delete', [
      'id' => $id,
    ]);

    db_delete('contribution_tracking')
      ->condition('contribution_id', $id)
      ->execute();
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
    catch (WmfException $e) {
      $created = (array) Contact::get(FALSE)->setWhere([
        ['display_name', '=', rtrim($msg['first_name'] . ' ' . $msg['last_name'])],
      ])->setSelect(['id'])->execute()->indexBy('id');
      foreach (array_keys($created) as $contactID) {
        $this->ids['Contact'][$contactID] = $contactID;
      }
      throw $e;
    }
  }

}
