<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\WMFException\WMFException;
use Civi\Api4\WMFContact;

class EmailPreferencesQueueConsumer extends QueueConsumer {

  /**
   * Validate and store messages from the e-mail preferences queue
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  function processMessage(array $message): void {
    try {
      $result = WMFContact::UpdateCommunicationsPreferences()
          ->setEmail($message['email'] ?? null)
          ->setContactID($message['contact_id'] ?? null)
          ->setChecksum($message['checksum'] ?? null)
          ->setCountry($message['country'] ?? null)
          ->setLanguage($message['language'] ?? null)
          ->setSnoozeDate($message['snooze_date'] ?? null)
          ->setSendEmail($message['send_email'] ?? null)
          ->execute();

      if (!$result->first()) {
        Civi::log('wmf')->info(
          'No records updated from e-mail preferences message with checksum ' .
          "{$message['checksum']} and contact_id {$message['contact_id']}."
        );
      }
    }
    catch (\CRM_Core_Exception $e) {
      // TODO Temporarily just throwing a WMFException; See T279962.
      throw new WMFException(
        WMFException::INVALID_MESSAGE,
        'failed to update e-mail preferences message:' . $e->getMessage()
      );
    }
  }
}
