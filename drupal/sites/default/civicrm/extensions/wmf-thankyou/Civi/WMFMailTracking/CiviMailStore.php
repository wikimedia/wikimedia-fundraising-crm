<?php

namespace Civi\WMFMailTracking;

use CRM_Core_DAO_Email;
use CRM_Core_Transaction;
use Exception;

/**
 * Handle inserting sent CiviMail records for emails
 * not actually sent by CiviCRM
 */
class CiviMailStore {

  protected static $mailings = [];

  protected static $jobs = [];

  /**
   * Adds a child job with completed date $date, a queue entry, and an entry
   * in the recipients table
   *
   * @param CiviMailingRecord $mailingRecord Mailing that is being sent
   * @param string $email Email address of recipient
   * @param int $contactId Used to disambiguate contacts with the same address
   *
   * @returns CiviMailQueueRecord
   *
   * @throws CiviQueueInsertException if email isn't in Civi or an error occurs
   */
  public function addQueueRecord($mailingRecord, $emailAddress, $contactId) {
    $email = new CRM_Core_DAO_Email();
    $email->email = $emailAddress;
    $email->contact_id = $contactId;

    if (!$email->find() || !$email->fetch()) {
      throw new CiviQueueInsertException("No record of email $emailAddress in CiviCRM");
    }
    //If there are multiple records for the email address, just use the first.
    //They should to be de-duped later, so no need to add extra mess.
    $transaction = new CRM_Core_Transaction();
    try {
      $queue = $this->addQueueInternal($mailingRecord->getJobID(), $mailingRecord->getMailingID(), $email);
      $transaction->commit();
    }
    catch (Exception $e) {
      $transaction->rollback();
      $msg = "Error inserting CiviMail queue entry for email $emailAddress -- {$e->getMessage()}";
      throw new CiviQueueInsertException($msg, 0, $e);
    }
    return new CiviMailQueueRecord($queue, $email);
  }

  /**
   * Gets a mailing record matching the input parameters
   *
   * @param string $source
   *
   * @returns CiviMailingRecord
   *
   * @throws CiviMailingMissingException no mailing found with those parameters
   */
  public function getMailing(string $source) {
    $mailing = $this->getMailingInternal($source);
    if (!$mailing) {
      throw new CiviMailingMissingException();
    }
    $job = $this->getJobInternal($mailing->id);
    // We need both.  If somehow the job wasn't created, throw
    // so the caller tries to add the mailing again.
    if (!$job) {
      throw new CiviMailingMissingException();
    }
    return new CiviMailingRecord($mailing, $job);
  }

  protected function getMailingInternal($name) {
    if (array_key_exists($name, self::$mailings)) {
      return self::$mailings[$name];
    }
    $mailing = new \CRM_Mailing_DAO_Mailing();
    $mailing->name = $name;
    $mailing->find(TRUE);
    if (!$mailing->id) {
      return NULL;
    }
    self::$mailings[$name] = $mailing;
    return $mailing;
  }

  protected function getJobInternal($mailingId) {
    if (array_key_exists($mailingId, self::$jobs)) {
      return self::$jobs[$mailingId];
    }
    $job = new \CRM_Mailing_DAO_MailingJob();
    $job->mailing_id = $mailingId;

    $job->find(TRUE);
    if (!$job->id) {
      return NULL;
    }
    self::$jobs[$mailingId] = $job;
    return $job;
  }

  protected function addQueueInternal(int $jobID, int $mailingID, $email): \CRM_Mailing_Event_BAO_MailingEventQueue {
    $params = [
      'mailing_id' => $mailingID,
      'job_id' => $jobID,
      'email_id' => $email->id,
      'contact_id' => $email->contact_id,
    ];
    return \CRM_Mailing_Event_BAO_Queue::create($params);
  }

}
