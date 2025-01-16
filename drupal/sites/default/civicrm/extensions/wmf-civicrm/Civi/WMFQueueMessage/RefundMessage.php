<?php

namespace Civi\WMFQueueMessage;

use Civi\WMFException\WMFException;

/**
 * Refund Message Class.
 *
 * The goal of this class (& other message classes) is to interpret the incoming message
 * such that the Consumer class is responsible for processing & the message class handles
 * interpretation.
 *
 * It may make sense for this to extend Donation Message but let's start
 * with only what we need.
 */
class RefundMessage extends Message {

  /**
   * @throws \Civi\WMFException\WMFException
   */
  public function getContributionStatus(): string {
    $validTypes = [
      'refund' => 'Refunded',
      'chargeback' => 'Chargeback',
      'cancel' => 'Cancelled',
      // from the audit processor
      'reversal' => 'Chargeback',
      // raw IPN code
      'admin_fraud_reversal' => 'Chargeback',
    ];

    if (!array_key_exists($this->message['type'], $validTypes)) {
      throw new WMFException(WMFException::IMPORT_CONTRIB, "Unknown refund type '{$this->message['type']}'");
    }
    return $validTypes[$this->message['type']];
  }

}
