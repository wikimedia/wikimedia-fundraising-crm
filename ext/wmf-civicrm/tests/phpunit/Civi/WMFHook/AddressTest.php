<?php

namespace Civi\WMFHook;

use Civi\Api4\Contact;
use Civi\Core\Exception\DBQueryException;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use Civi\Test\EntityTrait;

class AddressTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;
  use EntityTrait;

  /**
   * Check that when updating an NCOA address the old address is re-located.
   *
   * NCOA = National Change of Address and is data we receive from a
   * third party (e.g. DataAxle) based on the people updating their address
   * with the national postal service.
   *
   * See https://www.uspsoig.gov/reports/audit-reports/national-change-address-program
   *
   * The old address should be updated to having a new location type of 'Old
   * 2024'.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testUpdateNCOAAddress(): void {
    $contactID = $this->createIndividual([
      'address_primary.street_address' => 'Happy Street',
    ]);
    try {
      Contact::update(FALSE)
        ->addValue('address_primary.street_address', 'Sad Street')
        ->addValue('address_primary.address_data.address_source', 'ncoa')
        ->addValue('address_primary.address_data.address_updated', '2024-01-01')
        ->addWhere('id', '=', $contactID)
        ->execute();
      $address = \Civi\Api4\Address::get(FALSE)
        ->addSelect('*', 'address_data.*')
        ->addWhere('contact_id', '=', $contactID)
        ->execute();
    }
    catch (DBQueryException $e) {
      $this->fail($e->getDBErrorMessage() . $e->getSQL() . $e->getTraceAsString());
    }
    $this->assertCount(2, $address);
  }

}
