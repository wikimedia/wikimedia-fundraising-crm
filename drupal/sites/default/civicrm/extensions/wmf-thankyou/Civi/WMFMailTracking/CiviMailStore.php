<?php

namespace Civi\WMFMailTracking;

use CRM_Core_DAO;
use CRM_Core_DAO_Email;
use CRM_Core_Transaction;
use Exception;
use wmf_communication\CiviMailingRecord;
use wmf_communication\CiviMailQueueRecord;

/**
 * Handle inserting sent CiviMail records for emails
 * not actually sent by CiviCRM
 */
class CiviMailStore {

  protected static $mailings = [];

  protected static $jobs = [];


  /**
   * Adds a mailing template, a mailing, and a 'sent' parent job to CiviMail
   *
   * @param string $source the content's system of origination (eg Silverpop,
   *   WMF)
   * @param string $bodyTemplate the body of the mailing
   * @param string $subjectTemplate the subject of the mailing
   *
   * @return \wmf_communication\CiviMailingRecord
   * @throws \Civi\WMFMailTracking\CiviMailingInsertException something bad happened with the insert
   */
  public function addMailing(string $source, string $bodyTemplate, string $subjectTemplate): CiviMailingRecord {
    $mailing = $this->getMailingInternal($source);

    $transaction = new CRM_Core_Transaction();
    try {
      if (!$mailing) {
        $params = [
          'subject' => $subjectTemplate,
          'body_html' => $bodyTemplate,
          'name' => $source,
          'is_completed' => TRUE,
          //TODO: user picker on TY config page, or add 'TY mailer' contact
          'scheduled_id' => 1,
        ];
        $mailing = \CRM_Mailing_BAO_Mailing::add($params);
        self::$mailings[$source] = $mailing;
      }

      $job = $this->getJobInternal($mailing->id);

      $saveJob = (!$job || $job->status !== 'Complete');

      if (!$job) {
        $job = new \CRM_Mailing_BAO_MailingJob();
        $job->start_date = $job->end_date = gmdate('YmdHis');
        $job->job_type = 'external';
        $job->mailing_id = $mailing->id;
      }

      if ($saveJob) {
        $job->status = 'Complete';
        $job->save();
        self::$jobs[$mailing->id] = $job;
      }
      $transaction->commit();
      return new CiviMailingRecord($mailing, $job);
    }
    catch (Exception $e) {
      $transaction->rollback();
      $msg = "Error inserting CiviMail Mailing record $source -- {$e->getMessage()}";
      throw new CiviMailingInsertException($msg, 0, $e);
    }
  }

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
    $date = gmdate('YmdHis');

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
      $childJob = $this->addChildJob($mailingRecord, $date);
      $queue = $this->addQueueInternal($childJob, $email);
      //add contact to recipients table
      $sql = "INSERT INTO civicrm_mailing_recipients
(mailing_id, email_id, contact_id)
VALUES ( %1, %2, %3 )";
      $params = [
        1 => [$mailingRecord->getMailingID(), 'Integer'],
        2 => [$email->id, 'Integer'],
        3 => [$email->contact_id, 'Integer'],
      ];
      CRM_Core_DAO::executeQuery($sql, $params);
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

  protected function addChildJob($mailingRecord, $date) {
    $job = new \CRM_Mailing_DAO_MailingJob();
    $job->mailing_id = $mailingRecord->getMailingID();
    $job->parent_id = $mailingRecord->getJobID();
    $job->status = 'Complete';
    $job->jobType = 'child';
    $job->job_limit = 1;
    $job->start_date = $job->end_date = $date;
    $job->save();
    return $job;
  }

  protected function addQueueInternal($job, $email): \CRM_Mailing_Event_BAO_MailingEventQueue {
    $params = [
      'mailing_id' => $job->mailing_id,
      'job_id' => $job->id,
      'email_id' => $email->id,
      'contact_id' => $email->contact_id,
    ];
    return \CRM_Mailing_Event_BAO_Queue::create($params);
  }

}

