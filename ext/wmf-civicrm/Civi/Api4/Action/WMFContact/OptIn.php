<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Reverse all opt-out states, including snooze unless setCheckSnooze(FALSE) is called.
 *
 * Applies to every contact with the primary email unless setContactID() restricts it to one.
 * With only a contact ID, opts in that contact only.
 *
 * @method $this setEmail(?string $email)
 * @method $this setContactID(?int $contactID)
 * @method $this setCheckSnooze(bool $checkSnooze)
 *
 * */
class OptIn extends AbstractAction {
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
   */
  public function _run(Result $result): void {
    if (!$this->email && !$this->contactID) {
      throw new \CRM_Core_Exception('Either email or contactID is required.');
    }
    $contactUpdate = Contact::update(FALSE)
      ->addValue('is_opt_out', FALSE)
      ->addValue('do_not_email', FALSE)
      ->addValue('Communication.do_not_solicit', FALSE)
      ->addValue('Communication.opt_in', TRUE)
      ->addWhere('is_deleted', '=', FALSE)
      ->addClause('OR',
        ['is_opt_out', '=', TRUE],
        ['do_not_email', '=', TRUE],
        ['Communication.do_not_solicit', '=', TRUE],
        ['Communication.opt_in', '=', FALSE],
        ['Communication.opt_in', 'IS NULL']
      );
    // On hold is a property of the address, so un-hold all emails even secondary unless we have a contact ID.
    $onHoldUpdate = Email::update(FALSE)
      ->addValue('on_hold', FALSE)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addWhere('on_hold', '=', TRUE);
    // Snooze is not unset on secondary emails (though it has no effect anyways).
    // Shortened to tomorrow, the change is pushed to Acoustic by the omnimail_civicrm_customPre hook.
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $snoozeUpdate = Email::update(FALSE)
      ->addValue('email_settings.snooze_date', $tomorrow)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addWhere('is_primary', '=', TRUE)
      ->addWhere('email_settings.snooze_date', '>', $tomorrow);
    if ($this->email) {
      $contactUpdate->addWhere('email_primary.email', '=', $this->email);
      $onHoldUpdate->addWhere('email', '=', $this->email);
      $snoozeUpdate->addWhere('email', '=', $this->email);
    }
    if ($this->contactID) {
      $contactUpdate->addWhere('id', '=', $this->contactID);
      $onHoldUpdate->addWhere('contact_id', '=', $this->contactID)->addWhere('is_primary', '=', TRUE);
      $snoozeUpdate->addWhere('contact_id', '=', $this->contactID);
    }
    $contactUpdate->execute();
    $onHoldUpdate->execute();
    if ($this->checkSnooze) {
      $snoozeUpdate->execute();
    }
  }

}
