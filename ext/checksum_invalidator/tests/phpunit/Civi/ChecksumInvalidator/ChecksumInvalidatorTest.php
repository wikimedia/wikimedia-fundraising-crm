<?php
declare(strict_types = 1);
namespace Civi\ChecksumInvalidator;

use Civi\Api4\Contact;
use Civi\Api4\InvalidChecksum;
use CRM_ChecksumInvalidator_ExtensionUtil as E;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class ChecksumInvalidatorTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var int
   */
  protected $contactId;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp():void {
    parent::setUp();
    $this->contactId = Contact::create(FALSE)
      ->addValue('email', 'checksum@example.org')
      ->addValue('contact_type', 'Individual')
      ->execute()->first()['id'];
  }

  public function tearDown():void {
    parent::tearDown();
  }

  /**
   * Generate a checksum for a contact and then invalidate it
   * and verify that it no longer can be validated.
   */
  public function testInvalidateChecksumValidation() {
    $checksum = Contact::getChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setTtl(24)
      ->execute()->first()['checksum'];

    Contact::invalidateChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setChecksum($checksum)
      ->execute();

    $checkAfter = Contact::validateChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setChecksum($checksum)
      ->execute()->first();

    $this->assertFalse($checkAfter['valid'], "Checksum should be invalid after running invalidate action");
  }

  /**
   * Check that an invalidated checksum which has expired is removed
   * from the table (after running the job).
   */
  public function testExpiredChecksumRemoval() {
    $checksum = Contact::getChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setTtl(24)
      ->execute()->first()['checksum'];

    Contact::invalidateChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setChecksum($checksum)
      ->execute();

    InvalidChecksum::update(FALSE)
      ->addWhere('contact_id', '=', $this->contactId)
      ->addWhere('checksum', '=', $checksum)
      ->addValue('expiry', date('Y-m-d H:i:s', strtotime('-1 day')))
      ->execute();

    civicrm_api3('InvalidChecksum', 'clearexpired');

    $count = InvalidChecksum::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactId)
      ->addWhere('checksum', '=', $checksum)
      ->execute()->count();

    $this->assertEquals(0, $count, "Expired invalid checksums should be removed from the table");
  }

  /**
   * Check that an inf lifespan invalidated checksum is never removed from the table.
   */
  public function testInfiniteChecksumPersistence() {
    $checksum = Contact::getChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setTtl(0)
      ->execute()->first()['checksum'];

    Contact::invalidateChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setChecksum($checksum)
      ->execute();

    civicrm_api3('InvalidChecksum', 'clearexpired');

    $checkAfter = Contact::validateChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setChecksum($checksum)
      ->execute()->first();

    $this->assertFalse($checkAfter['valid'], "Infinite checksum should still be invalid");
  }

}
