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
  public function testForget(): void {

    $doNotSolicitFieldId = $this->callAPISuccess('CustomField', 'getvalue', ['name' => 'do_not_solicit', 'is_active' => 1, 'return' => 'id']);
    $doNotSolicitFieldLabel = 'custom_' . $doNotSolicitFieldId;
    $this->ids['contact']['Buffy'] = $this->callAPISuccess('Contact', 'create', [
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
    ])['id'];

    $result = $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->getContactID('Buffy')]);

    $this->callAPISuccessGetCount('Phone', ['contact_id' => $this->getContactID('Buffy')], 0);
    $this->callAPISuccessGetCount('Email', ['contact_id' => $this->getContactID('Buffy')], 0);

    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $this->getContactID('Buffy'), 'return' => ['gender_id', $doNotSolicitFieldLabel]]);
    $this->assertEmpty($contact['gender_id']);
    $this->assertEmpty($contact[$doNotSolicitFieldLabel]);
    $loggingEntries = $this->callAPISuccess('Logging', 'showme', ['contact_id' => $this->getContactID('Buffy')])['values'];
    // At this stage we should have contact entries (we will selectively delete from contact rows)
    // and activity contact entries - these will be deleted by activity type.
    foreach ($loggingEntries as $loggingEntry) {
      $this->assertNotEquals('civicrm_phone', $loggingEntry['table']);
      $this->assertNotEquals('civicrm_email', $loggingEntry['table']);
    }
  }

  /**
   * Test that the email is forgetten out of the sort_name & display_name, if present.
   *
   * When contacts do not have other name details their
   */
  public function testForgetEmailDisplayName(): void {
    $this->ids['contact']['garlic'] = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
    ])['id'];
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $this->getContactID('garlic'), 'return' => ['sort_name', 'display_name']]);
    $this->assertEquals('garlic@example.com', $contact['sort_name']);
    $this->assertEquals('garlic@example.com', $contact['display_name']);
    $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->getContactID('garlic')]);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $this->getContactID('garlic'), 'return' => ['sort_name', 'display_name']]);
    $this->assertEquals('Forgotten', $contact['sort_name']);
    $this->assertEquals('Forgotten', $contact['display_name']);
  }

  /**
   * Test our activity deletion.
   *
   * In merge instances a child activity might be deleted by a parent being deleted
   * & then be gone later on so we need at least one activity with a parent.
   *
   * See Bug T204063
   */
  public function testForgetActivities() {
    $buffies =  $this->bufficiseUs();
    $contactToDelete = $buffies['contact_to_delete'];
    $contactToKeep = $buffies['contact_to_keep'];
    $activityToDelete = $this->callAPISuccess('Activity', 'create', ['activity_type_id' => 'Meeting', 'source_contact_id' => $contactToDelete['id']]);

    $this->callAPISuccess('Activity', 'create', [
      'activity_type_id' => 'Meeting',
      'source_contact_id' => $contactToDelete['id'],
      'target_contact_id' => $contactToDelete['id'],
      'activity_id.parent_id' => $activityToDelete['id'],
     ]
    );

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
      'gender_id' => 'Female',
      'birth_date' => '2010-09-07'
    ]);
    $contactToKeep = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Bat Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlicwithmushrooms@example.com',
      'gender_id' => 'Female',
      'birth_date' => '2010-09-07'
    ]);
    $this->ids['contact']['keep'] = $contactToKeep['id'];
    $this->ids['contact']['delete'] = $contactToDelete['id'];
    return ['contact_to_delete' => $contactToDelete, 'contact_to_keep' => $contactToKeep];
  }

  /**
   * Test that details from merged contacts are deleted  too.
   */
  public function testForgetPastBuffies() {
    $buffies = $this->bufficiseUs();
    $this->callAPISuccess('Contact', 'merge', [
      'to_remove_id' => $buffies['contact_to_delete']['id'],
      'to_keep_id' => $buffies['contact_to_keep']['id'],
      'mode' => 'aggressive',
    ]);
    $this->callAPISuccess('Contact', 'forgetme', array('id' => $buffies['contact_to_keep']['id']));
    $theUndead = $this->callAPISuccess('Contact', 'get', [
      'id' => ['IN' => [$buffies['contact_to_delete']['id'], $buffies['contact_to_keep']['id']],
      'is_deleted' => '',
    ]])['values'];
    // We don't remove them - we just exorcise them.
    $this->assertEquals(2, count($theUndead));
    foreach ($theUndead as $deadBuffy) {
      $this->assertTrue(empty($deadBuffy['gender_id']));
      $this->assertTrue(empty($deadBuffy['birth_date']));
    }

    $logs = CRM_Core_DAO::executeQuery("
       SELECT * FROM log_civicrm_contact
       WHERE id IN ({$buffies['contact_to_delete']['id']}, {$buffies['contact_to_keep']['id']})
     ")->fetchAll();
    foreach ($logs as $log) {
      $this->assertTrue(empty($log['gender_id']));
      $this->assertTrue(empty($log['birth_date']));
    }
  }

  /*
  * Test that we are storing ids of deleted emails
  */
  public function testStoreEmailsDeleted() {
  	$contactAPIResult = $this->callAPISuccess('Contact', 'create', [
  	  'first_name' => 'Buffy',
	  'last_name' => 'Vampire Slayer',
	  'contact_type' => 'Individual',
	  'email' => 'garlic@example.com',
	  'gender_id' => 'Female',
	  'birth_date' => '2010-09-07',
	]);
  	$contact = $contactAPIResult['values'][$contactAPIResult['id']];
  	$email = $this->callAPISuccess('Email', 'get', [ 'contact_id' => $contact['id'] ]);
  	$this->callAPISuccess('Contact', 'forgetme', ['id' => $contact['id']]);
  	$sql = "Select id from civicrm_deleted_email where id =" . $email['id'] . ";";
  	$result = CRM_Core_DAO::singleValueQuery( $sql );
  	$this->assertEquals( $email['id'], $result );

	//clean up
	$this->callAPISuccess('Contact', 'delete', ['contact_id' => $contact['id'], 'skip_undelete' => TRUE]);
	$sql = "DELETE from civicrm_deleted_email where id =" . $email['id'] . ";";
	CRM_Core_DAO::executeQuery( $sql );
  }

  /**
   * Test payment token PII details are cleared
   */
  public function testForgetPaymentToken() {

    $contactAPIResult = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Buffy',
      'last_name' => 'Vampire Slayer',
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
      'gender_id' => 'Female',
      'birth_date' => '2010-09-07',
    ]);
    $contact = $contactAPIResult['values'][$contactAPIResult['id']];;

    $paymentTokenAPIResult = $this->createPaymentToken([
      'contact_id' => $contact['id']]
    );

    $paymentToken = $paymentTokenAPIResult['values'][$paymentTokenAPIResult['id']];

    // confirm values exist for the soon to be forgotten fields
    $this->assertNotEmpty($paymentToken['email']);
    $this->assertNotEmpty($paymentToken['billing_first_name']);
    $this->assertNotEmpty($paymentToken['billing_middle_name']);
    $this->assertNotEmpty($paymentToken['billing_last_name']);
    $this->assertNotEmpty($paymentToken['ip_address']);
    $this->assertNotEmpty($paymentToken['masked_account_number']);

    $this->callAPISuccess('Contact', 'forgetme', ['id' => $contact['id']]);

    $theForgottenAPIResult = $this->callAPISuccess('PaymentToken', 'get', [
      'id' => $paymentToken['id'],
    ]);

    $theForgotten = $theForgottenAPIResult['values'][$theForgottenAPIResult['id']];

    // check forgotten values no longer exist
    $this->assertNotTrue(isset($theForgotten['email']));
    $this->assertTrue(isset($theForgotten['billing_first_name']));
    $this->assertTrue(isset($theForgotten['billing_middle_name']));
    $this->assertTrue(isset($theForgotten['billing_last_name']));
    $this->assertNotTrue(isset($theForgotten['ip_address']));
    $this->assertNotTrue(isset($theForgotten['masked_account_number']));

    //clean up
    $this->callAPISuccess('Contact', 'delete', ['contact_id' => $contact['id'], 'skip_undelete' => TRUE]);
    $this->callAPISuccess('PaymentToken', 'delete', ['id' => $paymentToken['id']]);
    $this->callAPISuccess('PaymentProcessor', 'delete',
      ['id' => $this->paymentProcessor['id']]);
  }

  /**
   * Get the relevant contact ID.
   *
   * @param string $key
   *
   * @return int
   */
  protected function getContactID(string $key): int {
    return  $this->ids['contact'][$key];
  }
}
