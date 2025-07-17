<?php

namespace Civi\WMFQueueMessage;

class SettleMessage extends DonationMessage {

  /**
   * WMF Settlement message.
   *
   * @var array{
   *    gateway: string,
   *    gateway_txn_id: string,
   *    settled_date: string,
   *    currency: string,
   *    gross: float|string|int,
   *    settled_currency: string,
   *    fee: string
   *    }
   */
  protected array $message;

  protected bool $isRestrictToSupportedFields = TRUE;

  /**
   * Subset of the Message available fields required for this Message Type.
   *
   * @var array|string[]
   */
  protected array $requiredFields = [
    'gateway',
    'gateway_txn_id',
    'gross',
    'settled_date',
  ];

  public function getSettledDate() {
    return $this->message['settled_date'];
  }

  /**
   * Are we dealing with a message that had a currency other than our settlement currency.
   */
  public function isExchangeRateConversionRequired(): bool {
    return FALSE;
  }

}
