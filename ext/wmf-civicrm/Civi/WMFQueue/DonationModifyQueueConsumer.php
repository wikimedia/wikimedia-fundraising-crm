<?php

namespace Civi\WMFQueue;

use Civi\ExchangeRates\ExchangeRatesException;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\Contribution as ContributionHelper;
use Civi\WMFQueueMessage\DonationModifyMessage;

class DonationModifyQueueConsumer extends TransactionalQueueConsumer {

  /**
   * @inheritDoc
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws WMFException|ExchangeRatesException
   */
  public function processMessage(array $message): void {
    $messageObject = new DonationModifyMessage($message);
    $messageObject->validate();

    if ($messageObject->isCancelled()) {
      ContributionHelper::updateCancelledContribution($messageObject);
      return;
    }

    throw new WMFException(WMFException::INVALID_MESSAGE, 'Unknown modification type');
  }

}
