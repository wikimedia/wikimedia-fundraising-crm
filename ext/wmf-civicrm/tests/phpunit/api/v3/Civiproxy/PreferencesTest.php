<?php

use Civi\Api4\Address;
use Civi\Api4\Email;
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
class api_v3_Civiproxy_PreferencesTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface {
  use Api3TestTrait;

  protected $contactID;

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
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    Contact::delete(FALSE)->addWhere('id', '=', $this->contactID)->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /**
   * Simple example test case.
   *
   * Note how the function name begins with the word "test".
   *
   * @throws \CRM_Core_Exception
   * @throws \CRM_Core_Exception
   */
  public function testGetEmailPreferenceApi(): void {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'Roberto',
      'Communication.opt_in' => 0,
      'contact_type' => 'Individual',
      'preferred_language' => 'en_US',
    ]) ->addChain('address', Address::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('country_id:label', 'Canada')
      ->addValue('location_type_id:name', 'Home')
    ) ->addChain('email', Email::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('email', 'bob.roberto@test.com')
      ->addValue('location_type_id:name', 'Home')
    )
    ->execute()->first()['id'];

    $checksum = CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    $contact = $this->callAPISuccess('Civiproxy', 'getpreferences', ['checksum' => $checksum, 'contact_id' => $this->contactID]);

    $this->assertEquals(FALSE, $contact['is_opt_in']);
    $this->assertEquals('Bob', $contact['first_name']);
    $this->assertEquals('CA', $contact['country']);
    $this->assertEquals('en_US', $contact['preferred_language']);
    $this->assertEquals('bob.roberto@test.com', $contact['email']);
  }

}
