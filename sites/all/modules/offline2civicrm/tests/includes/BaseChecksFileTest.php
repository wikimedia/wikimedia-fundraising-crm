<?php

class BaseChecksFileTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Gateway.
   *
   * eg. jpmorgan, paypal etc.
   *
   * @var string
   */
  protected $gateway;

  /**
   * Transaction id being worked with. This is combined with the gateway for
   * the civi trxn_id.
   *
   * @var string
   */
  protected $trxn_id;

  protected $epochtime;

  function setUp() {
    parent::setUp();
    civicrm_initialize();
    $this->epochtime = wmf_common_date_parse_string('2016-09-15');
  }

  /**
   * Test and remove some dynamic fields, to simplify test fixtures.
   */
  function stripSourceData(&$msg) {
    $this->assertEquals('direct', $msg['source_type']);
    $importerClass = str_replace('Test', 'Probe', get_class($this));
    $this->assertEquals("Offline importer: {$importerClass}", $msg['source_name']);
    $this->assertNotNull($msg['source_host']);
    $this->assertGreaterThan(0, $msg['source_run_id']);
    $this->assertNotNull($msg['source_version']);
    $this->assertGreaterThan(0, $msg['source_enqueued_time']);

    unset($msg['source_type']);
    unset($msg['source_name']);
    unset($msg['source_host']);
    unset($msg['source_run_id']);
    unset($msg['source_version']);
    unset($msg['source_enqueued_time']);
  }

  /**
   * Clean up after test runs.
   */
  public function tearDown() {
    $this->doCleanUp();
  }

  /**
   * Clean up transactions from previous test runs.
   */
  function doCleanUp() {
    $contributions = array();
    if ($this->trxn_id) {
      $contributions = wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $this->trxn_id);
    }
    elseif (!empty($this->trxn_ids)) {
      foreach ($this->trxn_ids as $trxn_id) {
        $contributions = array_merge($contributions, wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $trxn_id));
      }
    }
    if ($contributions) {
      foreach ($contributions as $contribution) {
        $this->callAPISuccess('Contribution', 'delete', array('id' => $contribution['id']));
      }
    }
    $this->doMouseHunt();
  }

  /**
   * Clean up previous runs.
   *
   * Also get rid of the nest.
   */
  protected function doMouseHunt() {
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_contact WHERE display_name = "Mickey Mouse"');
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_prevnext_cache');
  }

  /**
   * Make sure we have the anonymous contact - like the live DB.
   */
  protected function ensureAnonymousContactExists() {
    $anonymousParams = array(
      'first_name' => 'Anonymous',
      'last_name' => 'Anonymous',
      'email' => 'fakeemail@wikimedia.org',
      'contact_type' => 'Individual',
    );
    $contacts = $this->callAPISuccess('Contact', 'get', $anonymousParams);
    if ($contacts['count'] == 0) {
      $this->callAPISuccess('Contact', 'create', $anonymousParams);
    }
    $contacts = $this->callAPISuccess('Contact', 'get', $anonymousParams);
    $this->assertEquals(1, $contacts['count']);
  }

}
