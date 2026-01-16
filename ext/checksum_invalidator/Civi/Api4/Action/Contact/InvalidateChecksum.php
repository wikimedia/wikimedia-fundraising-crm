<?php

namespace Civi\Api4\Action\Contact;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Contact;
use Civi\Api4\InvalidChecksum;

/**
 * Invalidate a checksym.
 *
 * @method $this setContactId(int $contact_id)
 * @method $this setChecksum(string $checksum)
 *
 * */
class InvalidateChecksum extends AbstractAction {

  /**
   * contactID
   * @var int
   * @required
   */
  protected $contactId;

  /**
   * checksum
   * @var string
   * @required
   */
  protected $checksum;

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  public function _run(Result $result): void {
    $validation = Contact::validateChecksum(FALSE)
      ->setContactId($this->contactId)
      ->setChecksum($this->checksum)
      ->execute()->first()['valid'];
    if (!$validation) {
      return;
    }

    $input = \CRM_Utils_System::explode('_', $this->checksum, 3);
    $inputTS = $input[1] ?? NULL;
    $inputLF = $input[2] ?? NULL;
    $expiry = ($inputLF == 'inf') ? NULL : date('Y-m-d H:i:s', ($inputTS + ($inputLF * 60 * 60)));

    $result[] = InvalidChecksum::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('checksum', $this->checksum)
      ->addValue('expiry', $expiry)
      ->execute();
  }

}
