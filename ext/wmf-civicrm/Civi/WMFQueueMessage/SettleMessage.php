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
   *    gross: float|string|int,
   *    settled_currency: string,
   *    fee: string,
   *    settlement_batch_reference: string,
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

  public function getSettledDate(): string {
    return $this->message['settled_date'];
  }

  /**
   * Are we dealing with a message that had a currency other than our settlement currency.
   */
  public function isExchangeRateConversionRequired(): bool {
    return FALSE;
  }

  /**
   * Get the currency the donation is settled into at the gateway.
   */
  public function getSettlementCurrency(): string {
    return $this->message['settled_currency'] ?? 'USD';
  }

  public function getSettlementBatchReference(): string {
    return $this->message['batch_reference'] ?? '';
  }

  /**
   * Get the amount of the donation in the currency it is settled in.
   *
   * @return float
   */
  public function getSettledAmount(): float {
    return $this->message['gross'];
  }

}
