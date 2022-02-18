<?php

use Civi\Api4\Email;
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
      'preferred_language' => 'fr_CA',
    ])->addChain('address', Address::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('country_id:name', 'CA')
      ->addValue('location_type_id:name', 'Home')
      ->addValue('is_primary', 1)
    ) ->addChain('email', Email::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('email', 'bob.roberto@test.com')
      ->addValue('location_type_id:name', 'Home')
    )
      ->execute()->first()['id'];

    $this->callAPISuccess('Preferences', 'create', [
      'contact_id' => $contactID,
      'contact_hash' => '12341234',
      'language' => 'es',
      "country" => 'US',
      "email" => 'test1@gmail.com',
      'send_email' => 'true',
    ]);

    $contact = Contact::get(FALSE)->addWhere('id', '=', (int) $contactID)
      ->setSelect(['preferred_language', 'Communication.opt_in'])
      ->execute()->first();

    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', (int) $contactID)
      ->addWhere('is_primary', '=', 1)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addSelect('country_id.iso_code')
      ->execute()
      ->first();

    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', (int) $contactID)
      ->addWhere('is_primary', '=', 1)
      ->execute()
      ->first();

    $this->assertEquals(1, $contact['Communication.opt_in']);
    $this->assertEquals('es', $contact['preferred_language']);
    $this->assertEquals('US', $address['country_id.iso_code']);
    $this->assertEquals('test1@gmail.com', $email['email']);

    $this->callAPISuccess('Preferences', 'create', [
      'contact_id' => $contactID,
      'contact_hash' => '12341234',
      'language' => 'pt-br',
      "country" => 'AF',
      "email" => 'test2@gmail.com',
      'send_email' => 'false',
    ]);

    $contact2 = Contact::get(FALSE)->addWhere('id', '=', (int) $contactID)
      ->setSelect(['preferred_language', 'Communication.opt_in'])
      ->execute()->first();

    $address2 = Address::get(FALSE)
      ->addWhere('contact_id', '=', (int) $contactID)
      ->addWhere('is_primary', '=', 1)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addSelect('country_id.iso_code')
      ->execute()
      ->first();

    $email2 = Email::get(FALSE)
      ->addWhere('contact_id', '=', (int) $contactID)
      ->addWhere('is_primary', '=', 1)
      ->execute()
      ->first();

    $this->assertEquals(0, $contact2['Communication.opt_in']);
    $this->assertEquals('pt-br', $contact2['preferred_language']);
    $this->assertEquals('AF', $address2['country_id.iso_code']);
    $this->assertEquals('test2@gmail.com', $email2['email']);
  }

}
