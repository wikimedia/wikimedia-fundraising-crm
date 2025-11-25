<?php

namespace phpunit;

use Civi\Api4\Activity;
use Civi\Api4\Omniactivity;
use Civi\Api4\OmnimailJobProgress;
use OmnimailBaseTestClass;

require_once __DIR__ . '/OmnimailBaseTestClass.php';

/**
 * Test Omniactivity methods.
 *
 * @group headless
 */
class OmniactivityGetTest extends OmnimailBaseTestClass {

  /**
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function setUp(): void {
    parent::setUp();
    $this->setupSuccessfulWebTrackingDownloadClient();
    // Ensure contacts 12, 15 & 56 exist and are Individuals.
    foreach ($this->usedContactIDs() as $id) {
      \CRM_Core_DAO::executeQuery("
       INSERT INTO civicrm_contact (id, contact_type, display_name, sort_name)
       VALUES ($id, 'Individual', '$id@example.com', '$id@example.com')
       ON DUPLICATE KEY UPDATE contact_type = 'Individual'");
    }
  }

  public function tearDown(): void {
    parent::tearDown();
    Activity::delete(FALSE)
      ->addWhere('source_contact_id', 'IN', $this->usedContactIDs())
      ->execute();
  }

  /**
   * Test retrieving web activities from Acoustic.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetWebActions(): void {

    $result = Omniactivity::get(FALSE)
      ->setClient($this->getGuzzleClient())
      // This would be picked up from settings if not set here.
      ->setDatabaseID(345)
      ->setStart('2024-12-28')
      ->setEnd('2024-12-29')
      ->execute()->first();
    $this->assertEquals(trim(file_get_contents(__DIR__ . '/Requests/WebTrackingDataExport.txt')), $this->getRequestBodies()[0]);
    $this->assertEquals('se@example.com', $result['email']);
    $this->assertEquals('remind_me_later', $result['activity_type']);
    $this->assertEquals('B2425_122821_en6C_m_p1_lg_txt_cnt', $result['referrer_url']);
    $this->assertEquals('2024-12-29 00:00:03', $result['recipient_action_datetime']);
    $this->assertEquals(123456, $result['contact_identifier']);
  }

  /**
   * Test retrieving web activities from Acoustic.
   *
   * @throws \CRM_Core_Exception
   */
  public function testLoadWebActions(): void {
    $result = Omniactivity::load(FALSE)
      ->setClient($this->getGuzzleClient())
      // This would be picked up from settings if not set here.
      ->setDatabaseID(345)
      ->setLimit(4)
      ->setStart('2024-12-28')
      ->setEnd('2024-12-29')
      ->execute();

    $this->assertCount(4, $result);
    $activities = Activity::get(FALSE)
      ->addWhere('activity_type_id:name', '=', 'unsubscribe')
      ->addWhere('subject', '=', 'Unsubscribed via Acoustic Email link')
      ->execute();
    $this->assertCount(1, $activities);
    $progress = OmnimailJobProgress::get(FALSE)
      ->addWhere('job', '=', 'omnimail_omniactivity_load')
      ->execute()->single();
    $this->assertEquals('2024-12-29 00:00:00', $progress['progress_end_timestamp']);
    $this->assertEquals(7, $progress['offset'], 'offset should be equal to the limit + the ignored rows');
    $this->setupSuccessfulWebTrackingDownloadClient(FALSE);
    $result = Omniactivity::load(FALSE)
      ->setClient($this->getGuzzleClient())
      // This would be picked up from settings if not set here.
      ->setDatabaseID(345)
      ->setLimit(6)
      ->execute();
    $this->assertCount(5, $result);
    $activities = Activity::get(FALSE)
      ->addWhere('activity_type_id:name', '=', 'remind_me_later')
      ->execute();
    $this->assertCount(3, $activities);

    $progress = OmnimailJobProgress::get(FALSE)
      ->addWhere('job', '=', 'omnimail_omniactivity_load')
      ->execute()->single();
    $this->assertEquals('2024-12-29 00:00:00', $progress['last_timestamp']);
    $this->assertEquals('', $progress['progress_end_timestamp']);
    $this->assertEquals(0, $progress['offset'], 'offset should be reset');

  }

  /**
   * Test that Legacy Giving Signup form submissions are skipped.
   *
   * Before the fix, we'd get a CRM_Core_Exception:
   * - omni-mystery : action "form", action name "Form", action URL name "Legacy Giving Signup (Wiki domain)"
   *
   * @see https://phabricator.wikimedia.org/T411011
   * @throws \CRM_Core_Exception
   */
  public function testLegacyGivingSignupFormIsSkipped(): void {
    $result = Omniactivity::get(FALSE)
      ->setClient($this->getGuzzleClient())
      ->setDatabaseID(345)
      ->setStart('2024-12-28')
      ->setEnd('2024-12-29')
      ->execute();

    // The CSV fixture contains one Legacy Giving Signup form action that should be skipped
    // We expect to get results without throwing an omni-mystery exception
    $this->assertGreaterThan(0, $result->count(), 'Should return at least one valid activity');

    // Verify that the Legacy Giving Signup form was not included in the results
    foreach ($result as $activity) {
      $this->assertNotEquals('Legacy Giving Signup (Wiki domain)', $activity['action_url_name'],
        'Legacy Giving Signup form should have been skipped');
    }
  }

  /**
   * Contact IDs used in the test.
   *
   * @return array
   */
  public function usedContactIDs(): array {
    return [12, 15, 56, 57, 99];
  }

}
