<?php
namespace wmf_communication;

use \BaseWmfDrupalPhpUnitTestCase;
use \CiviMailStore;
/**
 * Tests for CiviMail helper classes
 */
class CiviMailTest extends BaseWmfDrupalPhpUnitTestCase {

	protected $source = 'wmf_communication_test';
	protected $body = '<p>Dear Wikipedia supporter,</p><p>You are beautiful.</p>';
	protected $subject = 'Thank you';
	/**
	 * @var ICiviMailStore
	 */
	protected $mailStore;
	protected $contactID;
	protected $emailID;

	public function setUp() {
		parent::setUp();
		civicrm_initialize();
		$this->mailStore = new CiviMailStore();
		$contact = $this->getContact( 'generaltrius@hondo.mil', 'Trius', 'Hondo' );
		$this->emailID = $contact[ 'emailID' ];
		$this->contactID = $contact[ 'contactID' ];
	}

	protected function getContact( $email, $firstName, $lastName ) {
		$emailResult = civicrm_api3( 'Email', 'get', array(
			'email' => $email,
		) );
		$firstResult = reset( $emailResult['values'] );
		if ( $firstResult ) {
			return array(
				'emailID' => $firstResult['id'],
				'contactID' => $firstResult['contact_id'],
			);
		} else {
			$contactResult = civicrm_api3( 'Contact', 'create', array(
				'first_name' => $firstName,
				'last_name' => $lastName,
				'contact_type' => 'Individual',
			) );
			$emailResult = civicrm_api3( 'Email', 'create', array(
				'email' => $email,
				'contact_id' => $contactResult['id'],
			) );
			return array(
				'emailID' => $emailResult['id'],
				'contactID' => $contactResult['id'],
			);
		}
	}

	public function tearDown() {
		civicrm_api3( 'Email', 'delete', array( 'id' => $this->emailID ) );
		civicrm_api3( 'Contact', 'delete', array( 'id' => $this->contactID ) );
	}

	public function testAddMailing() {
		$name = 'test_mailing';
		$revision = mt_rand();
		$storedMailing = $this->mailStore->addMailing(
			$this->source,
			$name,
			$this->body,
			$this->subject,
			$revision
		);
		$this->assertInstanceOf(
			'ICiviMailingRecord',
			$storedMailing,
			'addMailing should return an ICiviMailingRecord'
		);
		$this->assertTrue(
			is_numeric( $storedMailing->getMailingID() ),
			'CiviMailingRecord should have a numeric mailing ID'
		);
	}

	public function testGetMailing() {
		$name = 'test_mailing';
		$revision = mt_rand();
		$storedMailing = $this->mailStore->addMailing(
			$this->source,
			$name,
			$this->body,
			$this->subject,
			$revision
		);
		$retrievedMailing = $this->mailStore->getMailing(
			$this->source,
			$name,
			$revision
		);
		$this->assertEquals(
			$storedMailing->getMailingID(),
			$retrievedMailing->getMailingID(),
			'Retrieved mailing has wrong MailingID'
		);
		$this->assertEquals(
			$storedMailing->getJobID(),
			$retrievedMailing->getJobID(),
			'Retrieved mailing has wrong JobID'
		);
	}

	/**
	 * @expectedException CiviMailingMissingException
	 */
	public function testMissingMailing() {
		$this->mailStore->getMailing( 'fakeSource', 'fakeName', mt_rand() );
	}

	public function testAddQueueRecord() {
		$name = 'test_mailing';
		$revision = mt_rand();
		$storedMailing = $this->mailStore->addMailing(
			$this->source,
			$name,
			$this->body,
			$this->subject,
			$revision
		);
		$queueRecord = $this->mailStore->addQueueRecord(
			$storedMailing,
			'generaltrius@hondo.mil',
			'20140205104611'
		);
		$this->assertInstanceOf(
			'ICiviMailQueueRecord',
			$queueRecord,
			'addQueueRecord should return an ICiviMailQueueRecord'
		);
		$this->assertTrue(
			is_numeric( $queueRecord->getQueueID() ),
			'CiviMailQueueRecord should have a numeric ID'
		);
		$this->assertEquals(
			$this->contactID,
			$queueRecord->getContactID(),
			'CiviMailQueueRecord has wrong contact ID'
		);
		$this->assertEquals(
			$this->emailID,
			$queueRecord->getEmailID(),
			'CiviMailQueueRecord has wrong email ID'
		);
	}
}