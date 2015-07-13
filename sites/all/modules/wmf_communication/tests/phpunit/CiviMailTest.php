<?php
namespace wmf_communication;

/**
 * Tests for CiviMail helper classes
 * @group CiviMail
 * @group WmfCommunication
 */
class CiviMailTest extends CiviMailTestBase {

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
			'wmf_communication\ICiviMailingRecord',
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
	 * @expectedException wmf_communication\CiviMailingMissingException
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
			'wmf_communication\ICiviMailQueueRecord',
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