<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Test\EntityTrait;
use Civi\WMFException\WMFException;

/**
 * @group Queue2Civicrm
 */
class OptInTest extends BaseQueueTestCase {

  use EntityTrait;

  protected string $queueConsumer = 'OptIn';

  protected string $queueName = 'opt-in';

  /**
   * @var string
   */
  protected string $email = 'testOptIn@example.net';

  public function setUp(): void {
    parent::setUp();
    $this->createIndividual();
  }

  protected function getMessage() {
    $message = $this->loadMessage('optin');
    $message['email'] = $this->email;
    return $message;
  }

  protected function getContactMessage(): array {
    return [
      'email' => $this->email,
      'first_name' => 'Christine',
      'last_name' => 'Mouse',
      'street_address' => '1 Test Street',
      'city' => 'Test-land',
      'postal_code' => '13126',
      'country' => 'US',
    ];
  }

  protected function createEmail($params = []): void {
    $params += [
      'email' => $this->email,
      'contact_id' => $this->ids['Contact']['danger_mouse'],
    ];
    $this->createTestEntity('Email', $params);
  }

  public function testValidMessage(): void {
    $this->createEmail(['is_primary' => '1']);
    $this->processMessage($this->getMessage());
    $contact = $this->getContact();
    $this->assertEquals(1, $contact['Communication.opt_in']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testOptedOutContact(): void {
    $this->createEmail(['is_primary' => '1']);
    Contact::update(FALSE)->setValues([
      'id' => $this->ids['Contact']['danger_mouse'],
      'is_opt_out' => TRUE,
      'do_not_email' => TRUE,
      'Communication.do_not_solicit' => TRUE,
    ])->execute();
    $this->processMessage($this->getMessage());
    $contact = $this->getContact();
    $this->assertTrue($contact['Communication.opt_in']);
    $this->assertFalse($contact['is_opt_out']);
    $this->assertFalse($contact['do_not_email']);
    $this->assertEquals('', $contact['Communication.do_not_solicit']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testNonExistentEmail(): void {
    $this->processMessage($this->getContactMessage());
    // Check the original contact (with no email) is unchanged.
    $contact = $this->getContact();
    $this->assertEquals('', $contact['Communication.opt_in']);

    //check that the contact was created
    $newContact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', $this->email)
      ->addSelect('Communication.opt_in')->execute()->first();

    //check that there is a new contact id
    $this->assertNotEquals($contact['id'], $newContact['id']);

    //check that the opt_in field was set
    $this->assertTrue($newContact['Communication.opt_in']);
  }

  public function testNonPrimaryEmail(): void {
    $this->createEmail([
      'email' => 'aDifferentEmail@example.net',
      'is_primary' => 1,
    ]);
    $this->createEmail([
      'is_primary' => 0,
    ]);
    $this->processMessage($this->getMessage(), $this->queueConsumer, $this->queueName);
    $contact = $this->getContact();
    $this->assertEquals('', $contact['Communication.opt_in']);
  }

  public function testMalformedMessage(): void {
    $this->expectException(WMFException::class);
    $msg = [
      'hither' => 'thither',
    ];
    (new OptInQueueConsumer($this->queueName))->processMessage($msg);
  }

}
