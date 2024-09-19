<?php

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
class OmnirecipientLoadTest extends OmnimailBaseTestClass {

  public function tearDown(): void {
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_provider_data');
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnirecipientLoad(): void {
    $client = $this->setupSuccessfulDownloadClient('omnimail_omnirecipient_load');

    $this->callAPISuccess('Omnirecipient', 'load', ['mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'debug' => 1, 'client' => $client]);
    $providers = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_mailing_provider_data')->fetchAll();
    $this->assertEquals([
      0 => [
        'contact_identifier' => '126312673126',
        'mailing_identifier' => 'sp54132674',
        'email' => 'sarah@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-06-30 23:32:00',
        'contact_id' => '',
        'is_civicrm_updated' => '0',
      ],
      1 => [
        'contact_identifier' => '15915939159',
        'mailing_identifier' => 'sp54132674',
        'email' => 'cliff@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-06-30 23:32:00',
        'contact_id' => '',
        'is_civicrm_updated' => '0',
      ],
      2 => [
        'contact_identifier' => '248248624848',
        'mailing_identifier' => 'sp54132674',
        'email' => 'bob@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-06-30 23:32:00',
        'contact_id' => '123',
        'is_civicrm_updated' => '0',
      ],
      3 => [
        'contact_identifier' => '508505678505',
        'mailing_identifier' => 'sp54132674',
        'email' => 'steve@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-07-01 17:28:00',
        'contact_id' => '456',
        'is_civicrm_updated' => '0',
      ],
    ], $providers);
    $this->assertEquals(['last_timestamp' => '2017-03-02 23:00:00'], $this->getUtcDateFormattedJobSettings());

  }

  /**
   * Test for no errors when using 'insert_batch_size' parameter.
   *
   * It's hard to test that it does batch, but at least we can check it
   * succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnirecipientLoadIncreasedBatchInsert(): void {
    $client = $this->setupSuccessfulDownloadClient('omnimail_omnirecipient_load');

    $this->callAPISuccess('Omnirecipient', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Donald',
      'password' => 'Duck',
      'debug' => 1,
      'client' => $client,
      'insert_batch_size' => 4,
    ]);
    $this->assertEquals(4, CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM civicrm_mailing_provider_data'));
  }

  /**
   * Test for no errors when using 'insert_batch_size' parameter.
   *
   * It's hard to test that it does batch, but at least we can check it
   * succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnirecipientLoadIncreasedBatchInsertExceedsAvailable(): void {
    $client = $this->setupSuccessfulDownloadClient('omnimail_omnirecipient_load');

    $this->callAPISuccess('Omnirecipient', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Donald',
      'password' => 'Duck',
      'debug' => 1,
      'client' => $client,
      'insert_batch_size' => 6,
    ]);
    $this->assertEquals(4, CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM civicrm_mailing_provider_data'));
  }

  /**
   * Example: Test that a version is returned.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnirecipientLoadLimitAndOffset(): void {
    $client = $this->setupSuccessfulDownloadClient('omnimail_omnirecipient_load');

    $this->callAPISuccess('Omnirecipient', 'load', [
      'mail_provider' => 'Silverpop',
      'username' => 'Donald',
      'password' => 'Duck',
      'debug' => 1,
      'client' => $client,
      'options' => ['limit' => 2, 'offset' => 1],
    ]);
    $providers = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_mailing_provider_data')->fetchAll();
    $this->assertEquals([
      0 => [
        'contact_identifier' => '126312673126',
        'mailing_identifier' => 'sp54132674',
        'email' => 'sarah@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => date('Y-m-d H:i:s', strtotime('2017-06-30 23:32:00 GMT')),
        'contact_id' => '',
        'is_civicrm_updated' => '0',
      ],
      1 => [
        'contact_identifier' => '15915939159',
        'mailing_identifier' => 'sp54132674',
        'email' => 'cliff@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => date('Y-m-d H:i:s', strtotime('2017-06-30 23:32:00 GMT')),
        'contact_id' => '',
        'is_civicrm_updated' => '0',
      ],
    ], $providers);

    $this->assertEquals([
      'last_timestamp' => '2017-02-23 23:00:00',
      'progress_end_timestamp' => '2017-03-02 23:00:00',
      'offset' => 3,
      'retrieval_parameters' => [
        'jobId' => '101569750',
        'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
      ],
    ], $this->getUtcDateFormattedJobSettings());

  }

  /**
   * Test when download does not complete in time.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnirecipientLoadIncomplete(): void {
    $this->createSetting([
      'job' => 'omnimail_omnirecipient_load',
      'mailing_provider' => 'Silverpop',
      'last_timestamp' => '1487890800',
    ]);
    $responses = [
      file_get_contents(__DIR__ . '/Responses/RawRecipientDataExportResponse.txt'),
    ];
    for ($i = 0; $i < 15; $i++) {
      $responses[] = file_get_contents(__DIR__ . '/Responses/JobStatusWaitingResponse.txt');
    }
    $responses[] = file_get_contents(__DIR__ . '/Responses/LogoutResponse.txt');
    $this->callAPISuccess('setting', 'create', ['omnimail_job_retry_interval' => 0.01]);
    $this->callAPISuccess('Omnirecipient', 'load', ['mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'client' => $this->getMockRequest($responses)]);
    $this->assertEquals(0, CRM_Core_DAO::singleValueQuery('SELECT  count(*) FROM civicrm_mailing_provider_data'));

    $this->assertEquals([
      'last_timestamp' => '2017-02-23 23:00:00',
      'retrieval_parameters' => [
        'jobId' => '101569750',
        'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
      ],
      'progress_end_timestamp' => '2017-03-02 23:00:00',
    ], $this->getUtcDateFormattedJobSettings());
  }

  /**
   * After completing an incomplete download the end date should be the
   * progress end date.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCompleteIncomplete(): void {
    $client = $this->setupSuccessfulDownloadClient('omnimail_omnirecipient_load');
    $now = time();
    $this->createSetting([
      'job' => 'omnimail_omnirecipient_load',
      'mailing_provider' => 'Silverpop',
      'last_timestamp' => strtotime('-1 hour', $now),
      'retrieval_parameters' => [
        'jobId' => '101569750',
        'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
      ],
      'progress_end_timestamp' => $now,
    ]);

    // We set $now above & then sleep because we want to check that last_time_stamp is set to $now not
    // a slightly later time that might evaluate to now().
    sleep(1);
    $this->callAPISuccess('Omnirecipient', 'load', ['mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'client' => $client]);
    $this->assertEquals(4, CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_provider_data'));
    $this->assertEquals([
      'last_timestamp' => date('Y-m-d H:i:s', $now),
    ], $this->getUtcDateFormattedJobSettings(['mail_provider' => 'Silverpop']));
  }

  /**
   * Test the suffix works for multiple jobs..
   *
   * @throws \CRM_Core_Exception
   */
  public function testCompleteIncompleteUseSuffix(): void {
    $client = $this->setupSuccessfulDownloadClient('omnimail_omnirecipient_load');
    $this->createSetting([
      'job' => 'omnimail_omnirecipient_load',
      'mailing_provider' => 'Silverpop',
      'last_timestamp' => '1487890800',
    ]);
    $this->createSetting([
      'job' => 'omnimail_omnirecipient_load',
      'job_identifier' => '_woot',
      'mailing_provider' => 'Silverpop',
      'last_timestamp' => '1487890800',
      'retrieval_parameters' => [
        'jobId' => '101569750',
        'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
      ],
      'progress_end_timestamp' => '1488495600',
    ]);
    $settings = $this->getJobSettings(['mail_provider' => 'Silverpop']);

    $this->callAPISuccess('Omnirecipient', 'load', ['mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'client' => $client, 'job_identifier' => '_woot']);
    $this->assertEquals(4, CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_provider_data'));
    $this->assertEquals([
      'last_timestamp' => '2017-03-02 23:00:00',
    ], $this->getUtcDateFormattedJobSettings(['mail_provider' => 'Silverpop', 'job_identifier' => '_woot']));

    $this->assertEquals($settings, $this->getJobSettings(['mail_provider' => 'Silverpop']));
  }


  /**
   * An exception should be thrown if the download is incomplete & we pass in a timestamp.
   *
   * This is because the incomplete download will continue, and we will incorrectly
   * think it is taking our parameters.
   *
   */
  public function testIncompleteRejectTimestamps(): void {
    $this->createSetting([
      'job' => 'omnimail_omnirecipient_load',
      'mailing_provider' => 'Silverpop',
      'last_timestamp' => '1487890800',
      'retrieval_parameters' => [
        'jobId' => '101569750',
        'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
      ],
      'progress_end_date' => '1488495600',
    ]);
    try {
      civicrm_api3('Omnirecipient', 'load', [
        // note we don't pass client in this test as
        // the exception is thrown before silverpop is instantiated.
        'mail_provider' => 'Silverpop',
        'start_date' => 'last week',
        'username' => 'Donald',
        'password' => 'Duck',
      ]);
    }
    catch (Exception $e) {
      $this->assertEquals('A prior retrieval is in progress. Do not pass in dates to complete a retrieval', $e->getMessage());
      return;
    }
    $this->fail('No exception');
  }

  /**
   * Get job settings.
   *
   * @param array $params
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function getJobSettings($params = ['mail_provider' => 'Silverpop']): array {
    $omnimail = new CRM_Omnimail_Omnirecipients($params);
    $result = $omnimail->getJobSettings();
    unset($result['id'], $result['mailing_provider'], $result['job'], $result['job_identifier']);
    return $result;
  }
}
