<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Email;
use Civi\Api4\Contact;
use Civi\Api4\Activity;

/**
 * Verify and record a double opt-in activity.
 *
 * @method $this setEmail(string $email)
 * @method $this setContact_Id( int $contact_id)
 * @method $this setChecksum(string $checksum)
 * @method $this setCampaign(string $campaign)
 * @method $this setMedium(string $medium)
 * @method $this setSource(string $source)
 *
 * */
class DoubleOptIn extends AbstractAction {

  /**
   * email
   * @var string
   * @required
   */
  protected $email;

  /**
   * contactID
   * @var int
   * @required
   */
  protected $contact_id;

  /**
   * checksum
   * @var string
   * @required
   */
  protected $checksum;

  /**
   * campaign
   * @var string
   */
  protected $campaign;

  /**
   * medium
   * @var string
   */
  protected $medium;

  /**
   * source
   * @var string
   */
  protected $source;

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  public function _run(Result $result): void {
    $validation = Contact::validateChecksum(FALSE)
      ->setContactId($this->contact_id)
      ->setChecksum($this->checksum)
      ->execute()->first()['valid'];
    if (!$validation) {
      throw new \CRM_Core_Exception("Checksum validation failed for Contact ID {$this->contact_id}.");
    }

    $emailID = Email::get(FALSE)
      ->addWhere('email', '=', $this->email)
      ->addWhere('contact_id', '=', $this->contact_id)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()->first()['id'] ?? NULL;
    if (!$emailID) {
      throw new \CRM_Core_Exception("Provided email is not the primary email for Contact ID {$this->contact_id}.");
    }

    $activity = Activity::create(FALSE)
      ->addValue('source_record_id', $emailID)
      ->addValue('source_contact_id', $this->contact_id)
      ->addValue('subject', $this->email)
      ->addValue('activity_tracking.activity_campaign', $this->campaign)
      ->addValue('activity_tracking.activity_medium', $this->medium)
      ->addValue('activity_tracking.activity_source', $this->source)
      ->addValue('activity_type_id', 220)
      ->execute()->first();
    if (!$activity) {
      throw new \CRM_Core_Exception("Activity create failed for Contact ID {$this->contact_id}.");
    }

    $result[] = ['id' => $activity['id']];
  }

}
