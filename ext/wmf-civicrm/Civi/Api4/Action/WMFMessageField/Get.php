<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Action\WMFMessageField;

use Civi\WMFQueueMessage\Message;

/**
 * Get the fields supported by the WMFMessage classes.
 *
 * @searchable secondary
 */
class Get extends \Civi\Api4\Generic\BasicGetAction {

  /**
   * Returns all APIv4 entities from core & enabled extensions.
   */
  protected function getRecords(): array {
    $message = new Message([]);
    return $message->getAvailableFields();
  }

}
