<?php

use CRM_CiviDataTranslate_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Api4\MessageTemplate;
use Civi\Api4\Strings;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;

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
class CRM_Wrapper_Test extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Headless setup.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   *
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    //
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Set up - ensure civicrm is initialized if calling tests outside cv context.
   */
  public function setUp(): void {
    civicrm_initialize();
    parent::setUp();
  }

  /**
   * Test that our wrapper interprets locales.
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testMessageTemplateWithWrapper() {
    $template = MessageTemplate::create()->setValues(['msg_html' => 'blah'])->setCheckPermissions(FALSE)->execute()->first();
    Strings::create()->setCheckPermissions(FALSE)->setValues(['entity_table' => 'civicrm_msg_template', 'entity_field' => 'msg_html','entity_id' => $template['id'], 'string' => 'not blah', 'language' => 'fr_FR'])->execute();
    $template = MessageTemplate::get()->setCheckPermissions(FALSE)->addWhere('id', '=', $template['id'])->setSelect(['*'])->setLanguage('fr_FR')->execute()->first();
    $this->assertEquals('not blah', $template['msg_html']);
  }

  /**
   * Test updating an existing template with a string in another language.
   *
   * The updates to msg_html should be saved to the strings table but not the main table.
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testMessageTemplateUpdateWithWrapper() {
    $template = MessageTemplate::create()->setCheckPermissions(FALSE)->setValues(['msg_html' => 'blah'])->execute()->first();
    // Update with no language set.
    $template = MessageTemplate::update()->setCheckPermissions(FALSE)->addWhere('id', '=', $template['id'])->setValues(['msg_html' => 'blah blah', 'is_reserved' => TRUE])->setLanguage('fr_FR')->execute()->first();
    $template = MessageTemplate::get()->setCheckPermissions(FALSE)->addWhere('id', '=', $template['id'])->setSelect(['*'])->setLanguage('fr_FR')->execute()->first();
    $this->assertEquals('blah blah', $template['msg_html']);
    $this->assertEquals(1, $template['is_reserved']);
    // Check the default language still returns unchanged.
    $template = MessageTemplate::get()->setCheckPermissions(FALSE)->addWhere('id', '=', $template['id'])->setSelect(['*'])->execute()->first();
    $this->assertEquals('blah', $template['msg_html']);
    $this->assertEquals(1, $template['is_reserved']);
    // Update the language string again.
    $template = MessageTemplate::update()->setCheckPermissions(FALSE)->addWhere('id', '=', $template['id'])->setValues(['msg_html' => 'new blah'])->setLanguage('fr_FR')->execute()->first();
    $template = MessageTemplate::get()->setCheckPermissions(FALSE)->addWhere('id', '=', $template['id'])->setSelect(['*'])->setLanguage('fr_FR')->execute()->first();
    $this->assertEquals('new blah', $template['msg_html']);

    // Now update using 'save'
    $template = MessageTemplate::save()->setCheckPermissions(FALSE)->setDefaults(['msg_html' => 'newer blah'])->setRecords([['id' => $template['id']]])->setLanguage('fr_FR')->execute()->first();
    $template = MessageTemplate::get()->setCheckPermissions(FALSE)->addWhere('id', '=', $template['id'])->setSelect(['*'])->setLanguage('fr_FR')->execute()->first();
    $this->assertEquals('newer blah', $template['msg_html']);
  }

  /**
   * Test that our wrapper interprets locales.
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testRenderTranslatedMessage() {
    $contributionRecur = $this->setupRecurringContribution();
    $msg = \Civi\Api4\Message::render()
      ->setCheckPermissions(FALSE)
      ->setWorkflowName('contribution_recurring_cancelled')
      ->setEntity('ContributionRecur')
      ->setEntityIDs([$contributionRecur['id']])
      ->execute()->first();
    $this->assertContains('{assign var="greeting" value="Dear Donald"}', $msg['msg_html']);
  }

  /**
   * Test rendering a non-core template.
   *
   * This version notably has some contribution_recur tokens....
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testRenderCustomTemplate() {
    MessageTemplate::create()->setCheckPermissions(FALSE)->setValues([
      'workflow_name' => 'my_custom_tpl',
      'msg_text' => 'Hi {contact.first_name}. Your email is {contact.email} and your recurring amount is {contributionRecur.amount}',
      'is_default' => TRUE,
    ])->setLanguage('en_NZ')->execute();
    $contributionRecur = $this->setupRecurringContribution();
    $msg = \Civi\Api4\Message::render()
      ->setCheckPermissions(FALSE)
      ->setEntity('ContributionRecur')
      ->setWorkflowName('my_custom_tpl')
      ->setEntityIDs([$contributionRecur['id']])
      ->execute()->first();
    $this->assertContains('Hi Donald', $msg['msg_text']);
    $this->assertContains('donald@duck.com', $msg['msg_text']);
    $this->assertContains('5', $msg['msg_text']);
  }

  /**
   * @return array
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function setupRecurringContribution():array {
    $contact = Contact::create()->setCheckPermissions(FALSE)->setValues(['first_name' => 'Donald', 'last_name' => 'Duck'])->addChain('email_0', \Civi\Api4\Email::create()
      ->addValue('contact_id', '$id')
      ->addValue('email', 'donald@duck.com')
    )->execute()->first();
    return ContributionRecur::create()
      ->setCheckPermissions(FALSE)
      ->setValues(['contact_id' => $contact['id'], 'amount' => 5, 'start_date' => 'now', 'frequency_interval' => 1, 'create_date' => 'now'])
      ->execute()->first();
  }

}
