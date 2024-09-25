<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\WMFException\WMFException;

class EmailPreferencesQueueConsumer extends QueueConsumer {

  /**
   * Validate and store messages from the e-mail preferences queue
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  function processMessage(array $message) {
    try {
      $result = civicrm_api3('Preferences', 'create', $message);
      if ($result['count'] !== 1) {
        Civi::log('wmf')->info(
          "No records updated from e-mail preferences message with checksum " .
          "{$message['checksum']} and contact_id {$message['contact_id']}."
        );
      }
    }
    catch (\CRM_Core_Exception $e) {
      // TODO Temporarily just throwing a WMFException; See T279962.
      throw new WMFException(
        WMFException::INVALID_MESSAGE,
        'Invalid data in e-mail preferences message.'
      );
    }
  }

}
