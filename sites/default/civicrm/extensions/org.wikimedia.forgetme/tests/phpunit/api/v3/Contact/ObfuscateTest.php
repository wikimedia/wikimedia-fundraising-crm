<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Contact.Showme API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Contact_ObfuscateTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

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
   * Simple example test case.
   *
   * Note how the function name begins with the word "test".
   */
  public function testApiExample() {

    $contact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
      'api.phone.create' => [
        ['location_type_id' => 'Main', 'phone' => 911],
        ['location_type_id' => 'Home', 'phone' => '9887-99-99', 'is_billing' => 1],
      ]
    ]);
    $result = civicrm_api3('Contact', 'obfuscate', array('id' => $contact['id']));
    $this->callAPISuccessGetCount('Phone', ['contact_id' => $contact['id']], 0);
    $this->callAPISuccessGetCount('Email', ['contact_id' => $contact['id']], 0);
  }

}
