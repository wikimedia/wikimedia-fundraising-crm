<?php

namespace Civi\Wmf;

use Civi\Api4\Contact;
use Civi\Api4\WMFContact;
use PHPUnit\Framework\TestCase;

/**
 * Contact Save tests for WMF user cases.
 *
 */
class WMFContactSaveTest extends TestCase {

  /**
   * @var array
   */
  protected $ids = [];

  /**
   * Clean up after test.
   *
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \CRM_Core_Exception
   */
  protected function tearDown(): void {
    if (!empty($this->ids['Contact'])) {
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contact'])
        ->setUseTrash(FALSE)->execute();
    }
    parent::tearDown();
    unset(\Civi::$statics['wmf_contact']);
  }

  public function testExternalIdentifierUpdate(): void {
    $newVenmoUserName = 'test';
    $unique = uniqid(__FUNCTION__);
    $initialDetails = [
        'first_name' => $unique,
        'last_name' => 'bb',
        'nick_name' => '',
        'email' => $unique . '@bb.org',
        'gateway' => 'braintree',
        'payment_method' => 'venmo',
        'external_identifier' => 'old',
        'country' => 'US',
        'street_address' => '',
        'city' => '',
        'street_number' => '',
        'postal_code' => '',
        'state_province' => '',
    ];
    $oldContactId = WMFContact::save(FALSE)->setMessage($initialDetails)->execute()->first()['id'];
    $oldContact = Contact::get(FALSE)
      ->addSelect('External_Identifiers.venmo_user_name')
      ->addWhere('id', '=', $oldContactId)
      ->execute()->first();
    $this->assertEquals('old', $oldContact['External_Identifiers.venmo_user_name']);

    $newDetails = array_merge($initialDetails, [
      'id' => $oldContactId,
      'contact_id' => $oldContactId,
      'external_identifier' => $newVenmoUserName
    ]);

    WMFContact::save(FALSE)->setMessage($newDetails)->execute();
    $updatedContact = Contact::get(FALSE)
        ->addSelect('External_Identifiers.venmo_user_name')
        ->addWhere('id', '=', $oldContactId)
        ->execute()->first();

    $this->assertEquals($newVenmoUserName, $updatedContact['External_Identifiers.venmo_user_name']);
  }

  public function testExternalIdentifierFundraiseupIdUpdate(): void {
    $fundraiseup_id = rand(10000,11200);
    $unique = uniqid(__FUNCTION__);
    $initialDetails = [
        'first_name' => $unique,
        'last_name' => 'bb',
        'nick_name' => '',
        'email' => $unique . '@bb.org',
        'gateway' => 'adyen',
        'payment_method' => 'cc',
        'external_identifier' => '',
        'country' => 'US',
        'street_address' => '',
        'city' => '',
        'street_number' => '',
        'postal_code' => '',
        'state_province' => '',
    ];
    $oldContactId = WMFContact::save(FALSE)->setMessage($initialDetails)->execute()->first()['id'];

    $newDetails = array_merge($initialDetails, [
      'id' => $oldContactId,
      'contact_id' => $oldContactId,
      'gateway' => 'fundraiseup',
      'external_identifier' => $fundraiseup_id,
    ]);

    WMFContact::save(FALSE)->setMessage($newDetails)->execute();
    $updatedContact = Contact::get(FALSE)
        ->addSelect('External_Identifiers.fundraiseup_id', 'email_primary.email')
        ->addWhere('id', '=', $oldContactId)
        ->execute()->first();

    $this->assertNotNull($updatedContact);
    $this->assertEquals(strtolower($initialDetails['email']), $updatedContact['email_primary.email']);
    $this->assertEquals($fundraiseup_id, $updatedContact['External_Identifiers.fundraiseup_id']);
  }

  public function testExternalIdentifierIdDedupe(): void {
    $lastName = uniqid(__FUNCTION__);
    $fundraiseup_id = rand(10000,11200);
    $new_email = 'anothertestemail@em.org';
    $new_first_name = 'One';
    $initialDetails = [
        'first_name' => 'Zero',
        'last_name' => $lastName,
        'nick_name' => 'Nick',
        'email' => 'testemail@em.org',
        'gateway' => 'fundraiseup',
        'external_identifier' => $fundraiseup_id,
        'country' => 'US',
        'street_address' => '',
        'city' => '',
        'street_number' => '',
        'postal_code' => '',
        'state_province' => '',
    ];

    $newDetails = array_merge($initialDetails, [
        'first_name' => $new_first_name,
        'email' => $new_email
    ]);

    WMFContact::save(FALSE)->setMessage($initialDetails)->execute();
    WMFContact::save(FALSE)->setMessage($newDetails)->execute();
    $contact = Contact::get(FALSE)
        ->addSelect('first_name', 'External_Identifiers.fundraiseup_id', 'email_primary.email')
        ->addWhere('External_Identifiers.fundraiseup_id', '=', $fundraiseup_id)
        ->execute();
    $this->ids['Contact'][] = $contact[0]['id'];

    $this->assertCount(1, $contact);
    $this->assertEquals($new_email, $contact[0]['email_primary.email']);
    $this->assertEquals($new_first_name, $contact[0]['first_name']);
  }
}