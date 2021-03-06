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
  public function testApi(): void {
    $contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'Roberto',
      'hash' => 'ab2',
      'country' => 'US',
      'Communication.opt_in' => 0,
      'contact_type' => 'Individual',
      'preferred_language' => 'en_US',
    ]) ->addChain('address', Address::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('country_id:label', 'United States')
      ->addValue('location_type_id:name', 'Home')
    )
      ->execute()->first()['id'];

    $this->callAPISuccess('Preferences', 'create', [
      'contact_id' => $contactID,
      'contact_hash' => 'ab2',
      'language' => 'es_ES',
      'send_email' => TRUE,
    ]);
    $contact = Contact::get(FALSE)->addWhere('id', '=', $contactID)
      ->setSelect(['preferred_language',  'Communication.opt_in'])
      ->execute()->first();
    $this->assertEquals(TRUE, $contact['Communication.opt_in']);
    $this->assertEquals('es_ES', $contact['preferred_language']);
  }

}
