<?php

namespace Civi\WMFHook;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Test\EntityTrait;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class ContactTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;
  use EntityTrait;

  /**
   * Changing the MG Stage should record an MG Stage Change activity.
   *
   * @throws \CRM_Core_Exception
   */
  public function testStageChangeCreatesActivity(): void {
    $contactID = $this->createIndividual();

    Contact::update(FALSE)
      ->addValue('Prospect.Stage', 'Qualification')
      ->addValue('Prospect.Stage_Change_Reason', 'Met at gala')
      ->addWhere('id', '=', $contactID)
      ->execute();

    $activities = Activity::get(FALSE)
      ->addSelect('subject', 'details', 'MG_Stage.Changed_to')
      ->addWhere('target_contact_id', 'CONTAINS', $contactID)
      ->addWhere('activity_type_id:name', '=', 'MG Stage Change')
      ->execute();

    $this->assertCount(1, $activities);
    $activity = $activities->first();
    $this->assertEquals('From None to Qualification', $activity['subject']);
    $this->assertEquals('Met at gala', $activity['details']);
    $this->assertEquals('Qualification', $activity['MG_Stage.Changed_to']);
  }

  /**
   * Clearing the stage via the API (NULL) should record an activity with a NULL stage.
   *
   * @throws \CRM_Core_Exception
   */
  public function testStageClearedViaAPICreatesActivity(): void {
    $contactID = $this->createIndividual(['Prospect.Stage' => 'Qualification']);

    Contact::update(FALSE)
      ->addValue('Prospect.Stage', NULL)
      ->addWhere('id', '=', $contactID)
      ->execute();

    $activities = Activity::get(FALSE)
      ->addSelect('subject', 'MG_Stage.Changed_to')
      ->addWhere('target_contact_id', 'CONTAINS', $contactID)
      ->addWhere('activity_type_id:name', '=', 'MG Stage Change')
      ->execute();

    $this->assertCount(1, $activities);
    $activity = $activities->first();
    $this->assertEquals('From Qualification to None', $activity['subject']);
    $this->assertNull($activity['MG_Stage.Changed_to']);
  }

  /**
   * Re-saving the contact without changing the stage should not record an activity.
   *
   * @throws \CRM_Core_Exception
   */
  public function testNoActivityWhenStageUnchanged(): void {
    $contactID = $this->createIndividual(['Prospect.Stage' => 'Cultivation']);

    // Edit the contact, no change of stage.
    Contact::update(FALSE)
      ->addValue('Prospect.Stage', 'Cultivation')
      ->addWhere('id', '=', $contactID)
      ->execute();

    $count = Activity::get(FALSE)
      ->addWhere('target_contact_id', 'CONTAINS', $contactID)
      ->addWhere('activity_type_id:name', '=', 'MG Stage Change')
      ->execute()
      ->count();

    $this->assertEquals(0, $count);
  }

}
