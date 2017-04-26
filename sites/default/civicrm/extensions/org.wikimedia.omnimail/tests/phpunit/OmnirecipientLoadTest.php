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
class OmnirecipientLoadTest extends OmnimailBaseTestClass implements EndToEndInterface, TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::e2e()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_provider_data');
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnirecipientLoad() {
    $client = $this->setupSuccessfulDownloadClient();

    civicrm_api3('Omnirecipient', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'debug' => 1, 'client' => $client));
    $providers = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_mailing_provider_data')->fetchAll();
    $this->assertEquals(array(
      0 => array(
        'contact_identifier' => '126312673126',
        'mailing_identifier' => '54132674',
        'email' => 'sarah@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-06-30 23:32:00',
        'contact_id' => '',
        'is_civicrm_updated' => '0',
      ),
      1 => array(
        'contact_identifier' => '15915939159',
        'mailing_identifier' => '54132674',
        'email' => 'cliff@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-06-30 23:32:00',
        'contact_id' => '',
        'is_civicrm_updated' => '0',
      ),
      2 => array(
        'contact_identifier' => '248248624848',
        'mailing_identifier' => '54132674',
        'email' => 'bob@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-06-30 23:32:00',
        'contact_id' => '123',
        'is_civicrm_updated' => '0',
      ),
      3 => array(
        'contact_identifier' => '508505678505',
        'mailing_identifier' => '54132674',
        'email' => 'steve@example.com',
        'event_type' => 'Open',
        'recipient_action_datetime' => '2017-07-01 17:28:00',
        'contact_id' => '456',
        'is_civicrm_updated' => '0',
      ),
    ), $providers);
    $this->assertEquals(array ('last_timestamp' => '1488495600'), $this->getJobSettings());

  }

  /**
   * Test when download does not complete in time.
   */
  public function testOmnirecipientLoadIncomplete() {
    civicrm_api3('Setting', 'create', array(
      'omnimail_omnirecipient_load' => array(
        'Silverpop' => array('last_timestamp' => '1487890800'),
      ),
    ));
    $responses = array(
      file_get_contents(__DIR__ . '/Responses/RawRecipientDataExportResponse.txt'),
    );
    for ($i = 0; $i < 15; $i++) {
      $responses[] = file_get_contents(__DIR__ . '/Responses/JobStatusWaitingResponse.txt');
    }
    civicrm_api3('setting', 'create', array('omnimail_job_retry_interval' => 0.01));
    civicrm_api3('Omnirecipient', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'client' => $this->getMockRequest($responses)));
    $this->assertEquals(0, CRM_Core_DAO::singleValueQuery('SELECT  count(*) FROM civicrm_mailing_provider_data'));

    $this->assertEquals(array(
      'last_timestamp' => '1487890800',
      'retrieval_parameters' => array(
      'jobId' => '101569750',
      'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
      ),
      'progress_end_date' => '1488495600',
    ), $this->getJobSettings());
  }

  /**
   * After completing an incomplete download the end date should be the progress end date.
   */
  public function testCompleteIncomplete() {
    $client = $this->setupSuccessfulDownloadClient();
    civicrm_api3('setting', 'create', array(
      'omnimail_omnirecipient_load' => array(
        'Silverpop' => array(
          'last_timestamp' => '1487890800',
          'retrieval_parameters' => array(
            'jobId' => '101569750',
            'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
          ),
          'progress_end_date' => '1488495600',
        ),
      ),
    ));

    civicrm_api3('Omnirecipient', 'load', array('mail_provider' => 'Silverpop', 'username' => 'Donald', 'password' => 'Duck', 'client' => $client));
    $this->assertEquals(4, CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_provider_data'));
    $this->assertEquals(array(
      'last_timestamp' => '1488495600',
    ), $this->getJobSettings(array('mail_provider' => 'Silverpop')));
  }

  /**
   * An exception should be thrown if the download is incomplete & we pass in a timestamp.
   *
   * This is because the incomplete download will continue, and we will incorrectly
   * think it is taking our parameters.
   *
   */
  public function testIncompleteRejectTimestamps() {
    civicrm_api3('setting', 'create', array(
      'omnimail_omnirecipient_load' => array(
        'Silverpop' => array(
          'last_timestamp' => '1487890800',
          'retrieval_parameters' => array(
            'jobId' => '101569750',
            'filePath' => 'Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.zip',
          ),
          'progress_end_date' => '1488495600',
        ),
      ),
    ));
    try {
      civicrm_api3('Omnirecipient', 'load', array(
        'mail_provider' => 'Silverpop',
        'start_date' => 'last week',
        'username' => 'Donald',
        'password' => 'Duck',
        'client' => $this->getMockRequest(array())
      ));
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
   * @return array
   */
  public function getJobSettings() {
    $omnimail = new CRM_Omnimail_Omnirecipients();
    return $omnimail->getJobSettings(array('mail_provider' => 'Silverpop'));
  }
}
