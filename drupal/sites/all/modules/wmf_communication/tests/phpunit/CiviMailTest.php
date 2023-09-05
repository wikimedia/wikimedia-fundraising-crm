<?php

namespace wmf_communication;

/**
 * Tests for CiviMail helper classes
 *
 * @group CiviMail
 * @group WmfCommunication
 */
class CiviMailTest extends CiviMailTestBase {

  public function testAddMailing() {
    $storedMailing = $this->mailStore->addMailing(
      $this->source,
      $this->body,
      $this->subject
    );
    $this->assertInstanceOf(
      \wmf_communication\CiviMailingRecord::class,
      $storedMailing,
      'addMailing should return an ICiviMailingRecord'
    );
    $this->assertTrue(
      is_numeric($storedMailing->getMailingID()),
      'CiviMailingRecord should have a numeric mailing ID'
    );
  }

  public function testGetMailing() {
    $storedMailing = $this->mailStore->addMailing(
      $this->source,
      $this->body,
      $this->subject
    );
    $retrievedMailing = $this->mailStore->getMailing($this->source);
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

  public function testAddQueueRecord() {
    $storedMailing = $this->mailStore->addMailing(
      $this->source,
      $this->body,
      $this->subject
    );
    $queueRecord = $this->mailStore->addQueueRecord(
      $storedMailing,
      'generaltrius@hondo.mil',
      $this->contactID
    );
    $this->assertInstanceOf(
      'wmf_communication\ICiviMailQueueRecord',
      $queueRecord,
      'addQueueRecord should return an ICiviMailQueueRecord'
    );
    $this->assertTrue(
      is_numeric($queueRecord->getQueueID()),
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
