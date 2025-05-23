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

    $contactID = $this->createTestContact([
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
      'api.phone.create' => [
        ['location_type_id' => 'Main', 'phone' => 911],
        ['location_type_id' => 'Home', 'phone' => '9887-99-99', 'is_billing' => 1],
      ]]
    );
    $contact2ID = $this->createTestContact( [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Hugger',
      'contact_type' => 'Individual',
      'email' => 'garlicless@example.com',
     ]);
    // We shouldn't traverse references to other contacts
    $orgID = $this->createTestContact([
      'organization_name' => 'Scooby Gang',
      'contact_type' => 'Organization',
      'primary_contact_id' => $contactID,
    ]);
    $this->callAPISuccess('contact', 'create', [
      'id' => $contactID,
      'employer_id' => $orgID
    ]);
    $paymentToken = $this->createPaymentToken([
        'contact_id' => $contactID]
    );
    $paymentToken2 = $this->createPaymentToken([
        'contact_id' => $contact2ID]
    );

    $result = civicrm_api3('Contact', 'Showme', array('id' => $contactID))['values'][$contactID];
    $this->assertEquals(1, count($result['PaymentToken' . $paymentToken['id']]));
    $this->assertArrayNotHasKey('PaymentToken' . $paymentToken2['id'], $result);
    $this->assertEquals('Buffy Vampire Slayer', $result['display_name']);
    $this->assertValueFound($result, 'email:garlic@example.com|Logging Timestamp');

    $this->callAPISuccess('PaymentToken', 'delete', ['id' => $paymentToken['id']]);
    $this->callAPISuccess('PaymentToken', 'delete', ['id' => $paymentToken2['id']]);
    $this->callAPISuccess('PaymentProcessor', 'delete', ['id' => $this->paymentProcessor['id']]);
  }

  /**
   * @param $result
   * @param $expectedValue
   */
  protected function assertValueFound($result, $expectedValue) {
    foreach ($result as $key => $value) {
      if (is_array($value)) {
        continue;
      }
      if (strstr($value, $expectedValue)) {
        // Just to show a success & then return
        $this->assertEquals(1, 1);
        return;
      }
    }
    $this->fail('string not found');
  }

}
