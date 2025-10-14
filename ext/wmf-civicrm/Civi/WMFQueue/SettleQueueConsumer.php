<?php

namespace Civi\WMFQueue;

use Civi\Api4\Action\WMFAudit\Settle;
use Civi\Api4\WMFAudit;
use Civi\WMFQueueMessage\SettleMessage;

class SettleQueueConsumer extends QueueConsumer {

  /**
   * Processes an individual opt-in message. The message just needs
   * the email address. We find all contacts with that email as their
   * primary address and set the opt_in field.
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   */
  public function processMessage(array $message): void {
    WMFAudit::settle(FALSE)
      ->setValues($message)
      ->execute();
  }

}
