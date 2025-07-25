<?php

require_once __DIR__ . '/OmnimailBaseTestClass.php';

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Group;
use Civi\Api4\Omnicontact;
use Civi\Api4\Queue;
use Civi\Test\EntityTrait;

/**
 * Test Omnicontact create method.
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
class OmnicontactCreateTest extends OmnimailBaseTestClass {
  use EntityTrait;

  /**
   * Post test cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    Group::delete(FALSE)->addWhere('name', '=', 'test_create_group')->execute();
    parent::tearDown();
    if (!empty($this->ids['Contact'])) {
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contact'])
        ->setUseTrash(FALSE)
        ->execute();
    }
  }

  /**
   * Example: the groupMember load fn works.
   *
   * @throws \CRM_Core_Exception
   */
  public function testAddToGroup(): void {
    $this->getMockRequest([file_get_contents(__DIR__ . '/Responses/AddRecipient.txt')]);
    $group = Group::create(FALSE)->setValues([
      'name' => 'test_create_group',
      'title' => 'Test group create',
      'Group_Metadata.remote_group_identifier' => 42192504,
    ])->execute()->first();
    $result = Omnicontact::create(FALSE)
      ->setGroupID([$group['id']])
      ->setDatabaseID(1234)
      ->setClient($this->getGuzzleClient())
      ->setEmail('jenny@example.com')
      ->setValues([
        'last_name' => 'Jenny',
        'first_name' => 'Lee',
      ])
      ->execute()->first();
    $guzzleSentRequests = $this->getRequestBodies();

    $this->assertEquals(569624660942, $result['contact_identifier']);
    $this->assertEquals(trim(file_get_contents(__DIR__ . '/Requests/AddRecipient.txt')), $guzzleSentRequests[0]);
  }

  /**
   * Example: the groupMember load fn works.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSnooze(): void {
    $this->getMockRequest([
      file_get_contents(__DIR__ . '/Responses/AddRecipient.txt'),
      file_get_contents(__DIR__ . '/Responses/UpdateRecipient.txt'),
    ]);
    $email = 'the_don@example.org';

    $contact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email_primary.email' => $email,
    ]);

    $activity = Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'EmailSnoozed')
      ->addValue('status_id:name', 'Scheduled')
      ->addValue('subject', "Email snooze scheduled")
      ->addValue('source_contact_id', $contact['id'])
      ->addValue('source_record_id', $contact['id'])
      ->addValue('activity_date_time', 'now')
      ->execute()
      ->first();

    Omnicontact::create(FALSE)
      ->setEmail($email)
      ->setClient($this->getGuzzleClient())
      ->setDatabaseID(1234)
      ->setValues([
        'last_name' => 'Donald',
        'first_name' => 'Duck',
        'snooze_end_date' => ((int) date('Y') + 1) . '-09-09',
        'activity_id' => $activity['id'],
      ])
      ->execute()->first();
    $guzzleSentRequests = $this->getRequestBodies();
    $activity = Activity::get(FALSE)
      ->addWhere('id', '=', $activity['id'])
      ->addSelect('status_id:name')
      ->execute()
      ->last();

    $this->assertEquals('Completed', $activity['status_id:name']);
    $this->assertEquals(str_replace('--YEAR--', (string) ((int) date('Y') + 1), trim(file_get_contents(__DIR__ . '/Requests/SnoozeRecipient.txt'))), $guzzleSentRequests[1]);
  }

  /**
   * Test that updating the contact's snooze date queues up a wee nap.
   *
   * This tests that when a contact has their email updated, using apiv4
   * a request to update Acoustic is saved to the civicrm_queue/civicrm_queue_item
   * table and that when we run that queue it does the call to Acoustic.
   *
   * @throws \CRM_Core_Exception
   */
  public function testQueueSnooze(): void {
    $this->getMockRequest([
      file_get_contents(__DIR__ . '/Responses/AddRecipient.txt'),
      file_get_contents(__DIR__ . '/Responses/UpdateRecipient.txt'),
    ]);
    $this->addTestClientToXMLSingleton();

    $snoozeDate = date('Y-m-d', strtotime('+ 1 week'));
    $contact = $this->createSnoozyDuck($snoozeDate);
    $queue = Queue::get(FALSE)
      ->addWhere('name', '=', 'omni-snooze')
      ->addWhere('status', '=', 'active')
      ->execute();

    $this->assertCount(1, $queue);
    $this->assertEquals('active', $queue->first()['status']);
    $this->runQueue();
    $activity = Activity::get(FALSE)
      ->addSelect('status_id:name')
      ->addWhere('source_record_id', '=', $contact['id'])
      ->addWhere('activity_type_id:name', '=', 'EmailSnoozed')
      ->execute()->last();
    $this->assertNotNull($activity);
    $this->assertEquals('Completed', $activity['status_id:name']);
    $requestContent = str_replace(urlencode('RESUME_SEND_DATE>09/09/--YEAR--'), 'RESUME_SEND_DATE' . urlencode('>' . date('m/d/Y', strtotime($snoozeDate))), trim(file_get_contents(__DIR__ . '/Requests/SnoozeRecipient.txt')));
    $guzzleSentRequests = $this->getRequestBodies();
    $this->assertEquals($requestContent, $guzzleSentRequests[1]);
  }

  /**
   * This also tests that setting snooze date queues an update, but for email entity edit.
   *
   * In this case we will just check it is queued.
   *
   * @throws \CRM_Core_Exception
   */
  public function testQueueEmailEdit(): void {
    // Set the busy_threshold really high so our hook does not prevent it running.
    putenv('busy_threshold=500000');
    // We don't send calls in this test but get an e-notice on CI if there is
    // no Acoustic configured.
    $this->setDatabaseID(1234);
    $contactID = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Daisy',
      'last_name' => 'Duck',
    ])['id'];

    $snoozeDate = date('Y-m-d', strtotime('+ 1 week'));
    Email::create(FALSE)->setValues([
      'contact_id' => $contactID,
      'email' => 'daisy@example.com',
      'email_settings.snooze_date' => $snoozeDate,
    ])->execute();
    $this->assertEquals(1, CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_queue WHERE name = "omni-snooze"'));
    $queuedItem = Queue::claimItems(FALSE)
      ->setQueue('omni-snooze')
      ->execute()->first();
    $this->assertNotEmpty($queuedItem);
    $this->assertEquals('daisy@example.com', $queuedItem['data']['arguments'][2]['email']);
  }

  /**
   * Run the snooze queue.
   */
  protected function runQueue(): void {
    $queue = Civi::queue('omni-snooze');
    $runner = new CRM_Queue_Runner([
      'queue' => $queue,
      'errorMode' => CRM_Queue_Runner::ERROR_ABORT,
    ]);
    $runner->runAll();
  }

}
