<?php
namespace wmf_communication;

use \CRM_Activity_BAO_Activity;
use \CRM_Activity_BAO_ActivityTarget;
use \CRM_Core_OptionGroup;
use \CRM_Mailing_BAO_Recipients;
use \CRM_Mailing_Event_BAO_Queue;
use \CRM_Mailing_Event_BAO_Delivered;
/**
 * Tests for CiviMail helper classes
 * @group CiviMail
 * @group WmfCommunication
 */
class CiviMailBulkTest extends CiviMailTestBase {

	protected $contacts = array();
	protected $emails = array();
	/**
	 * @var ICiviMailBulkStore
	 */
	protected $bulkMailStore; 

	public function setUp() {
		parent::setUp();

		$this->bulkMailStore = new CiviMailBulkStore();

		for ( $i = 1; $i <= 10; $i++ ) {
			$emailAddress = "hondonian$i@hondo.mil";
			$firstName = "Kevin$i";
			$lastName = 'Hondo';

			$contact = $this->getContact( $emailAddress, $firstName, $lastName );

			$this->contacts[] = $contact + array( 'emailAddress' => $emailAddress );
			$this->emails[] = $emailAddress;
		}
	}
	
	public function tearDown() {
		parent::tearDown();
		foreach ( $this->contacts as $contact ) {
			civicrm_api3( 'Email', 'delete', array( 'id' => $contact['emailID'] ) );
			civicrm_api3( 'Contact', 'delete', array( 'id' => $contact['contactID'] ) );
		}
	}

	public function testAddSentBulk() {
		$name = 'test_mailing';
		$revision = mt_rand();
		$storedMailing = $this->mailStore->addMailing(
			$this->source,
			$name,
			$this->body,
			$this->subject,
			$revision
		);

		$this->bulkMailStore->addSentBulk( $storedMailing, $this->emails );

		$mailingID = $storedMailing->getMailingID();
		// Should have a single bulk mailing activity created
		$activity = new CRM_Activity_BAO_Activity();
		$bulkMail = CRM_Core_OptionGroup::getValue('activity_type',
          'Bulk Email',
          'name'
        );
		$activity->activity_type_id = $bulkMail;
		$activity->source_record_id = $mailingID;
		$this->assertTrue( $activity->find() && $activity->fetch() );

		foreach ( $this->contacts as $contact ) {
			//recipients table
			$recipients = new CRM_Mailing_BAO_Recipients();
			$recipients->mailing_id = $mailingID;
			$recipients->contact_id = $contact['contactID'];
			$recipients->email_id = $contact['emailID'];
			$this->assertTrue( $recipients->find() && $recipients->fetch() );

			//queue entry
			$queueQuery = "SELECT q.id, q.contact_id
FROM civicrm_mailing_event_queue q
INNER JOIN civicrm_mailing_job j ON q.job_id = j.id
WHERE j.mailing_id = $mailingID";

			$queue = new CRM_Mailing_Event_BAO_Queue();
			$queue->query( $queueQuery );
			$this->assertTrue( $queue->fetch() );

			//delivery event
			$delivered = new CRM_Mailing_Event_BAO_Delivered();
			$delivered->event_queue_id = $queue->id;
			$this->assertTrue( $delivered->find() && $delivered->fetch() );

			//activity target
			$activityTarget = new CRM_Activity_BAO_ActivityTarget();
			$activityTarget->activity_id = $activity->id;
			$activityTarget->target_contact_id = $contact['contactID'];
			$this->assertTrue( $activityTarget->find() && $activityTarget->fetch() );
		}
	}
}