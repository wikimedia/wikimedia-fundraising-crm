<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

require_once __DIR__ . '/BaseTestClass.php';

/**
 * Contact.Showme API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Contact_ForgetmeTest extends api_v3_Contact_BaseTestClass implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Test Forget functionality.
   */
  public function testForget() {

    $doNotSolicitFieldId = $this->callAPISuccess('CustomField', 'getvalue', ['name' => 'do_not_solicit', 'is_active' => 1, 'return' => 'id']);
    $doNotSolicitFieldLabel = 'custom_' . $doNotSolicitFieldId;
    $contact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
      'gender_id' => 'Female',
      $doNotSolicitFieldLabel => 1,
      'api.phone.create' => [
        ['location_type_id' => 'Main', 'phone' => 911],
        ['location_type_id' => 'Home', 'phone' => '9887-99-99', 'is_billing' => 1],
      ],
    ]);

    $result = $this->callAPISuccess('Contact', 'forgetme', array('id' => $contact['id']));

    $this->callAPISuccessGetCount('Phone', ['contact_id' => $contact['id']], 0);
    $this->callAPISuccessGetCount('Email', ['contact_id' => $contact['id']], 0);

    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contact['id'], 'return' => ['gender_id', $doNotSolicitFieldLabel]]);
    $this->assertEmpty($contact['gender_id']);
    $this->assertEmpty($contact[$doNotSolicitFieldLabel]);
    $loggingEntries = $this->callAPISuccess('Logging', 'showme', ['contact_id' => $contact['id']])['values'];
    // At this stage we should have contact entries (we will selectively delete from contact rows)
    // and activity contact entries - these will be deleted by activity type.
    foreach ($loggingEntries as $loggingEntry) {
      $this->assertNotEquals('civicrm_phone', $loggingEntry['table']);
      $this->assertNotEquals('civicrm_email', $loggingEntry['table']);
    }
  }

  /**
   * Test our activity deletion.
   */
  public function testForgetActivities() {
    $buffies =  $this->bufficiseUs();
    $contactToDelete = $buffies['contact_to_delete'];
    $contactToKeep = $buffies['contact_to_keep'];
    $activityToDelete = $this->callAPISuccess('Activity', 'create', ['activity_type_id' => 'Meeting', 'source_contact_id' => $contactToDelete['id']]);
    $activityToKeep = $this->callAPISuccess('Activity', 'create', ['activity_type_id' => 'Meeting', 'source_contact_id' => $contactToDelete['id'], 'target_contact_id' => $contactToKeep['id']]);

    civicrm_api3('Contact', 'forgetme', array('id' => $contactToDelete['id']));

    $this->callAPISuccessGetCount('ActivityContact', ['contact_id' => $contactToDelete['id'], 'activity_id.activity_type_id' => ['IN' => ["Meeting"]]], 0);
    $this->callAPISuccessGetCount('ActivityContact', ['contact_id' => $contactToKeep['id'], 'activity_id.activity_type_id' => ['IN' => ["Meeting"]]], 1);

    $loggingEntries = $this->callAPISuccess('Logging', 'showme', ['contact_id' => $contactToDelete['id']])['values'];
    foreach ($loggingEntries as $loggingEntry) {
      if ($loggingEntry['table'] === 'civicrm_activity_contact') {
        // This is a bit clumsy - basically at the end of the FORGET a new activity is created for the forget,
        // it links to our contact as source & target -so we WILL have activity_contact records but they
        // will be higher ids than the ones we are looking at.
        $this->assertGreaterThan($activityToKeep['id'], $loggingEntry['activity_id']);
      }
    }
    // One deleted, one kept.
    $this->assertEquals(1, CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM log_civicrm_activity WHERE id IN (%1, %2)', [1 => [$activityToDelete['id'], 'Integer'], 2 => [$activityToKeep['id'], 'Integer']]));

  }

  /**
   * Test our activity relationships.
   */
  public function testForgetRelationships() {
    $buffies = $this->bufficiseUs();
    $relationshipTypes = $this->callAPISuccess('RelationshipType', 'get', ['contact_type_a' => 'Individual', 'contact_type_b' => 'Individual', 'return' => 'id', 'options' => ['limit' => 2], 'sequential' => 1])['values'];
    // Create circular relationship :-). Key is to have contact_id_a & contact_id_b tested.
    $this->callAPISuccess('Relationship', 'create', [
      'relationship_type_id' => $relationshipTypes[0]['id'],
      'contact_id_a' => $buffies['contact_to_delete']['id'],
      'contact_id_b' => $buffies['contact_to_keep']['id'],
    ]);
    $this->callAPISuccess('Relationship', 'create', [
      'relationship_type_id' => $relationshipTypes[1]['id'],
      'contact_id_a' => $buffies['contact_to_keep']['id'],
      'contact_id_b' => $buffies['contact_to_delete']['id'],
    ]);

    $this->callAPISuccess('Contact', 'forgetme', array('id' => $buffies['contact_to_delete']['id']));

    $this->callAPISuccessGetCount('Relationship', ['contact_id_a' => $buffies['contact_to_delete']['id']], 0);
    $this->callAPISuccessGetCount('Relationship', ['contact_id_b' => $buffies['contact_to_delete']['id']], 0);

    $this->assertEquals(0, CRM_Core_DAO::singleValueQuery(
      'SELECT count(*) FROM log_civicrm_relationship WHERE contact_id_a = %1 OR contact_id_b = %1', [1 => [$buffies['contact_to_delete']['id'], 'Integer']]
    ));
  }

  /**
   * @return array
   */
  protected function bufficiseUs() {
    $contactToDelete = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
    ]);
    $contactToKeep = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Bat Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlicwithmushrooms@example.com',
    ]);
    return ['contact_to_delete' => $contactToDelete, 'contact_to_keep' => $contactToKeep];
  }

}
