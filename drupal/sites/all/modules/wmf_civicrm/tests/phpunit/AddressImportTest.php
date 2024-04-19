<?php

use Civi\Api4\Address;
use Civi\Api4\Generic\Result;

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class AddressImportTest extends BaseWmfDrupalPhpUnitTestCase {

  public function setUp(): void {
    parent::setUp();
    $this->createIndividual();
  }

  /**
   * Test creating an address with void data does not create an address.
   */
  public function testAddressImportVoidData(): void {
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'street_address' => 'N0NE PROVIDED',
      'postal_code' => 0,
    ];

    $this->processMessage($msg, 'Donation', 'test');
    $addresses = $this->getMouseHouses();
    $this->assertCount(0, $addresses);
  }

  /**
   * Test creating an address not use void data.
   *
   * @dataProvider getVoidValues
   *
   * @param string|int $voidValue
   * @throws CRM_Core_Exception
   */
  public function testAddressImportSkipVoidData($voidValue) {
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'street_address' => 'really cool place',
      'postal_code' => $voidValue,
      'city' => $voidValue,
      'country' => 'US',
    ];

    $this->processMessage($msg, 'Donation', 'test');
    $address = $this->getMouseHouses()->single();
    $this->assertTrue(!isset($address['city']));
    $this->assertTrue(!isset($address['postal_code']));
  }

  /**
   * Get values which should not be stored to the DB.
   *
   * @return array
   */
  public function getVoidValues(): array {
    return [
      ['0'],
      [0],
      ['NoCity'],
      ['City/Town'],
    ];
  }

  /**
   * Test creating an address with void data does not create an address.
   *
   * In this case the contact already exists.
   */
  public function testAddressImportVoidDataContactExists() {
    $msg = [
      'contact_id' => $this->ids['Contact']['default'],
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'street_address' => 'N0NE PROVIDED',
      'postal_code' => 0,
    ];

    $this->processMessage($msg, 'Donation', 'test');
    $this->assertCount(0, $this->getMouseHouses());
  }

  /**
   * @return Civi\Api4\Generic\Result
   */
  public function getMouseHouses(): Result {
    try {
      return Address::get(FALSE)
        ->addWhere('contact_id.last_name', '=', 'Mouse')
        ->execute();
    }
    catch (CRM_Core_Exception $e) {
      $this->fail('Failed to retrieve addresses');
    }
  }

}
