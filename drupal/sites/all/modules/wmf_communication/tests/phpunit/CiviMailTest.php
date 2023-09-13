<?php

namespace wmf_communication;

/**
 * Tests for CiviMail helper classes
 *
 * @group CiviMail
 * @group WmfCommunication
 */
class CiviMailTest extends CiviMailTestBase {

  public function testAddQueueRecord() {
    $storedMailing = $this->mailStore->getMailing('thank_you');
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
