<?php

use Civi\Api4\Address;
use Civi\Api4\Email;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
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
   */
  public function testGetEmailPreferenceApi(): void {
    $contactID = $this->createContact();
    $checksum = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
    $contact = $this->callAPISuccess('Civiproxy', 'getpreferences', ['checksum' => $checksum, 'contact_id' => $contactID]);

    $this->assertEquals(FALSE, $contact['is_opt_in']);
    $this->assertEquals('Bob', $contact['first_name']);
    $this->assertEquals('CA', $contact['country']);
    $this->assertEquals('en_US', $contact['preferred_language']);
    $this->assertEquals('bob.roberto@test.com', $contact['email']);
  }

  public function testGetEmailPreferenceApiV4(): void {
    $contactID = $this->createContact();
    $recur = ContributionRecur::create(FALSE)
     ->setValues([
       'contact_id' => $contactID,
       'trxn_id' => 'active-paypal-recur-' . time(),
       'amount' => 100,
       'frequency_interval' => 1,
       'frequency_unit' => 'month',
       'start_date' => '2023-10-01',
       'installments' => 12,
       'contribution_status_id:name' => 'In Progress',
       'financial_type_id:name' => 'Donation',
     ])->execute()->first();

   Contribution::create(FALSE)
     ->setValues([
       'contact_id' => $contactID,
       'total_amount' => 100.00,
       'financial_type_id:name' => 'Donation',
       'receive_date' => '2023-10-01',
       'contribution_status_id:name' => 'Completed',
       'currency' => 'USD',
       'source' => 'Test Contribution',
       'contribution_extra.original_amount' => 100.00,
       'contribution_extra.original_currency' => 'USD',
       'payment_instrument_id:name' => 'Paypal',
       'contribution_recur_id' => $recur['id'],
     ])->execute();

    $checksum = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
    $contact =  \Civi\Api4\WMFContact::getCommunicationsPreferences()
      ->setChecksum($checksum)
      ->setContact_id($contactID)
      ->execute()->first();

    $this->assertEquals(FALSE, $contact['is_opt_in']);
    $this->assertEquals('Bob', $contact['first_name']);
    $this->assertEquals('CA', $contact['country']);
    $this->assertEquals('en_US', $contact['preferred_language']);
    $this->assertEquals('bob.roberto@test.com', $contact['email']);
    $this->assertEquals(TRUE, $contact['has_paypal']);
  }

  public function createContact(): int {
    $contact = Contact::create(FALSE)->setValues([
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
    )->execute()->first();
    return $contact['id'];
  }

}
