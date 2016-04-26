<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class MergeTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Id of the contact created in the setup function.
   *
   * @var int
   */
  protected $contactID;

  /**
   * Id of the contact created in the setup function.
   *
   * @var int
   */
  protected $contactID2;

  public function setUp() {
    parent::setUp();
    civicrm_initialize();
    $this->imitateAdminUser();
    $contact = $this->callAPISuccess('Contact', 'create', array(
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email' => 'the_don@duckland.com',
      wmf_civicrm_get_custom_field_name('do_not_solicit') => 0,
    ));
    $this->contactID = $contact['id'];

    $contact = $this->callAPISuccess('Contact', 'create', array(
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email' => 'the_don@duckland.com',
      wmf_civicrm_get_custom_field_name('do_not_solicit') => 1,
    ));
    $this->contactID2 = $contact['id'];
  }

  public function tearDown() {
    $this->callAPISuccess('Contribution', 'get', array(
      'contact_id' => array('IN' => array($this->contactID, $this->contactID2)),
      'api.Contribution.delete' => 1,
    ));
    $this->callAPISuccess('Contact', 'delete', array('id' => $this->contactID));
    $this->callAPISuccess('Contact', 'delete', array('id' => $this->contactID2));
    parent::tearDown();
  }

  /**
   * Test that the merge hook causes our custom fields to not be treated as conflicts.
   *
   * We also need to check the custom data fields afterwards.
   */
  public function testMergeHook() {
    $this->callAPISuccess('Contribution', 'create', array(
      'contact_id' => $this->contactID,
      'financial_type_id' => 'Cash',
      'total_amount' => 10,
      'currencty' => 'USD',
    ));
    $this->callAPISuccess('Contribution', 'create', array(
      'contact_id' => $this->contactID2,
      'financial_type_id' => 'Cash',
      'total_amount' => 5,
      'currencty' => 'USD',
    ));
    $contact = $this->callAPISuccess('Contact', 'get', array(
      'id' => $this->contactID,
      'sequential' => 1,
      'return' => array(wmf_civicrm_get_custom_field_name('lifetime_usd_total'), wmf_civicrm_get_custom_field_name('do_not_solicit')),
    ));
    $this->assertEquals(10, $contact['values'][0][wmf_civicrm_get_custom_field_name('lifetime_usd_total')]);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', array(
      'criteria' => array('contact' => array('id' => array('IN' => array($this->contactID, $this->contactID2)))),
    ));
    $this->assertEquals(1, count($result['values']['merged']));
    $contact = $this->callAPISuccess('Contact', 'get', array(
      'id' => $this->contactID,
      'sequential' => 1,
      'return' => array(wmf_civicrm_get_custom_field_name('lifetime_usd_total'), wmf_civicrm_get_custom_field_name('do_not_solicit')),
    ));
    $this->assertEquals(15, $contact['values'][0][wmf_civicrm_get_custom_field_name('lifetime_usd_total')]);
    $this->assertEquals(1, $contact['values'][0][wmf_civicrm_get_custom_field_name('do_not_solicit')]);

    // Now lets check the one to be deleted has a do_not_solicit = 0.
    $this->callAPISuccess('Contact', 'create', array(
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email' => 'the_don@duckland.com',
      wmf_civicrm_get_custom_field_name('do_not_solicit') => 0,
    ));
    $result = $this->callAPISuccess('Job', 'process_batch_merge', array(
      'criteria' => array('contact' => array('id' => $this->contactID)),
    ));
    $this->assertEquals(1, count($result['values']['merged']));
    $contact = $this->callAPISuccess('Contact', 'get', array(
      'id' => $this->contactID,
      'sequential' => 1,
      'return' => array(wmf_civicrm_get_custom_field_name('lifetime_usd_total'), wmf_civicrm_get_custom_field_name('do_not_solicit')),
    ));
    $this->assertEquals(1, $contact['values'][0][wmf_civicrm_get_custom_field_name('do_not_solicit')]);
  }

}
