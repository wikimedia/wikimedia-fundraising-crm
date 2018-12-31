<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Contact.Showme API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Contact_BaseTestClass extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  protected $paymentProcessor = [];

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
  public function setUp() {
    parent::setUp();
    civicrm_initialize();
    CRM_Forgetme_Hook::testSetup();
    if (!isset($GLOBALS['_PEAR_default_error_mode'])) {
      // This is simply to protect against e-notices if globals have been reset by phpunit.
      $GLOBALS['_PEAR_default_error_mode'] = NULL;
      $GLOBALS['_PEAR_default_error_options'] = NULL;
    }
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown() {
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

}
