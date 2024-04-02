<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Name;
use Civi\WMFHelper\FinanceInstrument;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFException\WMFException;
use Civi\ExchangeException\ExchangeRatesException;

class DonationMessage extends Message {

  protected array $parsedName;

  /**
   * Is it recurring - we would be using the child class if it is.
   *
   * @return bool
   */
  public function isRecurring(): bool {
    return FALSE;
  }

  public function isInvalidRecurring(): bool {
    return FALSE;
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithSubscriberID(): bool {
    return FALSE;
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithPaymentToken(): bool {
    return FALSE;
  }

  /**
   * @param array $message
   *
   * @return \Civi\WMFQueueMessage\DonationMessage|\Civi\WMFQueueMessage\RecurDonationMessage
   */
  public static function getWMFMessage($message) {
    if (!empty($message['recurring']) || !empty($message['contribution_recur_id'])) {
      $messageObject = new RecurDonationMessage($message);
    }
    else {
      $messageObject = new DonationMessage($message);
    }
    return $messageObject;
  }

  /**
   * Get the time stamp for the message.
   *
   * @return int
   */
  public function getTimestamp(): int {
    $date = $this->message['date'] ?? NULL;
    if (is_numeric($date)) {
      return $date;
    }
    if (!$date) {
      // Fall back to now.
      return time();
    }
    try {
      // Convert strings to Unix timestamps.
      return $this->parseDateString($date);
    }
    catch (\Exception $e) {
      \Civi::log('wmf')->debug('wmf_civicrm: Could not parse date: {date} from {id}', [
        'date' => $this->message['date'],
        'id' => $this->message['contribution_tracking_id'],
      ]);
      // Fall back to now.
      return time();
    }
  }

  /**
   * Normalize the queued message
   *
   * The goal is to break this up into multiple functions (mostly of the
   * getFinancialTypeID() nature)  now that it has been moved.
   *
   * @return array
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function normalize(): array {
    $msg = $this->message;

    // defaults: Keys that aren't actually required, but which will cause some
    // portion of the code to complain if they don't exist (even if they're
    // blank). Note that defaults for name fields are applied a bit further on,
    // after any full_name is parsed
    // FIXME: don't use defaults.  Access msg properties using a functional interface.
    $defaults = [
      'organization_name' => '',
      'email' => '',
      'street_address' => '',
      'supplemental_address_1' => '',
      'supplemental_address_2' => '',
      'city' => '',
      'country' => '',
      'state_province' => '',
      'postal_code' => '',
      'postmark_date' => NULL,
      'check_number' => NULL,
      'recurring' => NULL,
      'utm_campaign' => NULL,
      'effort_id' => NULL,
      'soft_credit_to' => NULL,
      'soft_credit_to_id' => NULL,
    ];
    $msg = $msg + $defaults;

    $this->removeKnownBadStringsFromAddressFields($msg);

    $msg['financial_type_id'] = $this->getFinancialTypeID();
    $msg['contribution_recur_id'] = $this->getContributionRecurID();
    $msg['contact_id'] = $this->getContactID();
    $msg['payment_instrument_id'] = $this->getPaymentInstrumentID();
    $msg['date'] = $this->getTimestamp();
    $msg['thankyou_date'] = $this->getThankYouDate();
    $parsed = $this->getParsedName();
    if (!empty($parsed)) {
      $msg = array_merge(array_filter((array) $parsed), $msg);
      $msg['addressee_custom'] = $msg['full_name'];
    }

    $contactFields = [
      'first_name' => $this->getFirstName(),
      'last_name' => $this->getLastName(),
      'middle_name' => $this->getMiddleName(),
      'language' => $this->getLanguage(),
    ];
    foreach ($contactFields as $name => $contactField) {
      if ($contactField) {
        $msg[$name] = $contactField;
      }
      else {
        unset($msg[$name]);
      }
    }
    // Check for special flags
    // TODO: push non-generic cases into database
    if (!empty($msg['utm_campaign'])) {
      $directMailOptions = wmf_civicrm_get_options('Contribution', wmf_civicrm_get_custom_field_name('Appeal'));
      if (!array_key_exists($msg['utm_campaign'], $directMailOptions)) {
        // @todo - I am hoping to replace with an api call but need more clarity as this doesn't work yet.
        // Contribution::getFields(FALSE)->setLoadOptions(TRUE)->->addWhere('field_name', '=', 'Gift_Data:Campaign')
        wmf_civicrm_ensure_option_value_exists(wmf_civicrm_get_direct_mail_field_option_id(), $msg['utm_campaign']);
      }
      $msg['direct_mail_appeal'] = $msg['utm_campaign'];
    }

    if ($this->isEndowmentGift()) {
      $msg['restrictions'] = 'Endowment Fund';
      $msg['gift_source'] = 'Online Gift';
    }

    // set the correct amount fields/data and do exchange rate conversions.
    // If there is anything fishy about the amount...
    if (!$this->getOriginalAmount() || !$this->getOriginalCurrency()) {
      // just... don't
      \Civi::log('wmf')->info('wmf_civicrm: Not freaking out about non-monetary message.');
      return $msg;
    }
    if ($this->isExchangeRateConversionRequired()) {
      \Civi::log('wmf')->info('wmf_civicrm: Converting to settlement currency: {old} -> {new}',
        ['old' => $msg['currency'], 'new' => $this->getSettlementCurrency()]);
    }

    $msg['original_gross'] = $this->getOriginalAmount();
    $msg['original_currency'] = $this->getOriginalCurrency();;
    $msg['currency'] = $this->getSettlementCurrency();
    $msg['fee'] = $this->getUsdFeeAmountRounded();
    $msg['gross'] = $this->getUsdAmountRounded();
    $msg['net'] = $this->getUsdNetAmountRounded();

    return $msg;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function getFirstName(): string {
    if (!empty($this->message['first_name'])) {
      return $this->message['first_name'];
    }
    if (!empty($this->getParsedName())) {
      return $this->getParsedName()['first_name'] ?? '';
    }
    if ($this->getContactID()) {
      return '';
    }
    // Historically we have set Anonymous here but
    // Only if both first & last are empty.
    // It's probably something we could stop doing.
    if (empty($this->message['last_name'])) {
      return 'Anonymous';
    }
    return '';
  }

  public function getLastName(): string {
    if (!empty($this->message['last_name'])) {
      return $this->message['last_name'];
    }
    if (!empty($this->getParsedName())) {
      return $this->getParsedName()['last_name'] ?? '';
    }
    return '';
  }

  public function getMiddleName(): string {
    if (!empty($this->message['middle_name'])) {
      return $this->message['middle_name'];
    }
    if (!empty($this->getParsedName())) {
      return $this->getParsedName()['middle_name'] ?? '';
    }
    return '';
  }

  public function getLanguage(): string {
    if (!empty($this->message['language'])) {
      $isES419 = strtolower($this->message['language']) === 'es-419';
      // Front-end uses es-419 to represent Latin American Spanish but
      // CiviCRM needs to store it as a 5 char string. We choose es_MX.
      return $isES419 ? 'es_MX' : $this->message['language'];
    }
    return '';
  }

  /**
   * Is the donation an endowment gift.
   *
   * @return bool
   */
  public function isEndowmentGift(): bool {
    return isset($this->message['utm_medium']) && $this->message['utm_medium'] === 'endowment';
  }

  /**
   * Get the currency remitted by the donor.
   *
   * @return string
   */
  public function getOriginalCurrency(): string {
    return $this->message['original_currency'] ?? $this->message['currency'];
  }

  /**
   * Get the original remitted amount in original currency.
   *
   * @return string
   */
  public function getOriginalAmount(): string {
    return !empty($this->message['original_gross']) ? $this->cleanMoney($this->message['original_gross']) : $this->cleanMoney($this->message['gross'] ?? 0);
  }

  /**
   * Get the currency the donation is settled in.
   *
   * Currency it is always converted to USD.
   */
  public function getSettlementCurrency(): string {
    return 'USD';
  }

  /**
   * Get the donation amount as we receive it in the settled currency.
   */
  public function getUsdAmount(): float {
    return $this->cleanMoney($this->message['gross'] ?? 0) * $this->getConversionRate();
  }

  public function getUsdAmountRounded(): string {
    return $this->round($this->getUsdAmount(), $this->getSettlementCurrency());
  }

  /**
   * Get the fee amount charged by the processing gateway, when available
   */
  public function getUsdFeeAmount(): float {
    if (array_key_exists('fee', $this->message) && is_numeric($this->message['fee'])) {
      return $this->cleanMoney($this->message['fee']) * $this->getConversionRate();
    }
    if (array_key_exists('net', $this->message) && is_numeric($this->message['net'])) {
      return $this->getUsdAmount() - $this->getUsdNetAmount();
    }
    return 0.00;
  }

  public function getUsdFeeAmountRounded(): string {
    return $this->round($this->getUsdFeeAmount(), $this->getSettlementCurrency());
  }

  /**
   * Get amount less any fee charged by the processor.
   */
  public function getUsdNetAmount(): float {
    if (array_key_exists('net', $this->message) && is_numeric($this->message['net'])) {
      return $this->cleanMoney($this->message['net']) * $this->getConversionRate();
    }
    if (array_key_exists('fee', $this->message) && is_numeric($this->message['fee'])) {
      return $this->getUsdAmount() - $this->getUsdFeeAmount();
    }
    return $this->getUsdAmount();
  }

  public function getUsdNetAmountRounded(): string {
    return $this->round($this->getUsdNetAmount(), $this->getSettlementCurrency());
  }

  /**
   * Remove known bad strings from address.
   *
   * This function focuses on specific forms of bad data with high
   * prevalence in the fields we see them in.
   *
   * @param array $msg
   */
  private function removeKnownBadStringsFromAddressFields(&$msg) {
    // Remove known dummy data.
    if ($msg['street_address'] === 'N0NE PROVIDED') {
      $msg['street_address'] = '';
    }

    $invalidAddressStrings = ['0', 'City/Town', 'NoCity', 'City'];
    foreach (['postal_code', 'city'] as $fieldName) {
      if (in_array($msg[$fieldName], $invalidAddressStrings)) {
        $msg[$fieldName] = '';
      }
    }

    // Filter out unexpected characters from postal codes.
    // This filter should allow through all postal code formats
    // listed here https://github.com/unicode-org/cldr/blob/release-26-0-1/common/supplemental/postalCodeData.xml
    if (isset($msg['postal_code'])) {
      $msg['postal_code'] = preg_replace(
        '/[^a-z0-9\s\-]+/i',
        '',
        $msg['postal_code']
      );
    }
  }

  /**
   * Run strtotime in UTC
   *
   * @param string $date Random date format you hope is parseable by PHP, and is
   * in UTC.
   *
   * @return int Seconds since Unix epoch
   * @throws \Exception
   */
  private function parseDateString(string $date): ?int {
    // Funky hack to trim decimal timestamp.  More normalizations may follow.
    $text = preg_replace('/^(@\d+)\.\d+$/', '$1', $date);
    return (new \DateTime($text, new \DateTimeZone('UTC')))->getTimestamp();
  }

  /**
   *
   * @return array
   */
  public function getThankYouDate(): ?int {
    if (empty($this->message['thankyou_date'])) {
      return NULL;
    }
    if (is_numeric($this->message['thankyou_date'])) {
      return $this->message['thankyou_date'];
    }
    try {
      return $this->parseDateString($this->message['thankyou_date']);
    }
    catch (\Exception $e) {
      \Civi::log('wmf')->debug('wmf_civicrm: Could not parse thankyou date: {date} from {id}', [
        'date' => $this->message['thankyou_date'],
        'id' => $this->message['contribution_tracking_id'],
      ]);
    }
    return NULL;
  }

  /**
   * Get the financial Type ID.
   *
   * @return int
   */
  public function getFinancialTypeID(): int {
    if ($this->isEndowmentGift()) {
      return (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    }
    if (!empty($this->message['financial_type_id'])) {
      if (is_numeric($this->message['financial_type_id'])) {
        return (int) $this->message['financial_type_id'];
      }
      return (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', $this->message['financial_type_id']);
    }
    // @todo - can these first 2 be safely moved to the RecurringMessage child class?
    if ($this->getContributionRecurID()) {
      return ContributionRecur::getFinancialType($this->getContributionRecurID());
    }
    if ($this->isRecurring()) {
      // No contribution recur record yet -> Recurring Gift is used for the first in the series,
      // Recurring Gift - Cash thereafter.
      return ContributionRecur::getFinancialTypeForFirstContribution();
    }
    return (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash');
  }

  /**
   * Validate the message
   *
   * @return void
   * @throws \Civi\WMFException\WMFException
   */
  public function validate(): void {}

  /**
   * Get the rate to convert the currency using.
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function getConversionRate(): float {
    if (!$this->isExchangeRateConversionRequired()) {
      return 1;
    }
    try {
      return (float) $this->currencyConvert($this->getOriginalCurrency(), 1, $this->getTimestamp()) / $this->currencyConvert($this->getSettlementCurrency(), 1, $this->getTimestamp());
    }
    catch (ExchangeRatesException $e) {
      throw new WMFException(WMFException::INVALID_MESSAGE, "UNKNOWN_CURRENCY: '{$this->getOriginalCurrency()}': " . $e->getMessage());
    }
  }

  /**
   * Are we dealing with a message that had a currency other than our settlement currency.
   */
  public function isExchangeRateConversionRequired(): bool {
    return $this->message['currency'] !== $this->getSettlementCurrency();
  }

  /**
   * Get the name parts parsed.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getParsedName(): ?array {
    if (!isset($this->parsedName)) {
      if (empty($this->message['full_name']) || !empty($this->message['first_name']) || !empty($this->message['last_name'])) {
        $this->parsedName = [];
      }
      else {
        // Parse name parts into fields if we have the full name and the name parts are
        // not otherwise specified.
        $this->parsedName = Name::parse(FALSE)
          ->setNames([$this->message['full_name']])
          ->execute()->first();
      }
    }
    return $this->parsedName;
  }

  /**
   * @return int
   * @throws \Civi\WMFException\WMFException
   */
  public function getPaymentInstrumentID(): int {
    if (!empty($this->message['payment_instrument_id'])) {
      if (is_numeric($this->message['payment_instrument_id'])) {
        return (int) $this->message['payment_instrument_id'];
      }
      $paymentInstrumentID = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', $this->message['payment_instrument_id']);
      if (!$paymentInstrumentID) {
        throw new WMFException(WMFException::INVALID_MESSAGE, "No payment type found for message.");
      }
      return (int) $paymentInstrumentID;
    }
    $paymentInstrument = $this->message['payment_instrument'] ?? FinanceInstrument::getPaymentInstrument($this->message);
    $paymentInstrumentID = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', $paymentInstrument);
    if ($paymentInstrumentID) {
      return (int) $paymentInstrumentID;
    }
    throw new WMFException(WMFException::INVALID_MESSAGE, "No payment type found for message.");
  }

}
