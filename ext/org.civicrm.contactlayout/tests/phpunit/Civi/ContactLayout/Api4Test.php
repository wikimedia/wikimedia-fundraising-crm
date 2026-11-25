<?php
declare(strict_types = 1);

namespace Civi\ContactLayout;

use Civi\Api4\ContactLayout;
use Civi\Test\Api4TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;

/**
 * @group headless
 */
class Api4Test extends \PHPUnit\Framework\TestCase implements HeadlessInterface {
  use Api4TestTrait;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \Civi::$statics['CRM_Contactlayout_BAO_ContactLayout'] = [];
    \Civi::$statics['contactLayout'] = [];
  }

  public function tearDown(): void {
    $this->deleteTestRecords();
    ContactLayout::delete(FALSE)->addWhere('id', '>', 0)->execute();
    \Civi::$statics['CRM_Contactlayout_BAO_ContactLayout'] = [];
    \Civi::$statics['contactLayout'] = [];
    parent::tearDown();
  }

  /**
   * Test ContactLayout::getBlocks action.
   */
  public function testGetBlocks(): void {
    $customGroup = $this->createTestRecord('CustomGroup', [
      'title' => 'Test Custom Group',
      'name' => 'test_custom_group',
      'extends' => 'Individual',
      'style' => 'Inline',
    ]);
    $this->createTestRecord('CustomField', [
      'custom_group_id' => $customGroup['id'],
      'label' => 'Favorite Color',
      'name' => 'favorite_color',
      'data_type' => 'String',
      'html_type' => 'Text',
    ]);

    \Civi::$statics['CRM_Contactlayout_BAO_ContactLayout'] = [];

    $allBlocks = ContactLayout::getBlocks(FALSE)->execute();
    $this->assertNotEmpty($allBlocks);

    $blockGroups = array_column((array) $allBlocks, NULL, 'name');
    $this->assertArrayHasKey('core', $blockGroups);
    $this->assertArrayHasKey('custom', $blockGroups);
    $this->assertArrayHasKey('profile', $blockGroups);

    $coreBlockNames = array_column($blockGroups['core']['blocks'], 'name');
    $this->assertContains('core.ContactInfo', $coreBlockNames);
    $this->assertContains('core.Address', $coreBlockNames);
    $this->assertContains('core.Email', $coreBlockNames);
    $this->assertContains('core.Phone', $coreBlockNames);

    // Verify custom group block and that custom field title is in sample array
    $customBlocks = array_column($blockGroups['custom']['blocks'], NULL, 'name');
    $this->assertArrayHasKey('custom.test_custom_group', $customBlocks);
    $this->assertEquals('Test Custom Group', $customBlocks['custom.test_custom_group']['title']);
    $sample = (array) $customBlocks['custom.test_custom_group']['sample'];
    $this->assertContains('Favorite Color', $sample);

    // Test with where filter
    $filtered = ContactLayout::getBlocks(FALSE)
      ->addWhere('name', '=', 'core')
      ->execute();
    $this->assertCount(1, $filtered);
    $this->assertEquals('core', $filtered->first()['name']);
  }

  /**
   * Test ContactLayout::getTabs action.
   */
  public function testGetTabs(): void {
    $customGroup = $this->createTestRecord('CustomGroup', [
      'title' => 'Multi Record Group',
      'name' => 'multi_record_group',
      'extends' => 'Individual',
      'is_multiple' => TRUE,
      'style' => 'Tab with table',
    ]);
    $this->createTestRecord('CustomField', [
      'custom_group_id' => $customGroup['id'],
      'label' => 'Multi Field',
      'name' => 'multi_field',
      'data_type' => 'String',
      'html_type' => 'Text',
    ]);

    $allTabs = ContactLayout::getTabs(FALSE)->execute();
    $this->assertNotEmpty($allTabs);

    $tabIds = array_column((array) $allTabs, 'id');
    $this->assertContains('rel', $tabIds);
    $this->assertContains("custom_{$customGroup['id']}", $tabIds);

    // Test with where filter
    $filtered = ContactLayout::getTabs(FALSE)
      ->addWhere('id', '=', "custom_{$customGroup['id']}")
      ->execute();
    $this->assertCount(1, $filtered);
    $customTab = $filtered->first();
    $this->assertEquals("custom_{$customGroup['id']}", $customTab['id']);
    $this->assertEquals('Multi Record Group', $customTab['title']);
    $this->assertEquals(['Individual'], $customTab['contact_type']);
    $this->assertTrue($customTab['is_active']);
  }

  /**
   * Test ContactLayout::replace action.
   */
  public function testReplace(): void {
    $sampleBlocks = [[[['name' => 'core.ContactInfo']]]];

    // 1. Create initial layouts
    $initial1 = $this->createTestRecord('ContactLayout', [
      'label' => 'Initial Org Layout',
      'contact_type' => 'Organization',
      'blocks' => $sampleBlocks,
    ]);

    $initial2 = $this->createTestRecord('ContactLayout', [
      'label' => 'Initial Ind Layout',
      'contact_type' => 'Individual',
      'blocks' => $sampleBlocks,
    ]);

    // 2. Selective replacement with where clause
    $replaceResult = ContactLayout::replace(FALSE)
      ->addWhere('contact_type', '=', 'Individual')
      ->setRecords([
        [
          'label' => 'Updated Ind Layout 1',
          'blocks' => $sampleBlocks,
          'weight' => 5,
        ],
        [
          'label' => 'Updated Ind Layout 2',
          'blocks' => $sampleBlocks,
          'weight' => 10,
        ],
      ])
      ->execute();

    $this->assertCount(2, $replaceResult);

    // Verify Organization layout was NOT replaced/deleted
    $orgLayouts = ContactLayout::get(FALSE)
      ->addWhere('contact_type', '=', 'Organization')
      ->execute();
    $this->assertCount(1, $orgLayouts);
    $this->assertEquals($initial1['id'], $orgLayouts->first()['id']);
    $this->assertEquals('Initial Org Layout', $orgLayouts->first()['label']);

    // Verify Individual layouts were replaced
    $indLayouts = ContactLayout::get(FALSE)
      ->addWhere('contact_type', '=', 'Individual')
      ->addOrderBy('weight')
      ->execute();
    $this->assertCount(2, $indLayouts);
    $this->assertEquals(['Updated Ind Layout 1', 'Updated Ind Layout 2'], array_column((array) $indLayouts, 'label'));

    // 3. Wholesale replacement (no where clause)
    ContactLayout::replace(FALSE)
      ->setRecords([
        [
          'label' => 'Wholesale Layout',
          'contact_type' => 'Household',
          'blocks' => $sampleBlocks,
        ],
      ])
      ->execute();

    $allLayouts = ContactLayout::get(FALSE)->execute();
    $this->assertCount(1, $allLayouts);
    $this->assertEquals('Wholesale Layout', $allLayouts->first()['label']);
    $this->assertEquals('Household', $allLayouts->first()['contact_type']);
  }

}
