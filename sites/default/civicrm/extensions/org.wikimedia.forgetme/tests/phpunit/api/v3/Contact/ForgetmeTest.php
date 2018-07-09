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
class api_v3_Contact_ForgetmeTest extends api_v3_Contact_BaseTestClass implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Simple example test case.
   *
   * Note how the function name begins with the word "test".
   */
  public function testForget() {

    $doNotSolicitFieldId = $this->callAPISuccess('CustomField', 'getvalue', ['name' => 'do_not_solicit', 'is_active' => 1, 'return' => 'id']);
    $doNotSolicitFieldLabel = 'custom_' . $doNotSolicitFieldId;
    $contact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
      'gender_id' => 'Female',
      $doNotSolicitFieldLabel => 1,
      'api.phone.create' => [
        ['location_type_id' => 'Main', 'phone' => 911],
        ['location_type_id' => 'Home', 'phone' => '9887-99-99', 'is_billing' => 1],
      ]
    ]);
    $result = civicrm_api3('Contact', 'forgetme', array('id' => $contact['id']));
    $this->callAPISuccessGetCount('Phone', ['contact_id' => $contact['id']], 0);
    $this->callAPISuccessGetCount('Email', ['contact_id' => $contact['id']], 0);
    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contact['id'], 'return' => ['gender_id', $doNotSolicitFieldLabel]]);
    $this->assertEmpty($contact['gender_id']);
    $this->assertEmpty($contact[$doNotSolicitFieldLabel]);
    $loggingEntries = $this->callAPISuccess('Logging', 'showme', ['contact_id' => $contact['id']])['values'];
    // At this stage we should have contact entries (we will selectively delete from contact rows)
    // and activity contact entries - these will be deleted by activity type.
    foreach ($loggingEntries as $loggingEntry) {
      $this->assertNotEquals('civicrm_phone', $loggingEntry['table']);
      $this->assertNotEquals('civicrm_email', $loggingEntry['table']);
    }
  }

}
