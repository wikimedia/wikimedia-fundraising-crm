<?php

namespace Civi\WMFMailTracking;

use \CRM_Core_DAO;
use \CRM_Mailing_BAO_Mailing;

class CiviMailQueueRecord {

  protected $queue;

  protected $email;

  /**
   * @param \CRM_Mailing_Event_DAO_Queue $queue
   * @param \CRM_Core_DAO_Email $email
   */
  public function __construct($queue, $email) {
    $this->queue = $queue;
    $this->email = $email;
  }

  public function getVerp() {
    $verpAndUrls = CRM_Mailing_BAO_Mailing::getVerpAndUrls(
      $this->queue->job_id,
      $this->queue->id,
      $this->queue->hash,
      $this->email->email);

    return $verpAndUrls[0]['bounce'];
  }

  public function markBounced($bounceType, $date = NULL) {
    //TODO
  }

  public function getQueueID() {
    return $this->queue->id;
  }

  public function getContactID() {
    return $this->email->contact_id;
  }

  public function getEmailID() {
    return $this->email->id;
  }

  public function markDelivered($date = NULL) {
    if (!$date) {
      $date = gmdate('YmdHis');
    }
    $sql = "INSERT INTO civicrm_mailing_event_delivered ( event_queue_id, time_stamp ) VALUES ( %1, %2 )";
    $params = [
      1 => [$this->getQueueID(), 'Integer'],
      2 => [$date, 'String'],
    ];
    CRM_Core_DAO::executeQuery($sql, $params);
  }

}
