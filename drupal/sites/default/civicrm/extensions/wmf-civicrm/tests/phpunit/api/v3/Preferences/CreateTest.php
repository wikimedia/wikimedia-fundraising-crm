<?php

use Civi\Api4\Address;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Api4\Contact;

/**
 * Civiproxy.Preferences API Test Case
 * This is a generic test class implemented with PHPUnit.
 *
 * @group headless
 */
class api_v3_Preferences_CreateTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;

  /**
   * Set up for headless tests.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and
   * sqlFile().
   *
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Post test cleanup.
   *
   * @throws \API_Exception
   */
  public function tearDown() {
    Contact::delete(FALSE)->addWhere('hash', '=', 'abx')->execute();
    parent::tearDown();
  }

  /**
   * Simple example test case.
   *
   * Note how the function name begins with the word "test".
   *
   * @throws \CRM_Core_Exception
   */
  public function testEmailPreferenceCenterUpdateApi(): void {
    $contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'Roberto',
      'hash' => '12341234',
      'Communication.opt_in' => 0,
      'contact_type' => 'Individual',
      'preferred_language' => 'en',
    ])->addChain('address', Address::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('country_id:label', 'Canada')
      ->addValue('location_type_id:name', 'Home')
      ->addValue('is_primary', 1)
    )
      ->execute()->first()['id'];

    $this->callAPISuccess('Preferences', 'create', [
      'contact_id' => $contactID,
      'contact_hash' => '12341234',
      'language' => 'es',
      "country" => 'US',
      'send_email' => 'true',
    ]);

    $contact = Contact::get(FALSE)->addWhere('id', '=', (int) $contactID)
      ->setSelect(['preferred_language', 'Communication.opt_in'])
      ->execute()->first();

    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', (int) $contactID)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addSelect('country_id.iso_code')
      ->execute()
      ->first();
    echo "\nTest1: Email Preference Center update civicrm_contact id: $contactID's preferred_language to es, country to US(1228) and civicrm_value_1_communication_4.opt_in to 1.";

    $this->assertEquals(1, $contact['Communication.opt_in']);
    $this->assertEquals('es', $contact['preferred_language']);
    $this->assertEquals('US', $address['country_id.iso_code']);

    $this->callAPISuccess('Preferences', 'create', [
      'contact_id' => $contactID,
      'contact_hash' => '12341234',
      'language' => 'pt-br',
      "country" => 'AF',
      'send_email' => 'false',
    ]);

    echo "\nTest2: Email Preference Center update civicrm_contact id: $contactID's preferred_language to pt-br, country to AF(1001) and civicrm_value_1_communication_4.opt_in to 0.";
    $contact2 = Contact::get(FALSE)->addWhere('id', '=', (int) $contactID)
      ->setSelect(['preferred_language', 'Communication.opt_in'])
      ->execute()->first();

    $address2 = Address::get(FALSE)
      ->addWhere('contact_id', '=', (int) $contactID)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addSelect('country_id.iso_code')
      ->execute()
      ->first();

    $this->assertEquals(0, $contact2['Communication.opt_in']);
    $this->assertEquals('pt-br', $contact2['preferred_language']);
    $this->assertEquals('AF', $address2['country_id.iso_code']);
  }

}
