<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Contribution;
use Civi\WMFException\WMFException;

class DonationModifyMessage extends DonationMessage {

  public function isCancelled(): bool {
    return $this->message['contribution_status_id:name'] === 'Cancelled';
  }

  public function getReason(): string {
    return $this->message['reason'];
  }

  public function canRetry(): bool {
    return $this->message['can_retry'];
  }

  public function getContribution(): array {
    return Contribution::get(FALSE)
      ->addWhere('contribution_extra.gateway', '=', $this->getGateway())
      ->addWhere('contribution_extra.gateway_txn_id', '=', $this->message['gateway_txn_id'])
      ->execute()->first();
  }

  /**
   * @throws \Civi\WMFException\WMFException
   */
  public function validate(): void {
    if (!$this->getGateway() || !$this->message['gateway_txn_id']) {
      throw new WMFException(WMFException::INVALID_MESSAGE, 'Invalid message type');
    }
  }
}
