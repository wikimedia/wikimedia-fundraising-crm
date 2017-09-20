<?php

use Civi\Test\EndToEndInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
require_once __DIR__ . '/OmnimailBaseTestClass.php';

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
 * @group e2e
 */
class OmnigroupmemberLoadTest extends OmnimailBaseTestClass implements EndToEndInterface, TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::e2e()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnigroupmemberLoad() {
    $client = $this->setupSuccessfulDownloadClient();
    $group = civicrm_api3('Group', 'create', array('name' => 'Omnimailers', 'title' => 'Omni'));

    civicrm_api3('Omnigroupmember', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Shrek', 'password' => 'Fiona', 'options' => array('limit' => 3), 'client' => $client, 'group_identifier' => 123, 'group_id' => $group['id']));
    $groupMembers = civicrm_api3('GroupContact', 'get', array('group_id' => $group['id']));
    $this->assertEquals(3, $groupMembers['count']);
    $contacts = $this->getGroupMemberDetails($groupMembers);
    $this->assertEquals('fr_FR', $contacts['values'][0]['preferred_language']);
    $this->assertEquals('eric@example.com', $contacts['values'][0]['email']);
    $this->assertEquals('France', $contacts['values'][0]['country']);
    $this->assertEquals(1, $contacts['values'][0]['is_opt_out']);
    $this->assertEquals('Silverpop Added by WebForms 10/18/16', $contacts['values'][0]['contact_source']);

    $this->assertEquals('Silverpop clever place 07/04/17', $contacts['values'][2]['contact_source']);
    $this->cleanupGroup($group);
  }

  /**
   * Example: Test that offset is respected.
   */
  public function testOmnigroupmemberLoadOffset() {
    $client = $this->setupSuccessfulDownloadClient();
    $group = civicrm_api3('Group', 'create', array('name' => 'Omnimailers', 'title' => 'Omni'));

    civicrm_api3('Omnigroupmember', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Shrek', 'password' => 'Fiona', 'options' => array('offset' => 1), 'client' => $client, 'group_identifier' => 123, 'group_id' => $group['id']));
    $groupMembers = civicrm_api3('GroupContact', 'get', array('group_id' => $group['id']));
    $this->assertEquals(2, $groupMembers['count']);
    $contacts = $this->getGroupMemberDetails($groupMembers);
    $this->assertEquals('sarah@example.com', $contacts['values'][0]['email']);
    $this->assertEquals('lisa@example.com', $contacts['values'][1]['email']);
    $this->cleanupGroup($group);
  }

  /**
   * Example: Test that offset is respected.
   */
  public function testOmnigroupmemberLoadUseOffsetSetting() {
    $client = $this->setupSuccessfulDownloadClient();
    $group = civicrm_api3('Group', 'create', array('name' => 'Omnimailers', 'title' => 'Omni'));

    civicrm_api3('Omnigroupmember', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Shrek', 'password' => 'Fiona', 'options' => array('limit' => 1), 'client' => $client, 'group_identifier' => 123, 'group_id' => $group['id']));
    $groupMembers = civicrm_api3('GroupContact', 'get', array('group_id' => $group['id']));
    $this->assertEquals(1, $groupMembers['count']);
    $contacts = $this->getGroupMemberDetails($groupMembers);
    $this->assertEquals('eric@example.com', $contacts['values'][0]['email']);

    // Re-run. Offset is now 1 in settings & we are passing in limit =1. Sarah should be created.
    $client = $this->setupSuccessfulDownloadClient(FALSE);
    civicrm_api3('Omnigroupmember', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Shrek', 'password' => 'Fiona', 'options' => array('limit' => 1), 'client' => $client, 'group_identifier' => 123, 'group_id' => $group['id']));
    $groupMembers = civicrm_api3('GroupContact', 'get', array('group_id' => $group['id']));
    $this->assertEquals(2, $groupMembers['count']);
    $contacts = $this->getGroupMemberDetails($groupMembers);
    $this->assertEquals('sarah@example.com', $contacts['values'][1]['email']);
    $this->cleanupGroup($group);

    $this->assertEquals(array(
      'last_timestamp' => '1487890800',
      'progress_end_date' => '1488495600',
      'offset' => 2,
      'retrieval_parameters' => array(
        'jobId' => '101719657',
        'filePath' => '/download/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv',
      ),
    ), $this->getJobSettings());

  }

  /**
   * Test when download does not complete in time.
   */
  public function testOmnigroupmemberLoadIncomplete() {
    civicrm_api3('Setting', 'create', array(
      'omnimail_omnigroupmembers_load' => array(
        'Silverpop' => array('last_timestamp' => '1487890800'),
      ),
    ));
    $responses = array(
      file_get_contents(__DIR__ . '/Responses/ExportListResponse.txt'),
    );
    for ($i = 0; $i < 15; $i++) {
      $responses[] = file_get_contents(__DIR__ . '/Responses/JobStatusWaitingResponse.txt');
    }
    civicrm_api3('setting', 'create', array('omnimail_job_retry_interval' => 0.01));
    $group = civicrm_api3('Group', 'create', array('name' => 'Omnimailers2', 'title' => 'Omni2'));

    civicrm_api3('Omnigroupmember', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'client' => $this->getMockRequest($responses), 'group_identifier' => 123, 'group_id' => $group['id']));

    $groupMembers = civicrm_api3('GroupContact', 'get', array('group_id' => $group['id']));
    $this->assertEquals(0, $groupMembers['count']);

    $this->assertEquals(array(
      'last_timestamp' => '1487890800',
      'retrieval_parameters' => array(
        'jobId' => '101719657',
        'filePath' => '/download/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv',
      ),
      'progress_end_date' => '1488495600',
      'offset' => 0,
    ), $this->getJobSettings());
    $this->cleanupGroup($group);
  }

  /**
   * After completing an incomplete download the end date should be the progress end date.
   */
  public function testCompleteIncomplete() {
    $client = $this->setupSuccessfulDownloadClient();
    $group = civicrm_api3('Group', 'create', array('name' => 'Omnimailers3', 'title' => 'Omni3'));
    civicrm_api3('setting', 'create', array(
      'omnimail_omnigroupmembers_load' => array(
        'Silverpop' => array(
          'last_timestamp' => '1487890800',
          'retrieval_parameters' => array(
            'jobId' => '101719657',
            'filePath' => '/download/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv',
          ),
          'progress_end_date' => '1488150000',
        ),
      ),
    ));

    civicrm_api3('Omnigroupmember', 'load', array(
      'mail_provider' => 'Silverpop',
      'username' => 'Shrek',
      'password' => 'Fiona',
      'options' => array('limit' => 3),
      'client' => $client,
      'group_identifier' => 123,
      'group_id' => $group['id'],
     ));

    $groupMembers = civicrm_api3('GroupContact', 'get', array('group_id' => $group['id']));
    $this->assertEquals(3, $groupMembers['count']);

    $this->assertEquals(array(
      'last_timestamp' => '1488495600',
    ), $this->getJobSettings(array('mail_provider' => 'Silverpop')));
    $this->cleanupGroup($group);
  }

  /**
   * Set up the mock client to emulate a successful download.
   * @param bool $isUpdateSetting
   *
   * @return \GuzzleHttp\Client
   */
  protected function setupSuccessfulDownloadClient($isUpdateSetting = TRUE) {
    $responses = array(
      file_get_contents(__DIR__ . '/Responses/ExportListResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/JobStatusCompleteResponse.txt'),
    );
    copy(__DIR__ . '/Responses/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv', sys_get_temp_dir() . '/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv');
    fopen(sys_get_temp_dir() . '/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv.complete', 'c');
    if ($isUpdateSetting) {
      $this->createSetting('omnimail_omnigroupmembers_load', array('Silverpop' => array('last_timestamp' => '1487890800')));
    }

    $client = $this->getMockRequest($responses);
    return $client;
  }

  /**
   * Get job settings.
   *
   * @return array
   */
  public function getJobSettings() {
    $omnimail = new CRM_Omnimail_Omnigroupmembers(array('mail_provider' => 'Silverpop'));
    return $omnimail->getJobSettings();
  }

  /**
   * @param $group
   */
  protected function cleanupGroup($group) {
    civicrm_api3('GroupContact', 'get', array(
      'group_id' => $group['id'],
      'api.contact.delete' => array('skip_undelete' => 1),
    ));
    civicrm_api3('Group', 'delete', array('id' => $group['id']));

  }

  /**
   * @param $groupMembers
   * @return array
   */
  protected function getGroupMemberDetails($groupMembers) {
    $contactIDs = array('IN' => array());
    foreach ($groupMembers['values'] as $groupMember) {
      $contactIDs['IN'][] = $groupMember['contact_id'];
    }
    $contacts = civicrm_api3('Contact', 'get', array(
      'contact_id' => $contactIDs,
      'sequential' => 1,
      'return' => array(
        'contact_source',
        'email',
        'country',
        'created_date',
        'preferred_language',
        'is_opt_out'
      )
    ));
    return $contacts;
  }

}
