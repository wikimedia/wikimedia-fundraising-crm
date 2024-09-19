<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Contact.Showme API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Contact_BaseTestClass extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  protected $paymentProcessor = [];

  /**
   * Ids created for test purposes.
   *
   * @var array
   */
  protected $ids = [];

  /**
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   * See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp(): void {
    parent::setUp();
    civicrm_initialize();
    CRM_Forgetme_Hook::testSetup();
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown(): void {
    foreach ($this->ids as $entity => $entityIDs) {
      foreach ($entityIDs as $entityID) {
        try {
          if ($entity === 'Contact') {
            $this->cleanUpContact($entityID);
          }
          else {
            civicrm_api3($entity, 'delete', [
              'id' => $entityID,
              'skip_undelete' => TRUE,
            ]);
          }
        }
        catch (CRM_Core_Exception $e) {
          // No harm done - it was a best effort cleanup
        }
      }
    }
    parent::tearDown();
  }

  /**
   * @param array $params
   *
   * @return array
   */
  protected function createPaymentToken($params) {
    if (empty($this->paymentProcessor)) {
      $this->paymentProcessor = $this->createPaymentProcessorFixture();
    }

    $paymentTokenAPIResult = civicrm_api3('PaymentToken', 'create', array_merge([
      'payment_processor_id' => $this->paymentProcessor['id'],
      'token' => "TEST-TOKEN",
      'email' => "garlic@example.com",
      'billing_first_name' => "Buffy",
      'billing_middle_name' => "Vampire",
      'billing_last_name' => "Slayer",
      'ip_address' => "123.456.789.0",
      'masked_account_number' => "666999666",
    ], $params));
    return $paymentTokenAPIResult;
  }

  protected function createPaymentProcessorFixture() {
    $processor = $this->callAPISuccess('PaymentProcessor', 'get', ['name' =>  'test_processor']);
    if ($processor['count']) {
      // Be a bit forgiving if previous attempts have not been cleaned up.
      return $processor['values'][$processor['id']];
    }
    // the type hard coding makes this a pretty frail test...
    $accountType = key(CRM_Core_PseudoConstant::accountOptionValues(
      'financial_account_type',
      NULL,
      " AND v.name = 'Asset' "
    ));
    $query = "
        SELECT id
        FROM   civicrm_financial_account
        WHERE  is_default = 1
        AND    financial_account_type_id = {$accountType}
      ";
    $financialAccountId = CRM_Core_DAO::singleValueQuery($query);
    $params = [];
    $params['payment_processor_type_id'] = 'smashpig_ingenico';
    $params['name'] = 'test_processor';
    $params['domain_id'] = CRM_Core_Config::domainID();
    $params['is_active'] = TRUE;
    $params['financial_account_id'] = $financialAccountId;
    $result = civicrm_api3('PaymentProcessor', 'create', $params);
    return $result['values'][$result['id']];
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
    $this->ids['Contact'][] = $id;
    return $id;
  }

  /**
   * Delete a contact fully.
   *
   * @param int $contactId
   */
  public function cleanUpContact($contactId) {
    $contributions = $this->callAPISuccess('Contribution', 'get', array(
      'contact_id' => $contactId,
    ));
    if (!empty($contributions['values'])) {
      foreach ($contributions['values'] as $id => $details) {
        $this->callAPISuccess('Contribution', 'delete', array(
          'id' => $id,
        ));
      }
    }
    $this->callAPISuccess('Contact', 'delete', array(
      'id' => $contactId,
      'skip_undelete' => 1,
    ));
  }

}
