<?php

namespace Civi\Test;

use Civi\Api4\FailureEmail;

/**
 * Tests for SmashPig payment processor extension
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
 * @group SmashPig
 * @group headless
 */
class FailureEmailTest extends SmashPigBaseTestClass {

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRender(): void {
    // Make sure the define is available (not this won't override any existing define).
    putenv('WMF_UNSUB_SALT=abc123');
    $this->setupFailureTemplate();
    $contributionRecur = $this->setupRecurring();
    $email = FailureEmail::render()->setCheckPermissions(FALSE)->setContributionRecurID($contributionRecur['id'])->execute()->first();
    $this->assertEquals('Dear Harry,
      We cancelled your recur of USD $12.34
      and we are sending you this at harry@hendersons.net
      this month of ' . (new \DateTime())->format('F') . '
      $12.34', $email['msg_html']);
    $this->assertEquals('Hey Harry', $email['msg_subject']);
  }

  /**
   * Test send api action.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSend(): void {
    \Civi::settings()->set('smashpig_recurring_send_failure_email', 1);
    $this->setupFailureTemplate();
    $contributionRecur = $this->setupRecurring();
    $email = FailureEmail::send()->setCheckPermissions(FALSE)->setContributionRecurID($contributionRecur['id'])->execute()->first();
    $activity = $this->getLatestFailureMailActivity((int) $contributionRecur['id']);
    $this->assertEquals('Hey Harry', $email['msg_subject']);
    $this->assertEquals('Recur fail message : Hey Harry', $activity['subject']);
    $this->assertEquals('Dear Harry,
      We cancelled your recur of USD $12.34
      and we are sending you this at harry@hendersons.net
      this month of ' . (new \DateTime())->format('F') . '
      $12.34', $activity['details']);
  }

}
