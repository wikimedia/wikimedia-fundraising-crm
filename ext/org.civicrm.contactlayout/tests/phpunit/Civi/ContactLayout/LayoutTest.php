<?php
declare(strict_types = 1);

namespace Civi\ContactLayout;

use Civi\Api4\Contact;
use Civi\Api4\ContactLayout;
use Civi\Api4\ContactType;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class LayoutTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown(): void {
    ContactLayout::delete(FALSE)->addWhere('id', '>', 0)->execute();
    \Civi::$statics['CRM_Contactlayout_BAO_ContactLayout'] = [];
    \Civi::$statics['contactLayout'] = [];
    parent::tearDown();
  }

  /**
   * Test \CRM_Contactlayout_BAO_ContactLayout::getLayout() with various contact types,
   * sub_types, and user groups against layout filters and weights.
   */
  public function testGetLayout(): void {
    // 1. Create Subtypes
    ContactType::create(FALSE)
      ->addValue('name', 'TestStudent')
      ->addValue('label', 'Test Student')
      ->addValue('parent_id:name', 'Individual')
      ->execute();

    ContactType::create(FALSE)
      ->addValue('name', 'TestStaff')
      ->addValue('label', 'Test Staff')
      ->addValue('parent_id:name', 'Individual')
      ->execute();

    ContactType::create(FALSE)
      ->addValue('name', 'TestSponsor')
      ->addValue('label', 'Test Sponsor')
      ->addValue('parent_id:name', 'Organization')
      ->execute();

    // 2. Create Groups
    $adminGroup = Group::create(FALSE)
      ->addValue('title', 'Test Admins')
      ->addValue('name', 'test_admins')
      ->execute()
      ->first();

    $staffGroup = Group::create(FALSE)
      ->addValue('title', 'Test Staff Group')
      ->addValue('name', 'test_staff_group')
      ->execute()
      ->first();

    // 3. Create Users
    $adminUser = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Admin')
      ->addValue('last_name', 'User')
      ->execute()
      ->first();
    GroupContact::create(FALSE)
      ->addValue('group_id', $adminGroup['id'])
      ->addValue('contact_id', $adminUser['id'])
      ->execute();

    $staffUser = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Staff')
      ->addValue('last_name', 'User')
      ->execute()
      ->first();
    GroupContact::create(FALSE)
      ->addValue('group_id', $staffGroup['id'])
      ->addValue('contact_id', $staffUser['id'])
      ->execute();

    $plainUser = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Plain')
      ->addValue('last_name', 'User')
      ->execute()
      ->first();

    // 4. Create Contacts of different types and subtypes
    $indPlain = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Plain')
      ->addValue('last_name', 'Individual')
      ->execute()
      ->first();

    $indStudent = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('contact_sub_type', ['TestStudent'])
      ->addValue('first_name', 'Student')
      ->addValue('last_name', 'Individual')
      ->execute()
      ->first();

    $indStaff = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('contact_sub_type', ['TestStaff'])
      ->addValue('first_name', 'Staff')
      ->addValue('last_name', 'Individual')
      ->execute()
      ->first();

    $indStudentStaff = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('contact_sub_type', ['TestStudent', 'TestStaff'])
      ->addValue('first_name', 'StudentStaff')
      ->addValue('last_name', 'Individual')
      ->execute()
      ->first();

    $orgPlain = Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'Plain Org')
      ->execute()
      ->first();

    $orgSponsor = Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('contact_sub_type', ['TestSponsor'])
      ->addValue('organization_name', 'Sponsor Org')
      ->execute()
      ->first();

    $household = Contact::create(FALSE)
      ->addValue('contact_type', 'Household')
      ->addValue('household_name', 'Sample Household')
      ->execute()
      ->first();

    // 5. Create Layouts with various filter criteria and weights
    $sampleBlocks = [[[['name' => 'core.ContactInfo']]]];

    // Admin-only layout (weight 1, no contact_type/sub_type filter)
    ContactLayout::create(FALSE)
      ->addValue('label', 'Admin Special Layout')
      ->addValue('groups', ['test_admins'])
      ->addValue('weight', 1)
      ->addValue('blocks', $sampleBlocks)
      ->execute();

    // Individual with TestStudent AND TestStaff subtypes (weight 10)
    ContactLayout::create(FALSE)
      ->addValue('label', 'Student Staff AND Layout')
      ->addValue('contact_type', 'Individual')
      ->addValue('contact_sub_type', ['TestStudent', 'TestStaff'])
      ->addValue('settings', ['sub_type_operator' => 'AND'])
      ->addValue('weight', 10)
      ->addValue('blocks', $sampleBlocks)
      ->execute();

    // Organization Sponsor layout visible to Test Staff Group users only (weight 15)
    ContactLayout::create(FALSE)
      ->addValue('label', 'Sponsor Staff View Layout')
      ->addValue('contact_type', 'Organization')
      ->addValue('contact_sub_type', ['TestSponsor'])
      ->addValue('groups', ['test_staff_group'])
      ->addValue('weight', 15)
      ->addValue('blocks', $sampleBlocks)
      ->execute();

    // Individual with TestStudent OR TestStaff subtype (weight 20)
    ContactLayout::create(FALSE)
      ->addValue('label', 'Student OR Staff Layout')
      ->addValue('contact_type', 'Individual')
      ->addValue('contact_sub_type', ['TestStudent', 'TestStaff'])
      ->addValue('settings', ['sub_type_operator' => 'OR'])
      ->addValue('weight', 20)
      ->addValue('blocks', $sampleBlocks)
      ->execute();

    // Default Individual layout (weight 30)
    ContactLayout::create(FALSE)
      ->addValue('label', 'Individual Default Layout')
      ->addValue('contact_type', 'Individual')
      ->addValue('weight', 30)
      ->addValue('blocks', $sampleBlocks)
      ->execute();

    // Default Organization layout (weight 30)
    ContactLayout::create(FALSE)
      ->addValue('label', 'Organization Default Layout')
      ->addValue('contact_type', 'Organization')
      ->addValue('weight', 30)
      ->addValue('blocks', $sampleBlocks)
      ->execute();

    // Universal Fallback layout (weight 100)
    ContactLayout::create(FALSE)
      ->addValue('label', 'Universal Fallback Layout')
      ->addValue('weight', 100)
      ->addValue('blocks', $sampleBlocks)
      ->execute();

    $scenarios = [
      'Admin user viewing StudentStaff individual matches Admin layout by weight' => [
        'cid' => $indStudentStaff['id'],
        'uid' => $adminUser['id'],
        'expected' => 'Admin Special Layout',
      ],
      'Plain user viewing StudentStaff individual matches Student Staff AND layout' => [
        'cid' => $indStudentStaff['id'],
        'uid' => $plainUser['id'],
        'expected' => 'Student Staff AND Layout',
      ],
      'Plain user viewing Student individual matches Student OR Staff layout' => [
        'cid' => $indStudent['id'],
        'uid' => $plainUser['id'],
        'expected' => 'Student OR Staff Layout',
      ],
      'Plain user viewing Staff individual matches Student OR Staff layout' => [
        'cid' => $indStaff['id'],
        'uid' => $plainUser['id'],
        'expected' => 'Student OR Staff Layout',
      ],
      'Plain user viewing Plain individual matches Individual Default layout' => [
        'cid' => $indPlain['id'],
        'uid' => $plainUser['id'],
        'expected' => 'Individual Default Layout',
      ],
      'Staff user viewing Sponsor organization matches Sponsor Staff View layout' => [
        'cid' => $orgSponsor['id'],
        'uid' => $staffUser['id'],
        'expected' => 'Sponsor Staff View Layout',
      ],
      'Plain user viewing Sponsor organization falls back to Organization Default layout' => [
        'cid' => $orgSponsor['id'],
        'uid' => $plainUser['id'],
        'expected' => 'Organization Default Layout',
      ],
      'Plain user viewing Plain organization matches Organization Default layout' => [
        'cid' => $orgPlain['id'],
        'uid' => $plainUser['id'],
        'expected' => 'Organization Default Layout',
      ],
      'Plain user viewing Household contact falls back to Universal Fallback layout' => [
        'cid' => $household['id'],
        'uid' => $plainUser['id'],
        'expected' => 'Universal Fallback Layout',
      ],
      'Admin user viewing Household contact matches Admin layout' => [
        'cid' => $household['id'],
        'uid' => $adminUser['id'],
        'expected' => 'Admin Special Layout',
      ],
    ];

    foreach ($scenarios as $description => $scenario) {
      \Civi::$statics['contactLayout'] = [];
      $layout = \CRM_Contactlayout_BAO_ContactLayout::getLayout($scenario['cid'], $scenario['uid']);
      $this->assertEquals($scenario['expected'], $layout['label'] ?? NULL, $description);
      $this->assertSame('Employer, Nickname, Source', $layout['blocks'][0][0][0]['title']);
    }
  }

}
