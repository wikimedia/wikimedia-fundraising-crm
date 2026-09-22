<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Check if an email address can be sent bulk emails.
 * In theory, this should match opt in/out status in Acoustic.
 *
 * Only primary emails count, as those are the ones Acoustic mails.
 *
 * Considers every contact holding the address as their primary, since Acoustic mails the
 * address, unless setContactID() narrows it to one. With only a contact ID, checks that
 * contact's primary.
 *
 * @method $this setEmail(?string $email)
 * @method $this setContactID(?int $contactID)
 * @method $this setCheckSnooze(bool $checkSnooze)
 *
 * */
class BulkEmailable extends AbstractAction {
  /**
   * @var string|null
   */
  protected $email;

  /**
   * @var int|null
   */
  protected $contactID;

  /**
   * @var bool
   */
  protected $checkSnooze = TRUE;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function _run(Result $result): void {
    if (!$this->email && !$this->contactID) {
      throw new \CRM_Core_Exception('Either email or contactID is required.');
    }
    $emailGet = Email::get(FALSE)
      ->addSelect(
        'is_primary',
        'on_hold',
        'contact_id.is_opt_out',
        'contact_id.do_not_email',
        'contact_id.Communication.opt_in',
        'contact_id.Communication.do_not_solicit',
        'contact_id.is_deleted',
        'email_settings.snooze_date'
      );
    if ($this->email) {
      $emailGet->addWhere('email', '=', $this->email);
    }
    if ($this->contactID) {
      $emailGet->addWhere('contact_id', '=', $this->contactID);
    }
    $emails = $emailGet->execute();
    if ($emails->count() == 0) {
      throw new \CRM_Core_Exception('Email not found.');
    }
    $anyPrimary = FALSE;
    foreach ($emails as $email) {
      // Only a primary email reaches Acoustic, so a secondary row says nothing about
      // whether the address is deliverable.
      if (!$email['is_primary'] || $email['contact_id.is_deleted']) {
        continue;
      }
      if ($email['on_hold'] ||
        $email['contact_id.is_opt_out'] ||
        $email['contact_id.do_not_email'] ||
        $email['contact_id.Communication.do_not_solicit'] ||
        $email['contact_id.Communication.opt_in'] === FALSE ||
        ($this->checkSnooze &&
          $email['email_settings.snooze_date'] &&
          ($email['email_settings.snooze_date'] > gmdate("Y-m-d"))
        )
      ) {
        $result[] = FALSE;
        return;
      }
      $anyPrimary = TRUE;
    }
    $result[] = $anyPrimary;
  }

}
