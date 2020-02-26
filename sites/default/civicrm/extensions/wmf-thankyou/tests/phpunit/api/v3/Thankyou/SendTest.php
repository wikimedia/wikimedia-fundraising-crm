<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Thankyou.Send API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Thankyou_SendTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  protected $ids;

  /**
   * Set up for headless tests.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   *
   * @throws \CRM_Extension_Exception_ParseException
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
    civicrm_initialize();
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'aslkdhaslkdjasd');
    }
    parent::setUp();
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown() {
    $this->callAPISuccess('Contribution', 'get', ['contact_id' => $this->ids['Contact'][0], 'api.Contact.delete' => ['skip_undelete' => 1]]);
    parent::tearDown();
  }

  /**
   * Basic test for sending a thank you.
   *
   * We might want to add an override parameter on the date range for the UI but for now this tests the basics.
   *
   * @throws \CRM_Core_Exception
   */
  public function testThankyouSend() {
    $contribution = $this->setupThankyouAbleContribution();
    $this->callAPISuccess('Thankyou', 'Send', ['contribution_id' => $contribution['id']]);
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['id' => $contribution['id']]);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($contribution['thankyou_date'])));
  }


  /**
   * Test that we are still able to force an old thank you to send.
   *
   * @throws \CRM_Core_Exception
   */
  public function testThankyouTooLate() {
    $contribution = $this->setupThankyouAbleContribution();
    $this->callAPISuccess('Contribution', 'create', ['id' => $contribution['id'], 'receive_date' => '2016-01-01']);
    $this->callAPISuccess('Thankyou', 'Send', ['contribution_id' => $contribution['id']]);
  }

  /**
   * Set up a contribution with minimum detail for a thank you.
   *
   * @return array|int
   *
   * @throws \CRM_Core_Exception
   */
  protected function setupThankyouAbleContribution() {
    $wmfFields = $this->callAPISuccess('CustomField', 'get', ['custom_group_id' => 'contribution_extra'])['values'];
    $fieldMapping = [];
    foreach ($wmfFields as $field) {
      $fieldMapping[$field['name']] = $field['id'];
    }
    $this->ids['Contact'][0] = $this->callAPISuccess('Contact', 'create', ['first_name' => 'bob', 'contact_type' => 'Individual', 'email' => 'bob@example.com'])['id'];
    $contribution = $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->ids['Contact'][0],
      'financial_type_id' => 'Donation',
      'total_amount' => 60,
      'custom_' . $fieldMapping['total_usd'] => 60,
      'custom_' . $fieldMapping['original_amount'] => 60,
      'custom_' . $fieldMapping['original_currency'] => 'USD',
      'currency' => 'USD',
    ]);
    return $contribution;
  }

}
