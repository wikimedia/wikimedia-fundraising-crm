<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Omnicontact;
use Civi\Api4\Omniphone;
use Civi\Api4\WMFContact;

/**
 * Opt a phone number into the Acoustic SMS program.
 *
 * This is run as a queued background task (see Save::optInToSMSProgram())
 * rather than inline during donation import.
 *
 * @method $this setEmail(string $email)
 * @method string getEmail()
 * @method $this setPhoneNumber(string $phoneNumber)
 * @method string getPhoneNumber()
 */
class OptInSmsProgram extends AbstractAction {

  /**
   * @var string
   * @required
   */
  protected $email;

  /**
   * @var string
   * @required
   */
  protected $phoneNumber;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    // We don't want to change the opt out status of the contact in Acoustic,
    // so check the status in Civi first so we can send opt out if opted out.
    $isEmailable = WMFContact::bulkEmailable(FALSE)
      ->setEmail($this->email)
      ->setCheckSnooze(FALSE)
      ->execute()->first();

    $acousticValues = ['mobile_phone' => $this->phoneNumber];
    if (!$isEmailable) {
      $acousticValues['is_opt_out'] = TRUE;
    }

    // Add the contact to Acoustic first, matching by email, so the
    // mobile-originated message below doesn't create an orphan.
    Omnicontact::create(FALSE)
      ->setEmail($this->email)
      ->setValues($acousticValues)
      ->execute();

    Omniphone::optIn(FALSE)
      ->setPhoneNumber($this->phoneNumber)
      ->execute();
  }

}
