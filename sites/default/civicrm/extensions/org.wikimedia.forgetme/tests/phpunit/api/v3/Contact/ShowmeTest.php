<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
require_once __DIR__ . '/BaseTestClass.php';

/**
 * Contact.Showme API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Contact_ShowmeTest extends api_v3_Contact_BaseTestClass implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Simple test for showme api call.
   */
  public function testApiShowme() {

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
    $result = civicrm_api3('Contact', 'Showme', array('id' => $contact['id']))['values'][$contact['id']];
    $this->assertEquals('Buffy Vampire Slayer', $result['display_name']);
  }

}
