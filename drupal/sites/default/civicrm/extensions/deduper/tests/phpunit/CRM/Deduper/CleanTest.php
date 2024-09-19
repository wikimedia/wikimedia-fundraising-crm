<?php

use CRM_Deduper_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\Api3TestTrait;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Phone;
use Civi\Api4\Address;


require_once __DIR__ . '/DedupeBaseTestClass.php';
/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CleanTest extends DedupeBaseTestClass {

  /**
   * Entity being worked on.
   *
   * @var string[email|phone|address]
   */
  protected $entity;

  /**
   * Convenience array for location types.
   *
   * Keyed like ['Home' => 1, 'Work' => 2....]
   *
   * @var array
   */
  protected $locationTypes = [];

  /**
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    parent::setUp();
    $this->locationTypes = array_flip(CRM_Deduper_BAO_MergeConflict::getLocationTypes());
  }

  /**
   * Test that a contact with no primary email has one of them update to be primary.
   *
   * @dataProvider getLocationEntityData
   *
   * @param string $entity
   * @param array $values
   * @param array $secondaryValues
   *
   * @throws \CRM_Core_Exception
   */
  public function testCleanMissingPrimary($entity, $values, $secondaryValues) {
    $this->entity = $entity;
    $this->ids['contact']['Ponyo'] = (int) $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Ponyo'])['id'];
    $values = array_merge([
      'email' => 'ponyo@example.com',
      'location_type_id' => $this->locationTypes['Home'],
      'contact_id' => $this->ids['contact']['Ponyo'],
    ], $values);

    $this->createEntity($values);
    $this->createEntity(array_merge($values, $secondaryValues));
    $this->updateIsPrimaryForContact($this->ids['contact']['Ponyo'] , 0);

    $this->doClean($this->ids['contact']['Ponyo']);
    $this->checkExactlyOnePrimary($this->ids['contact']['Ponyo'], 2);
  }

  /**
   * Test that a contact with more than one primary is brought back down to size.
   *
   * @dataProvider getLocationEntityData
   *
   * @param string $entity
   * @param array $values
   * @param array $secondaryValues
   *
   * @throws \CRM_Core_Exception
   */
  public function testCleanExtraPrimary($entity, $values, $secondaryValues) {
    $this->entity = $entity;
    $this->ids['contact']['Ponyo'] = (int) $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Ponyo'])['id'];
    $values = array_merge([
      'email' => 'ponyo@example.com',
      'location_type_id' => $this->locationTypes['Home'],
      'contact_id' => $this->ids['contact']['Ponyo'],
    ], $values);

    $this->createEntity($values);
    $this->createEntity(array_merge($values, $secondaryValues));
    $this->updateIsPrimaryForContact($this->ids['contact']['Ponyo'], 1);

    $this->doClean($this->ids['contact']['Ponyo']);
    $this->checkExactlyOnePrimary($this->ids['contact']['Ponyo'], 2);
  }

  /**
   * Test that a contact with 3 identical emails (or phones, addresses), with the same location winds up with just one.
   *
   * More than one email of the same location cannot be created through the UI but it
   * can through the API. Dedupe doesn't cope with this (& it is not 'good' data').
   *
   * @dataProvider getLocationEntityData
   *
   * @param string $entity
   * @param array $values
   *
   * @throws \CRM_Core_Exception
   */
  public function testCleanDuplicateLocationSameValues($entity, $values) {
    $this->entity = $entity;
    $this->ids['contact']['Ponyo'] = (int) $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Ponyo'])['id'];
    $values = array_merge([
      'location_type_id' => $this->locationTypes['Home'],
      'contact_id' => $this->ids['contact']['Ponyo'],
    ], $values);
    $this->createEntity($values);
    $this->createEntity($values);
    $this->createEntity($values);
    // It's possible a core fix could prevent the 'bad data' we are trying to set up so validate that
    // our set up data is as bad as we hoped.
    $this->checkEntities($this->ids['contact']['Ponyo'], [$values, $values, $values]);
    $this->doClean($this->ids['contact']['Ponyo']);
    $this->checkEntities($this->ids['contact']['Ponyo'], [$values]);
  }

  /**
   * Test that a contact with 3 emails with the same location but 2 unique emails winds up with 2.
   *
   * The one that is primary keeps the location and the other should get the top priority alternative address.
   *
   *
   * @dataProvider getLocationEntityData
   *
   * @param string $entity
   * @param array $values
   * @param array $secondaryValues
   *
   * @throws \CRM_Core_Exception
   */
  public function testCleanDuplicateLocationDifferentValues($entity, $values, $secondaryValues) {
    $this->entity = $entity;
    $this->ids['contact']['Ponyo'] = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Ponyo'])['id'];
    Civi::settings()->set('deduper_location_priority_order', [$this->locationTypes['Work'], $this->locationTypes['Other'], $this->locationTypes['Home']]);

    $values = array_merge([
      'location_type_id' => $this->locationTypes['Home'],
      'contact_id' => $this->ids['contact']['Ponyo'],
    ], $values);

    $this->createEntity($values);
    $this->createEntity(array_merge($values, ['is_primary' => TRUE], $secondaryValues));
    $this->createEntity($values);
    $this->createEntity(array_merge($values, $secondaryValues));

    $this->doClean($this->ids['contact']['Ponyo']);

    $this->checkEntities($this->ids['contact']['Ponyo'], [
      array_merge($values, ['is_primary' => TRUE], $secondaryValues),
      array_merge($values, ['location_type_id' => $this->locationTypes['Work']])
    ]);
    $this->checkExactlyOnePrimary($this->ids['contact']['Ponyo'], 2);
  }

  /**
   * Get entity for location tests.
   *
   * @return array
   */
  public function getLocationEntityData(): array {
    return [
      ['Address', ['street_address' => '10 Downing Street'], ['street_address' => '10 Sesame Street']],
      ['Phone', ['phone' => '555-666', 'phone_type_id' => 1], ['phone' => '666-777']],
      ['Email', ['email' => 'ponyo@example.com'], ['email' => 'totoro@example.com']],
    ];
  }

  /**
   * Create the entity with the given values.
   *
   * @param array $values
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createEntity($values) {
    switch ($this->entity) {
      case 'Email' :
        Email::create()->setCheckPermissions(FALSE)->setValues($values)->execute();
        return;

      case 'Phone' ;
        Phone::create()->setCheckPermissions(FALSE)->setValues($values)->execute();
        return;

      case 'Address' :
        Address::create()->setCheckPermissions(FALSE)->setValues($values)->execute();
        return;

    }

  }

  /**
   * Update all entities for the contact to have is or is not primary.
   *
   * Use a direct query as the api should block us from creating a contact with no primary.
   *
   * @param int $ponyoID
   * @param int $is_primary
   */
  protected function updateIsPrimaryForContact($ponyoID, $is_primary) {
    $table = 'civicrm_' . strtolower($this->entity);
    CRM_Core_DAO::executeQuery("UPDATE $table SET is_primary = $is_primary WHERE contact_id = $ponyoID");
  }

  /**
   * @param int $ponyoID
   *
   * @return \Civi\Api4\Generic\Result|mixed
   * @throws \CRM_Core_Exception
   */
  public function getContactEntities($ponyoID) {
    switch ($this->entity) {
      case 'Email':
        return Email::get()->setCheckPermissions(FALSE)->addOrderBy('is_primary', 'DESC')->addWhere('contact_id', '=', $ponyoID)->addSelect('*')->execute();

      case 'Phone';
        return Phone::get()->setCheckPermissions(FALSE)->addOrderBy('is_primary', 'DESC')->addWhere('contact_id', '=', $ponyoID)->addSelect('*')->execute();

      case 'Address':
        return Address::get()->setCheckPermissions(FALSE)->addOrderBy('is_primary', 'DESC')->addWhere('contact_id', '=', $ponyoID)->addSelect('*')->execute();
    }
  }

  /**
   * Call the clean action.
   *
   * @param int $ponyoID
   */
  protected function doClean($ponyoID) {
    switch ($this->entity) {
      case 'Email':
        Email::clean()->setCheckPermissions(FALSE)->setContactIDs([$ponyoID])->execute();
        return;

      case 'Phone';
        Phone::clean()->setCheckPermissions(FALSE)->setContactIDs([$ponyoID])->execute();
        return;

      case 'Address' :
        Address::clean()->setCheckPermissions(FALSE)->setContactIDs([$ponyoID])->execute();
        return;

    }

  }

  /**
   * Assert there is exactly one primary entity for the contact.
   *
   * @param int $ponyoID
   *
   * @param int $expectedCount
   *
   * @throws \CRM_Core_Exception
   */
  protected function checkExactlyOnePrimary(int $ponyoID, $expectedCount) {
    $created = $this->getContactEntities($ponyoID);
    $this->assertCount($expectedCount, $created);
    $this->assertTrue($created->first()['is_primary']);
    $primaryCount = 0;
    foreach ($created as $entity) {
      $primaryCount += $entity['is_primary'];
    }
    $this->assertEquals(1, $primaryCount);
  }

  /**
   * Assert the entities attached to the contact are per the values array.
   *
   * @param int $ponyoID
   * @param array $values
   *   Expected entity values.
   *
   * @throws \CRM_Core_Exception
   */
  protected function checkEntities(int $ponyoID, $values) {
    $entities = $this->getContactEntities($ponyoID);
    $this->assertCount(count($values), $entities, 'incorrect count found for ' . $this->entity);
    foreach ($values as $index => $valueSet) {
      foreach ($valueSet as $key => $value) {
        $this->assertEquals($value, $entities[$index][$key]);
      }
    }
  }

}
