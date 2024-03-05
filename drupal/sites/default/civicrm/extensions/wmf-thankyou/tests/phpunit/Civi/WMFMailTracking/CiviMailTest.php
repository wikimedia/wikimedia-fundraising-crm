<?php

namespace Civi\WMFMailTracking;

/**
 * Tests for CiviMail helper classes
 *
 * @group CiviMail
 * @group WmfCommunication
 */
class CiviMailTest extends CiviMailTestBase {

  /**
   * @throws \Civi\WMFMailTracking\CiviQueueInsertException
   * @throws \Civi\WMFMailTracking\CiviMailingMissingException
   */
  public function testAddQueueRecord(): void {
    $storedMailing = $this->mailStore->getMailing('thank_you');
    $queueRecord = $this->mailStore->addQueueRecord(
      $storedMailing,
      'generaltrius@hondo.mil',
      $this->contactID
    );
    $this->assertInstanceOf(
      CiviMailQueueRecord::class,
      $queueRecord,
      'addQueueRecord should return an CiviMailQueueRecord'
    );
    $this->assertIsNumeric(
      $queueRecord->getQueueID(), 'CiviMailQueueRecord should have a numeric ID'
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
