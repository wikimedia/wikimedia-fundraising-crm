<?php

namespace Civi\WMFQueueMessage;

use Civi\API\EntityLookupTrait;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;

class Message {

  use EntityLookupTrait;

  /**
   * WMF message with keys relevant to the message.
   *
   * This is an incomplete list of parameters used in the past
   * but it should be message specific.
   *
   *  - recurring
   *  - contribution_recur_id
   *  - subscr_id
   *  - recurring_payment_token
   *  - date
   *  - thankyou_date
   *  - utm_medium
   *
   * @var array
   */
  protected array $message;

  /**
   * Contribution Tracking ID.
   *
   * This contains the ID of the contribution Tracking record if it was looked up
   * or set from external code rather than passed in. We keep the original $message array unchanged but
   * track the value here to avoid duplicate lookups.
   *
   * @var int|null
   */
  protected ?int $contributionTrackingID;

  /**
   * Constructor.
   */
  public function __construct(array $message) {
    $this->message = $message;
    foreach ($this->message as $key => $input) {
      if (is_string($input)) {
        $this->message[$key] = trim($input);
      }
    }
  }

  /**
   * Set the contribution tracking ID.
   *
   * This would be used when the calling code has created a missing contribution
   * tracking ID.
   *
   * @param int|null $contributionTrackingID
   * @return void
   */
  public function setContributionTrackingID(?int $contributionTrackingID): void{
    $this->contributionTrackingID = $contributionTrackingID;
  }

  protected function cleanMoney($value): float {
    return (float) str_replace(',', '', $value);
  }

  /**
   * Round the number based on currency.
   *
   * Note this could also be done using code that ships with Civi (BrickMoney)
   * or \Civi::format() functions - we use a thin wrapper so if we ever change
   * we can change it here only.
   *
   * @param float $amount
   * @param string $currency
   *
   * @return string
   */
  protected function round(float $amount, string $currency): string {
    return CurrencyRoundingHelper::round($amount, $currency);
  }

  public function getContactID(): ?int {
    return !empty($this->message['contact_id']) ? (int) $this->message['contact_id'] : NULL;
  }

  /**
   * Get the recurring contribution ID if it already exists.
   *
   * @return int|null
   */
  public function getContributionRecurID(): ?int {
    return !empty($this->message['contribution_recur_id']) ? (int) $this->message['contribution_recur_id'] : NULL;
  }

  /**
   * @param string $value
   *   The value to fetch, in api v4 format (e.g supports contribution_status_id:name).
   *
   * @return mixed|null
   * @noinspection PhpDocMissingThrowsInspection
   * @noinspection PhpUnhandledExceptionInspection
   */
  public function getExistingContributionRecurValue(string $value) {
    if (!$this->getContributionRecurID()) {
      return NULL;
    }
    if (!$this->isDefined('ContributionRecur')) {
      $this->define('ContributionRecur', 'ContributionRecur', ['id' => $this->getContributionRecurID()]);
    }
    return $this->lookup('ContributionRecur', $value);
  }

  /**
   * Convert currency.
   *
   * This is a thin wrapper around our external function.
   *
   * @param string $currency
   * @param float $amount
   * @param int|null $timestamp
   *
   * @return float
   * @throws \Civi\ExchangeException\ExchangeRatesException
   */
  protected function currencyConvert(string $currency, float $amount, ?int $timestamp = NULL): float {
    return (float) exchange_rate_convert($currency, $amount, $timestamp ?: $this->getTimestamp());
  }

  /**
   * Get the time stamp for the message.
   *
   * @return int
   */
  public function getTimestamp(): int {
    return time();
  }

  public function isAmazon(): bool {
    return $this->isGateway('amazon');
  }

  public function isPaypal(): bool {
    return $this->isGateway('paypal') || $this->isGateway('paypal_ec');
  }

  public function isFundraiseUp(): bool {
    return $this->isGateway('fundraiseup');
  }

  /**
   * Is this a recurring payment which the provider has been able to 'rescue'.
   *
   * Adyen is able to get the donor's failing recurring back on track in some
   * cases - these manifest as an auto-rescue.
   *
   * @return bool
   */
  public function isAutoRescue(): bool {
    return isset($this->message['is_successful_autorescue']) && $this->message['is_successful_autorescue'];
  }

  public function isGateway(string $gateway): bool {
    return $this->getGateway() === $gateway;
  }

  public function getGateway(): string {
    return trim($this->message['gateway']);
  }

  /**
   * Get the contribution tracking ID if it already exists.
   *
   * @return int|null
   */
  public function getContributionTrackingID(): ?int {
    if (isset($this->contributionTrackingID)) {
      return $this->contributionTrackingID;
    }
    return !empty($this->message['contribution_tracking_id']) ? (int) $this->message['contribution_tracking_id'] : NULL;
  }

}
