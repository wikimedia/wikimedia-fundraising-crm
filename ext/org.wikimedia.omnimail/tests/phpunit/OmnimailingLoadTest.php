<?php

require_once __DIR__ . '/OmnimailBaseTestClass.php';

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or
 * test****() functions will rollback automatically -- as long as you don't
 * manipulate schema or truncate tables. If this test needs to manipulate
 * schema or truncate tables, then either: a. Do all that using setupHeadless()
 * and Civi\Test. b. Disable TransactionalInterface, and handle all
 * setup/teardown yourself.
 *
 * @group headless
 */
class OmnimailingLoadTest extends OmnimailBaseTestClass {

  /**
   * Test that Mailings load using the Omnimailing.load api.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnimailingLoad(): void {
    $mailings = $this->loadMailings();
    $this->assertEquals(2, $mailings['count']);
    $mailing = $this->callAPISuccess('Mailing', 'getsingle', ['hash' => 'sp7877']);
    $this->assertEquals(1, $mailing['is_completed']);
    $this->loadMailings(TRUE);

    $mailingReloaded = $this->callAPISuccess('Mailing', 'getsingle', ['hash' => 'sp7877']);
    $this->assertEquals($mailingReloaded['id'], $mailing['id']);
    $mailingJobs = $this->callAPISuccess('MailingJob', 'get', ['mailing_id' => $mailing['id']]);
    $this->assertEquals(0, $mailingJobs['count']);
  }

  /**
   * Load mailings for test.
   *
   * @param bool $isCached
   *
   * @return array
   */
  protected function loadMailings(bool $isCached = FALSE): array {
    $responses = $this->getResponses($isCached);
    $mailings = $this->callAPISuccess('Omnimailing', 'load', [
      'mail_provider' => 'Silverpop',
      'client' => $this->getMockRequest($responses),
      'username' => 'Donald',
      'password' => 'quack',
    ]);
    return $mailings;
  }

  /**
   *
   * @param bool $isCached
   *
   * @return array
   */
  protected function getResponses(bool $isCached = FALSE): array {
    $calls = [
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse.txt'),
      // Second 'non-campaign' call.
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      // The query look ups are at the end.
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
    ];
    if ($isCached) {
      // Because these have been retrieved there is no outgoing call here.
      unset ($calls[2], $calls[5]);
    }
    return $calls;
  }

}
