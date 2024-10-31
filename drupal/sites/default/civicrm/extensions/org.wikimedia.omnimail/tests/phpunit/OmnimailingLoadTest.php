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
class OmnimailingLoadTest extends OmnimailBaseTestClass {

  protected $isOmniHellEnabled = FALSE;

  /**
   * Test that Mailings load using the Omnimailing.load api.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnimailingLoad(): void {
    $mailings = $this->loadMailings();
    $this->assertEquals(2, $mailings['count']);
    $mailing = $this->callAPISuccess('Mailing', 'getsingle', array('hash' => 'sp7877'));
    $this->assertEquals(1, $mailing['is_completed']);

    $this->loadMailings();

    $mailingReloaded = $this->callAPISuccess('Mailing', 'getsingle', array('hash' => 'sp7877'));
    $this->assertEquals($mailingReloaded['id'], $mailing['id']);
    $mailingJobs = $this->callAPISuccess('MailingJob', 'get', array('mailing_id' => $mailing['id']));
    $this->assertEquals(0, $mailingJobs['count']);
  }

  /**
   * Load mailings for test.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function loadMailings(): array {
    $responses = $this->isOmniHellEnabled ? $this->getWithHell() : $this->getWithoutHell();
    Civi::settings()->set('omnimail_omnihell_enabled', $this->isOmniHellEnabled);
    $mailings = $this->callAPISuccess('Omnimailing', 'load', array(
      'mail_provider' => 'Silverpop',
      'client' => $this->getMockRequest($responses),
      'username' => 'Donald',
      'password' => 'quack',
    ));
    return $mailings;
  }

  /**
   * Get the responses with Omnihell enabled.
   *
   * @return array
   */
  protected function getWithoutHell(): array {
    return [
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/LogoutResponse.txt'),
    ];
  }

  /**
   * Get the responses with Omnihell enabled.
   *
   * @return array
   */
  protected function getWithHell(): array {
    return [
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/LoginHtml.html'),
      '',
      file_get_contents(__DIR__ . '/Responses/QueryListHtml.html'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/LoginHtml.html'),
      '',
      file_get_contents(__DIR__ . '/Responses/QueryListHtml.html'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/LogoutResponse.txt'),
    ];
  }

}
