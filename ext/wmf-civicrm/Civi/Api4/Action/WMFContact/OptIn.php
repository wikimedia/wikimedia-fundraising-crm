<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Reverse all opt-out states, including snooze unless setCheckSnooze(FALSE) is called.
 *
 * @method $this setEmail(string $email)
 * @method $this setCheckSnooze(bool $checkSnooze)
 *
 * */
class OptIn extends AbstractAction {
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
   */
  public function _run(Result $result): void {
    Contact::update(FALSE)
      ->addValue('is_opt_out', FALSE)
      ->addValue('do_not_email', FALSE)
      ->addValue('Communication.do_not_solicit', FALSE)
      ->addValue('Communication.opt_in', TRUE)
      ->addWhere('email_primary.email', '=', $this->email)
      ->addClause('OR',
        ['is_opt_out', '=', TRUE],
        ['do_not_email', '=', TRUE],
        ['Communication.do_not_solicit', '=', TRUE],
        ['Communication.opt_in', '=', FALSE]
      )
      ->execute();
    Email::update(FALSE)
      ->addValue('on_hold', FALSE)
      ->addWhere('email', '=', $this->email)
      ->addWhere('on_hold', '=', TRUE)
      ->execute();

    if ($this->checkSnooze) {
      // Shortened to tomorrow, the change is pushed to Acoustic by the omnimail_civicrm_customPre hook.
      Email::update(FALSE)
        ->addValue('email_settings.snooze_date', date('Y-m-d', strtotime('+1 day')))
        ->addWhere('email', '=', $this->email)
        ->addWhere('email_settings.snooze_date', '>', date('Y-m-d', strtotime('+1 day')))
        ->execute();
    }
  }

}
