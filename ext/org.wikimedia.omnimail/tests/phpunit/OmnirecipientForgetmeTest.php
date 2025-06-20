<?php

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

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
class OmnirecipientForgetmeTest extends OmnimailBaseTestClass {

  public function tearDown(): void {
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_omnimail_job_progress WHERE job_identifier = \'["charlie@example.com"]\'');
    parent::tearDown();
  }

  /**
   * Test forgetme function.
   */
  public function testForgetme(): void {
    $this->makeScientists();
    $this->createMailingProviderData();
    $this->setUpForErase();
    $this->addTestClientToRestSingleton();

    $this->assertEquals(1, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->ids['Contact']['charlie_clone']]));

    $settings = $this->setDatabaseID([50]);
    $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->ids['Contact']['charlie_clone']]);
    $this->callAPISuccess('Omnirecipient', 'process_forgetme', ['mail_provider' => 'Silverpop', 'retry_delay' => 0]);

    $this->assertEquals(0, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->ids['Contact']['charlie_clone']]));

    // Check the job has retrieval parameters to try again later.
    $omniJobParams = ['job' => 'omnimail_privacy_erase', 'job_identifier' => '["charlie@example.com"]', 'mailing_provider' => 'Silverpop'];
    $omniJob = $this->callAPISuccessGetSingle('OmnimailJobProgress', $omniJobParams);
    $this->assertEquals('{"fetch_url":"https:\/\/api4.ibmmarketingcloud.com\/rest\/gdpr_jobs\/662\/status","database_id":"50"}', $omniJob['retrieval_parameters']);

    $this->setUpForEraseFollowUpSuccess();
    $this->addTestClientToRestSingleton();
    // This time it was complete - entry should be gone.
    $this->callAPISuccess('Omnirecipient', 'process_forgetme', ['mail_provider' => 'Silverpop', 'retry_delay' => 0]);
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
  public function testForgetmeNoRecipientData(): void {
    $this->makeScientists();
    $this->setUpForErase();
    $this->addTestClientToRestSingleton();
    $settings = $this->setDatabaseID([50]);

    $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->ids['Contact']['charlie_clone']]);
    $this->callAPISuccess('Omnirecipient', 'process_forgetme', ['mail_provider' => 'Silverpop', 'retry_delay' => 0]);

    // Check the request we sent out had the right email in it.
    $requests = $this->getRequestBodies();
    $this->assertEquals("Email,charlie@example.com\n", $requests[1], print_r($requests, 1));
    Civi::settings()->set('omnimail_credentials', $settings);
  }

}
