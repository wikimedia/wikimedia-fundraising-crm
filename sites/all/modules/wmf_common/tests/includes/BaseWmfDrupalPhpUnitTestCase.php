<?php

// Need this to use the trais as Civi otherwise not bootstrapped.
require_once __DIR__ . '/../../../civicrm/Civi/Test/Api3TestTrait.php';

use SmashPig\Core\Context;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;

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

    // Initialize SmashPig with a fake context object
    $config = TestingGlobalConfiguration::create();
    TestingContext::init($config);

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
    if (!Civi::settings()->get('logging')
      && !CRM_Core_DAO::singleValueQuery("
        SHOW triggers WHERE `Trigger` like 'civicrm_contribution_after_insert'
      ")) {
      civicrm_api3('Setting', 'create', array(
        'logging_no_trigger_permission' => 0,
      ));
      civicrm_api3('Setting', 'create', array('logging' => 1));
    }
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
   */
  public function createTestContact($params) {
    $id = $this->callAPISuccess('Contact', 'create', $params)['id'];
    $this->ids['Contact'][$id] = $id;
    return $id;
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

  /**
   * Emulate a logged in user since certain functions use that.
   * value to store a record in the DB (like activity)
   * CRM-8180
   *
   * @return int
   *   Contact ID of the created user.
   */
  public function imitateAdminUser() {
    $result = $this->callAPISuccess('UFMatch', 'get', array(
      'uf_id' => 1,
      'sequential' => 1,
    ));
    if (empty($result['id'])) {
      $contact = $this->callAPISuccess('Contact', 'create', array(
        'first_name' => 'Super',
        'last_name' => 'Duper',
        'contact_type' => 'Individual',
        'api.UFMatch.create' => array('uf_id' => 1, 'uf_name' => 'Wizard'),
      ));
      $contactID = $contact['id'];
    }
    else {
      $contactID = $result['values'][0]['contact_id'];
    }
    $session = CRM_Core_Session::singleton();
    $session->set('userID', $contactID);
    CRM_Core_Config::singleton()->userPermissionClass = new CRM_Core_Permission_UnitTests();
    CRM_Core_Config::singleton()->userPermissionClass->permissions = array(
      'edit all contacts',
      'Access CiviCRM',
      'Administer CiviCRM',
    );
    return $contactID;
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
    if (!PRINT_WATCHDOG_ON_TEST_FAIL) {
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

}
