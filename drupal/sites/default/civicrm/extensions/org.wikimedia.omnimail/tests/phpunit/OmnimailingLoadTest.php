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

  protected $isOmniHellEnabled = TRUE;

  /**
   * Test that Mailings load using the Omnimailing.load api.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnimailingLoad() {
    $mailings = $this->loadMailings();
    $this->assertEquals(2, $mailings['count']);
    $mailing = $this->callAPISuccess('Mailing', 'getsingle', array('hash' => 'sp7877'));
    $this->assertEquals(1, $mailing['is_completed']);

    $this->loadMailings();

    $mailingReloaded = $this->callAPISuccess('Mailing', 'getsingle', array('hash' => 'sp7877'));

    $customFieldID = $this->callAPISuccessGetValue('CustomField', ['name' => 'query_criteria', 'return' => 'id']);
    $this->assertEquals($mailingReloaded['id'], $mailing['id']);
    $this->assertEquals('WHEN (COUNTRY is equal to IL AND ISOLANG is equal to HE AND LATEST_DONATION_DATE is before JAN 1, 2019 AND EMAIL_DOMAIN_PART is not equal to one of the following (AOL.COM | NETSCAPE.COM | NETSCAPE.NET | CS.COM | AIM.COM | WMCONNECT.COM | VERIZON.NET) OR (EMAIL is equal to FUNDRAISINGEMAIL-JAJP+HEIL@WIKIMEDIA.ORG AND COUNTRY is equal to IL)) AND SEGMENT is equal to 2', $mailingReloaded['custom_' . $customFieldID]);
    $mailingJobs = $this->callAPISuccess('MailingJob', 'get', array('mailing_id' => $mailing['id']));
    $this->assertEquals(0, $mailingJobs['count']);

    // Reload with omnihell disabled. This means no query criteria will be retrieved.
    // We want to check that what was already there remains.
    $this->isOmniHellEnabled = FALSE;
    $this->loadMailings();
    $mailingReloaded = $this->callAPISuccess('Mailing', 'getsingle', array('hash' => 'sp7877'));
    $this->assertEquals('WHEN (COUNTRY is equal to IL AND ISOLANG is equal to HE AND LATEST_DONATION_DATE is before JAN 1, 2019 AND EMAIL_DOMAIN_PART is not equal to one of the following (AOL.COM | NETSCAPE.COM | NETSCAPE.NET | CS.COM | AIM.COM | WMCONNECT.COM | VERIZON.NET) OR (EMAIL is equal to FUNDRAISINGEMAIL-JAJP+HEIL@WIKIMEDIA.ORG AND COUNTRY is equal to IL)) AND SEGMENT is equal to 2', $mailingReloaded['custom_' . $customFieldID]);



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
      'password' => 'quack'
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
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
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
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse1.txt'),
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
    ];
  }

}
