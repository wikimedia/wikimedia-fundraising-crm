<?php

namespace Civi\WMFException;

class DuplicateContactException extends \CRM_Core_Exception {

  public function getDuplicateContacts(): array {
    return (array) $this->getErrorData()['contacts'];
  }

}
