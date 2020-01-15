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
 * @group headless
 */
class OmnigroupmemberLoadTest extends OmnimailBaseTestClass {

  public function tearDown() {
    $this->cleanupGroup(NULL, 'Omnimailers');
    $this->cleanupGroup(NULL, 'Omnimailers2');
    $this->cleanupGroup(NULL, 'Omnimailers3');
    parent::tearDown();
  }

  /**
   * Example: the groupMember load fn works.
   */
  public function testOmnigroupmemberLoad() {
    $client = $this->setupSuccessfulDownloadClient();
    $group = $this->callAPISuccess('Group', 'create', ['name' => 'Omnimailers', 'title' => 'Omni']);

    $this->callAPISuccess('Omnigroupmember', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Shrek',
      'password' => 'Fiona',
      'options' => ['limit' => 3],
      'client' => $client,
      'group_identifier' => 123,
      'group_id' => $group['id'],
    ]);
    $groupMembers = $this->callAPISuccess('GroupContact', 'get', ['group_id' => $group['id']]);
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
    $group = $this->callAPISuccess('Group', 'create', ['name' => 'Omnimailers', 'title' => 'Omni']);

    $this->callAPISuccess('Omnigroupmember', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Shrek',
      'password' => 'Fiona',
      'options' => ['offset' => 1],
      'client' => $client,
      'group_identifier' => 123,
      'group_id' => $group['id'],
    ]);
    $groupMembers = $this->callAPISuccess('GroupContact', 'get', ['group_id' => $group['id']]);
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
    $group = $this->callAPISuccess('Group', 'create', ['name' => 'Omnimailers', 'title' => 'Omni']);

    $this->callAPISuccess('Omnigroupmember', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Shrek',
      'password' => 'Fiona',
      'options' => ['limit' => 1],
      'client' => $client,
      'group_identifier' => 123,
      'group_id' => $group['id'],
    ]);
    $groupMembers = $this->callAPISuccess('GroupContact', 'get', ['group_id' => $group['id']]);
    $this->assertEquals(1, $groupMembers['count']);
    $contacts = $this->getGroupMemberDetails($groupMembers);
    $this->assertEquals('eric@example.com', $contacts['values'][0]['email']);

    // Re-run. Offset is now 1 in settings & we are passing in limit =1. Sarah should be created.
    $client = $this->setupSuccessfulDownloadClient(FALSE);
    $this->callAPISuccess('Omnigroupmember', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Shrek',
      'password' => 'Fiona',
      'options' => ['limit' => 1],
      'client' => $client,
      'group_identifier' => 123,
      'group_id' => $group['id'],
    ]);
    $groupMembers = $this->callAPISuccess('GroupContact', 'get', ['group_id' => $group['id']]);
    $this->assertEquals(2, $groupMembers['count']);
    $contacts = $this->getGroupMemberDetails($groupMembers);
    $this->assertEquals('sarah@example.com', $contacts['values'][1]['email']);
    $this->cleanupGroup($group);

    $this->assertEquals([
      'last_timestamp' => '2017-02-23 23:00:00',
      'offset' => 2,
      'retrieval_parameters' => [
        'jobId' => '101719657',
        'filePath' => '/download/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv',
      ],
      'progress_end_timestamp' => '2017-03-02 23:00:00',
    ], $this->getUtcDateFormattedJobSettings());

  }

  /**
   * Test when download does not complete in time.
   */
  public function testOmnigroupmemberLoadIncomplete() {
    $this->createSetting([
      'job' => 'omnimail_omnigroupmembers_load',
      'mailing_provider' => 'Silverpop',
      'last_timestamp' => '1487890800',
    ]);

    $this->callAPISuccess('Setting', 'create',  ['omnimail_job_default_time_interval' => '7 days']);
    $responses = [
      file_get_contents(__DIR__ . '/Responses/ExportListResponse.txt'),
    ];
    for ($i = 0; $i < 15; $i++) {
      $responses[] = file_get_contents(__DIR__ . '/Responses/JobStatusWaitingResponse.txt');
    }
    $this->callAPISuccess('setting', 'create', ['omnimail_job_retry_interval' => 0.01]);
    $group = $this->callAPISuccess('Group', 'create', ['name' => 'Omnimailers2', 'title' => 'Omni2']);

    $this->callAPISuccess('Omnigroupmember', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Donald',
      'password' => 'Duck',
      'client' => $this->getMockRequest($responses),
      'group_identifier' => 123,
      'group_id' => $group['id'],
    ]);

    $groupMembers = $this->callAPISuccess('GroupContact', 'get', ['group_id' => $group['id']]);
    $this->assertEquals(0, $groupMembers['count']);

    $this->assertEquals([
      'last_timestamp' => '2017-02-23 23:00:00',
      'retrieval_parameters' => [
        'jobId' => '101719657',
        'filePath' => '/download/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv',
      ],
      'progress_end_timestamp' => '2017-03-02 23:00:00',
      'offset' => 0,
    ], $this->getUtcDateFormattedJobSettings());
  }

  /**
   * Test when download does not complete in time.
   */
  public function testOmnigroupmemberLoadIncompleteUseSuffix() {
    $this->createSetting([
      'job' => 'omnimail_omnigroupmembers_load',
      'mailing_provider' => 'Silverpop',
      'job_identifier' => '_woot',
      'last_timestamp' => '1487890800',
    ]);
    $responses = [
      file_get_contents(__DIR__ . '/Responses/ExportListResponse.txt'),
    ];
    for ($i = 0; $i < 15; $i++) {
      $responses[] = file_get_contents(__DIR__ . '/Responses/JobStatusWaitingResponse.txt');
    }
    $this->callAPISuccess('setting', 'create', ['omnimail_job_retry_interval' => 0.01]);
    $group = $this->callAPISuccess('Group', 'create', ['name' => 'Omnimailers2', 'title' => 'Omni2']);

    $this->callAPISuccess('Omnigroupmember', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Donald',
      'password' => 'Duck',
      'client' => $this->getMockRequest($responses),
      'group_identifier' => 123,
      'group_id' => $group['id'],
      'job_identifier' => '_woot',
    ]);

    $groupMembers = $this->callAPISuccess('GroupContact', 'get', ['group_id' => $group['id']]);
    $this->assertEquals(0, $groupMembers['count']);

    $this->assertEquals([
      'last_timestamp' => '2017-02-23 23:00:00',
      'retrieval_parameters' => [
        'jobId' => '101719657',
        'filePath' => '/download/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv',
      ],
      'progress_end_timestamp' => '2017-03-02 23:00:00',
      'offset' => 0,
    ], $this->getUtcDateFormattedJobSettings(['mail_provider' => 'Silverpop', 'job_identifier' => '_woot']));
    $this->cleanupGroup($group);
  }

  /**
   * After completing an incomplete download the end date should be the progress end date.
   */
  public function testCompleteIncomplete() {
    $client = $this->setupSuccessfulDownloadClient(FALSE);
    $group = $this->callAPISuccess('Group', 'create', ['name' => 'Omnimailers3', 'title' => 'Omni3']);
    $this->createSetting([
      'job' => 'omnimail_omnigroupmembers_load',
      'mailing_provider' => 'Silverpop',
      'last_timestamp' => '1487890800',
      'retrieval_parameters' => [
        'jobId' => '101719657',
        'filePath' => '/download/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv',
      ],
      'progress_end_timestamp' => '1488150000',
    ]);

    $this->callAPISuccess('Omnigroupmember', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Shrek',
      'password' => 'Fiona',
      'options' => ['limit' => 3],
      'client' => $client,
      'group_identifier' => 123,
      'group_id' => $group['id'],
    ]);

    $groupMembers = $this->callAPISuccess('GroupContact', 'get', ['group_id' => $group['id']]);
    $this->assertEquals(3, $groupMembers['count']);

    $this->assertEquals([
      'last_timestamp' => '2017-03-02 23:00:00',
    ], $this->getUtcDateFormattedJobSettings(['mail_provider' => 'Silverpop']));
    $this->cleanupGroup($group);
  }

  /**
   * Set up the mock client to emulate a successful download.
   *
   * @param bool $isUpdateSetting
   *
   * @return \GuzzleHttp\Client
   */
  protected function setupSuccessfulDownloadClient($isUpdateSetting = TRUE) {
    $responses = [
      file_get_contents(__DIR__ . '/Responses/ExportListResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/JobStatusCompleteResponse.txt'),
    ];
    copy(__DIR__ . '/Responses/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv', sys_get_temp_dir() . '/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv');
    fopen(sys_get_temp_dir() . '/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv.complete', 'c');
    if ($isUpdateSetting) {
      $this->createSetting(['job' => 'omnimail_omnigroupmembers_load', 'mailing_provider' => 'Silverpop', 'last_timestamp' => '1487890800']);
    }

    return $this->getMockRequest($responses);
  }

  /**
   * Get job settings.
   *
   * @param array $params
   *
   * @return array
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function getJobSettings($params = ['mail_provider' => 'Silverpop']): array {
    $omnimail = new CRM_Omnimail_Omnigroupmembers($params);
    $result = $omnimail->getJobSettings();
    unset($result['id'], $result['mailing_provider'], $result['job'], $result['job_identifier']);
    return $result;
  }

  /**
   * @param $group
   *
   * @param null|string $name
   */
  protected function cleanupGroup($group, $name = NULL) {
    if ($name) {
      $group = $this->callAPISuccess('Group', 'get', ['name' => $name])['values'];
      if (empty($group)) {
        return;
      }
      $group = reset($group);
    }
    $this->callAPISuccess('GroupContact', 'get', [
      'group_id' => $group['id'],
      'api.contact.delete' => ['skip_undelete' => 1],
    ]);
    $this->callAPISuccess('Group', 'delete', ['id' => $group['id']]);

  }

  /**
   * @param $groupMembers
   *
   * @return array
   */
  protected function getGroupMemberDetails($groupMembers): array {
    $contactIDs = ['IN' => []];
    foreach ($groupMembers['values'] as $groupMember) {
      $contactIDs['IN'][] = $groupMember['contact_id'];
    }
    $contacts = $this->callAPISuccess('Contact', 'get', [
      'contact_id' => $contactIDs,
      'sequential' => 1,
      'return' => [
        'contact_source',
        'email',
        'country',
        'created_date',
        'preferred_language',
        'is_opt_out',
      ],
    ]);
    return $contacts;
  }

}
