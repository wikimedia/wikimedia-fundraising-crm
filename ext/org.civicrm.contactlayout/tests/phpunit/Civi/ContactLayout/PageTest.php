<?php
declare(strict_types = 1);

namespace Civi\ContactLayout;

use Civi\Api4\OptionValue;
use Civi\Api4\Setting;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class PageTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Test CRM_Contactlayout_Page_Base runs and sets up resource variables.
   */
  public function testRun(): void {
    // Add an inactive option value to ensure it gets filtered out by Api4
    OptionValue::create(FALSE)
      ->addValue('option_group_id:name', 'contact_edit_options')
      ->addValue('name', 'TestInactiveOption')
      ->addValue('value', '999')
      ->addValue('label', 'Test Inactive Option')
      ->addValue('is_active', FALSE)
      ->execute();

    Setting::set(FALSE)
      ->addValue('contact_edit_options', ['1', '2'])
      ->execute();

    $page = new \CRM_Contactlayout_Page_Base();
    ob_start();
    $page->run();
    $output = ob_get_clean();

    $vars = \Civi::resources()->getSettings()['vars']['contactlayout'];

    // Verify contactEditOptions contains active options and excludes inactive ones
    $this->assertNotEmpty($vars['contactEditOptions']);
    $this->assertArrayHasKey('CustomData', $vars['contactEditOptions']);
    $this->assertArrayHasKey('Address', $vars['contactEditOptions']);
    $this->assertSame('1', $vars['contactEditOptions']['CustomData']);
    $this->assertSame('2', $vars['contactEditOptions']['Address']);
    $this->assertArrayNotHasKey('TestInactiveOption', $vars['contactEditOptions']);

    // Verify systemDefaultsEnabled reflects filtered options against settings
    $this->assertSame(['CustomData' => '1', 'Address' => '2'], $vars['systemDefaultsEnabled']);

    // Verify metadata collections
    $this->assertIsArray($vars['contactTypes']);
    $this->assertIsArray($vars['groups']);
    $this->assertIsArray($vars['relationshipTypes']);
    $this->assertIsArray($vars['layouts']);
    $this->assertIsArray($vars['blocks']);
    $this->assertIsArray($vars['tabs']);
  }

}
