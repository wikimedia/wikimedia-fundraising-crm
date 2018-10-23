<?php

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;
use SilverpopConnector\SilverpopRestConnector;

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
class OmnirecipientForgetmeTest extends OmnimailBaseTestClass implements EndToEndInterface, TransactionalInterface {

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
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_provider_data WHERE contact_id IN (' . implode(',', $this->contactIDs) . ')');
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_omnimail_job_progress WHERE job_identifier = \'["charlie@example.com"]\'');
    parent::tearDown();
  }

  /**
   * Test forgetme function.
   */
  public function testForgetme() {
    $this->makeScientists();
    $this->createMailingProviderData();
    $this->setUpForErase();
    $this->addTestClientToRestSingleton();

    $this->assertEquals(1, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs['charlie_clone']]));

    $settings = $this->setDatabaseID([50]);
    $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->contactIDs['charlie_clone']]);
    $this->callAPISuccess('Omnirecipient', 'process_forgetme', ['mail_provider' => 'Silverpop']);

    $this->assertEquals(0, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs['charlie_clone']]));

    // Check the job has retrieval parameters to try again later.
    $omniJobParams = ['job' => 'omnimail_privacy_erase', 'job_identifier' => '["charlie@example.com"]', 'mailing_provider' => 'Silverpop'];
    $omniJob = $this->callAPISuccessGetSingle('OmnimailJobProgress', $omniJobParams);
    $this->assertEquals('{"fetch_url":"https:\/\/api4.ibmmarketingcloud.com\/rest\/gdpr_jobs\/662\/status","database_id":"50"}', $omniJob['retrieval_parameters']);

    $this->setUpForEraseFollowUpSuccess();
    $this->addTestClientToRestSingleton();
    // This time it was complete - entry should be gone.
    $this->callAPISuccess('Omnirecipient', 'process_forgetme', ['mail_provider' => 'Silverpop']);
    $this->callAPISuccessGetCount('OmnimailJobProgress', $omniJobParams, 0);

    // Check the request retried our url
    $requests = $this->getRequestUrls();
    $this->assertEquals('https://api4.ibmmarketingcloud.com/rest/gdpr_jobs/662/status', $requests[1]);
    // The job should be deleted due to having succeeded
    $this->callAPISuccessGetCount('OmnimailJobProgress', $omniJobParams, 0);
    Civi::settings()->set('omnimail_credentials', $settings);
  }

  /**
   * Test forgetme function when there is no recipient data.
   *
   * We should still send a rest request.
   */
  public function testForgetmeNoRecipientData() {
    $this->makeScientists();
    $this->setUpForErase();
    $this->addTestClientToRestSingleton();
    $settings = $this->setDatabaseID([50]);

    $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->contactIDs['charlie_clone']]);
    $this->callAPISuccess('Omnirecipient', 'process_forgetme', ['mail_provider' => 'Silverpop']);

    // Check the request we sent out had the right email in it.
    $requests = $this->getRequestBodies();
    $this->assertEquals($requests[1], "Email,charlie@example.com\n", print_r($requests, 1));
    Civi::settings()->set('omnimail_credentials', $settings);
  }

  /**
   * Add our mock client to the rest singleton.
   *
   * In other places we have been passing the client in but we can't do that
   * here so trying a new tactic  - basically setting it up on the singleton
   * first.
   */
  protected function addTestClientToRestSingleton() {
    $restConnector = SilverpopRestConnector::getInstance();
    $this->setUpClientWithHistoryContainer();
    $restConnector->setClient($this->getGuzzleClient());
  }

  /**
   * Ensure there is a database id setting.
   *
   * @param array $databaseIDs
   *
   * @return array
   *   Settings prior to change
   */
  protected function setDatabaseID($databaseIDs = [50]) {
    $settings = Civi::settings()->get('omnimail_credentials');
    // This won't actually work if settings is set in civicrm.settings.php but will be used by CI
    // which now will skip erase if it doesn't have any database_id
    Civi::settings()
      ->set('omnimail_credentials', ['Silverpop' => array_merge(CRM_Utils_Array::value('Silverpop', $settings, []), ['database_id' => $databaseIDs])]);
    return $settings;
  }

}
