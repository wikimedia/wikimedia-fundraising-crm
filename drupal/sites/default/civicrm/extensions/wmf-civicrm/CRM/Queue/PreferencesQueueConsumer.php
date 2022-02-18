<?php

use Civi\WMFException\WMFException;
use wmf_common\WmfQueueConsumer;

class CRM_Queue_PreferencesQueueConsumer extends WmfQueueConsumer {

  /**
   * Validate and store messages from the e-mail preferences queue
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  function processMessage($message) {

    try {
      $result = civicrm_api3('Preferences', 'create', $message);
      if ($result['count'] !== 1) {
        Civi::log('wmf')->info(
          "No records updated from e-mail preferences message with checksum " .
          "{$message['checksum']} and contact_id {$message['contact_id']}."
        );
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      // TODO Temporarily just throwing a WMFException; See T279962.
      throw new WMFException(
        WMFException::INVALID_MESSAGE,
        'Invalid data in e-mail preferences message.'
      );
    }
  }

}
