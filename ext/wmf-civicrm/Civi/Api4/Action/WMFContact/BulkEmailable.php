<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Check if an email address can be sent bulk emails.
 * In theory, this should match opt in/out status in Acoustic.
 *
 * @method $this setEmail(string $email)
 * @method $this setCheckSnooze(bool $checkSnooze)
 *
 * */
class BulkEmailable extends AbstractAction {
  /**
   * @var string
   * @required
   */
  protected $email;

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
    $emails = Email::get(FALSE)
      ->addSelect(
        'is_primary',
        'on_hold',
        'contact_id.is_opt_out',
        'contact_id.do_not_email',
        'contact_id.Communication.opt_in',
        'contact_id.Communication.do_not_solicit',
        'contact_id.is_deleted',
        'email_settings.snooze_date'
      )
      ->addWhere('email', '=', $this->email)
      ->execute();
    if ($emails->count() == 0) {
      throw new \CRM_Core_Exception('Email not found.');
    }
    $anyPrimary = FALSE;
    foreach ($emails as $email) {
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
      if ($email['is_primary'] && !$email['contact_id.is_deleted']) {
        $anyPrimary = TRUE;
      }
    }
    $result[] = $anyPrimary;
  }

}
