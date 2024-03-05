<?php

namespace Civi\WMFMailTracking;

use Civi\Api4\Email;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests of CiviMail helper classes
 */
class CiviMailTestBase extends TestCase implements HeadlessInterface, TransactionalInterface {

  use Test\EntityTrait;

  protected string $source = 'wmf_communication_test';

  protected string $body = '<p>Dear Wikipedia supporter,</p><p>You are beautiful.</p>';

  protected string $subject = 'Thank you';

  /**
   * @var CiviMailStore
   */
  protected CiviMailStore $mailStore;

  protected int $contactID;

  protected int $emailID;

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): Test\CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->mailStore = new CiviMailStore();
    $contact = $this->getContact('generaltrius@hondo.mil', 'Trius', 'Hondo');
    $this->emailID = $contact['emailID'];
    $this->contactID = $contact['contactID'];
  }

  protected function getContact($email, $firstName, $lastName) {
    $firstResult = Email::get(FALSE)->addWhere('email', '=', $email)
      ->execute()->first();
    if ($firstResult) {
      return [
        'emailID' => $firstResult['id'],
        'contactID' => $firstResult['contact_id'],
      ];
    }

    $contactResult = $this->createTestEntity('Contact', [
      'first_name' => $firstName,
      'last_name' => $lastName,
      'contact_type' => 'Individual',
    ]);
    $emailResult = $this->createTestEntity('Email', [
      'email' => $email,
      'contact_id' => $contactResult['id'],
    ]);
    return [
      'emailID' => $emailResult['id'],
      'contactID' => $contactResult['id'],
    ];
  }

}
