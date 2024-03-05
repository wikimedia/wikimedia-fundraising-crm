<?php

namespace Civi\WMFMailTracking;

class CiviMailingRecord {

  protected $mailing;

  protected $job;

  /**
   * @param \CRM_Mailing_DAO_Mailing $mailing
   * @param \CRM_Mailing_DAO_MailingJob; $job
   */
  public function __construct($mailing, $job) {
    $this->mailing = $mailing;
    $this->job = $job;
  }

  /**
   * Gets the id of the parent job created along with this mailing
   *
   * @return int parent job id
   */
  public function getJobID() {
    return $this->job->id;
  }

  /**
   * Gets the CiviCRM db ID for the mailing
   *
   * @return int mailing id
   */
  public function getMailingID() {
    return $this->mailing->id;
  }

  /**
   * Gets the unique name for this mailing in CiviCRM
   *
   * @return string mailing name
   */
  public function getMailingName() {
    return $this->mailing->name;
  }

  /**
   * Gets the status of the parent job created along with this mailing
   *
   * @return enum('Scheduled', 'Running', 'Complete', 'Paused', 'Canceled') parent job status
   */
  public function getJobStatus() {
    return $this->job->status;
  }

  /**
   * Gets the underlying CiviCRM Mailing record
   *
   * @return \CRM_Mailing_DAO_Mailing
   */
  public function getMailing() {
    return $this->mailing;
  }

}
