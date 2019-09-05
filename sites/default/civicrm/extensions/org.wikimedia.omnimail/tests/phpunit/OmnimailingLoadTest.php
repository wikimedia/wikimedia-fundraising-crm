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
class OmnimailingLoadTest extends OmnimailBaseTestClass {

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnimailingLoad() {
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
   * @return array
   */
  protected function loadMailings() {
    $responses = array(
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
    );
    $mailings = $this->callAPISuccess('Omnimailing', 'load', array(
      'mail_provider' => 'Silverpop',
      'client' => $this->getMockRequest($responses),
      'username' => 'Donald',
      'password' => 'quack'
    ));
    return $mailings;
  }

}
