<?php

class BaseChecksFileTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Gateway.
   *
   * eg. benevity, engage etc.
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

  /**
   * ID of the database anonymous contact.
   *
   * This contact can be regarded as site metadata
   * so does not need to be removed afterwards.
   *
   * It is used during imports.
   *
   * @var int
   */
  protected $anonymousContactID;

  public function setUp(): void {
    $this->ensureAnonymousContactExists();
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
  public function tearDown(): void {
    $this->doCleanUp();
    parent::tearDown();
  }

  /**
   * Clean up transactions from previous test runs.
   */
  public function doCleanUp(): void {
    $contributions = [];
    if ($this->trxn_id) {
      // Any that are already removed will be FALSE and filtered out by array filter.
      $contributions = array_filter((array) wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $this->trxn_id));
    }
    elseif (!empty($this->trxn_ids)) {
      foreach ($this->trxn_ids as $trxn_id) {
        $contributions = array_merge($contributions, array_filter((array) wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $trxn_id)));
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
  protected function doMouseHunt(): void {
    $traditionalMouseNames = [
      'mickey@mouse.com',
      'Mickey Mouse',
      'foo@example.com',
      // This anonymous is created in the wmf_civicrm module,
      // not to be confused with import-specific anonymous
      // who might be understood as site metadata.
      'Anonymous',
      // Ducks are mice too.
      'Daisy Duck',
      // As are paranormal investigators
      'Fox Mulder',
      'Satoshi Nakamoto',
      'fox.mulder.doppelganger@pm.me',
      // It is well known mice that evolved from scientists
      'Charles Darwin',
      'Marie Currie',
    ];
    CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_contact WHERE display_name IN ("'
      . implode('","', $traditionalMouseNames)
      . '")'
    );
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
    $this->anonymousContactID = $contacts['id'];
  }

}
