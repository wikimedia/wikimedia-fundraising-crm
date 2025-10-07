<?php

namespace Civi\WMFQueueMessage;

class SettleMessage extends DonationMessage {

  /**
   * WMF Settlement message.
   *
   * @var array{
   *    gateway: string,
   *    gateway_txn_id: string,
   *    contribution_id: int,
   *    settled_date: string,
   *    settled_currency: string,
   *    settlement_batch_reference: string,
   *    settled_total_amount: float|string|int,
   *    settled_fee_amount: float|string|int,
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
    'settled_date',
    'settled_total_amount',
    'contribution_id',
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
    return $this->message['settlement_batch_reference'] ?? '';
  }

  /**
   * Get the amount of the donation in the currency it is settled in.
   *
   * @return float
   */
  public function getSettledAmount(): float {
    return $this->message['settled_total_amount'];
  }

  /**
   * Get the fee amount charged by the processing gateway, when available
   */
  public function getSettledFeeAmount(): float {
    return $this->message['settled_fee_amount'] ?: 0.0;
  }

  public function getContributionID(): int {
    return $this->message['contribution_id'];
  }

}
