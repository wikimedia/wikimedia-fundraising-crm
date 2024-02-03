<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Name;
use Civi\WMFHelper\FinanceInstrument;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFException\WMFException;
use Civi\ExchangeException\ExchangeRatesException;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;

class DonationMessage {

  /**
   * WMF message with keys (incomplete list)
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
   * Constructor.
   */
  public function __construct(array $message) {
    $this->message = $message;
  }

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
    $trim_strings = function($input) {
      if (!is_string($input)) {
        return $input;
      }
      return trim($input);
    };

    $msg = array_map($trim_strings, $msg);

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
      'contact_id' => NULL,
      'contribution_recur_id' => NULL,
      'effort_id' => NULL,
      'subscr_id' => NULL,
      'contact_groups' => [],
      'contact_tags' => [],
      'contribution_tags' => [],
      'soft_credit_to' => NULL,
      'soft_credit_to_id' => NULL,
    ];
    $msg = $msg + $defaults;

    $this->removeKnownBadStringsFromAddressFields($msg);

    if (empty($msg['financial_type_id'])) {
      if (!empty($msg['contribution_recur_id'])) {
        $msg['financial_type_id'] = ContributionRecur::getFinancialType($msg['contribution_recur_id']);
      }
      elseif (!empty($msg['recurring'])) {
        // Can we remove this - seems to be set elsewhere.
        // Recurring Gift is used for the first in the series, Recurring Gift - Cash thereafter.
        $msg['financial_type_id'] = ContributionRecur::getFinancialTypeForFirstContribution();
      }
      else {
        $msg['financial_type_id'] = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash');
      }
    }

    if (empty($msg['payment_instrument_id'])) {
      $paymentInstrument = $msg['payment_instrument'] ?? FinanceInstrument::getPaymentInstrument($msg);
      $msg['payment_instrument_id'] = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', $paymentInstrument);
    }
    if (!$msg['payment_instrument_id']) {
      throw new WMFException(WMFException::INVALID_MESSAGE, "No payment type found for message.");
    }
    $msg['date'] = $this->getTimestamp();

    if ($msg['recurring'] and !isset($msg['start_date'])) {
      $msg['start_date'] = $msg['date'];
      $msg['create_date'] = $msg['date'];
    }

    if ($msg['recurring'] and !$msg['subscr_id']) {
      if ($msg['gateway'] === 'globalcollect') {
        // Well randomly grab an ID, of course :-/
        $msg['subscr_id'] = $msg['gateway_txn_id'];
      }
      else {
        if ($msg['gateway'] === 'amazon') {
          // Amazon 'subscription id' is the Billing Agreement ID, which
          // is a substring of the Capture ID we record as 'gateway_txn_id'
          $msg['subscr_id'] = substr($msg['gateway_txn_id'], 0, 19);
        }
      }
    }

    $msg['thankyou_date'] = $this->getThankYouDate();

    if (!empty($msg['full_name']) && (empty($msg['first_name']) || empty($msg['last_name']))) {
      // Parse name parts into fields if we have the full name and the name parts are
      // not otherwise specified.
      $parsed = Name::parse(FALSE)
        ->setNames([$msg['full_name']])
        ->execute()->first();
      $msg = array_merge(array_filter((array) $parsed), $msg);
      $msg['addressee_custom'] = $msg['full_name'];
    }

    if (empty($msg['first_name']) && empty($msg['last_name'])) {
      $msg['first_name'] = 'Anonymous';
      $msg['last_name'] = '';
    }

    // Apply name defaults after full_name has been parsed
    $nameDefaults = ['first_name' => '', 'middle_name' => '', 'last_name' => ''];
    $msg = array_merge($nameDefaults, $msg);

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
      $msg['financial_type_id'] = 'Endowment Gift';
      $msg['restrictions'] = 'Endowment Fund';
      $msg['gift_source'] = 'Online Gift';
    }

    $list_fields = [
      'contact_groups',
      'contact_tags',
      'contribution_tags',
    ];
    foreach ($list_fields as $field) {
      if (is_string($msg[$field])) {
        $msg[$field] = preg_split('/[\s,]+/', $msg[$field], NULL, PREG_SPLIT_NO_EMPTY);
      }
      $msg[$field] = array_unique($msg[$field]);
    }

    // Front-end uses es-419 to represent Latin American Spanish but
    // CiviCRM needs to store it as a 5 char string. We choose es_MX.
    if (isset($msg['language']) && strtolower($msg['language']) === 'es-419') {
      $msg['language'] = 'es_MX';
    }

    // set the correct amount fields/data and do exchange rate conversions.
    $msg = $this->normalizeContributionAmounts($msg);

    return $msg;
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
   * Normalize contribution amounts
   *
   * Do exchange rate conversions and set appropriate fields for CiviCRM
   * based on information contained in the message.
   *
   * Upon exiting this function, the message is guaranteed to have these fields:
   *    currency - settlement currency
   *    original_currency - currency remitted by the donor
   *    gross - settled total amount
   *    original_gross - remitted amount in original currency
   *    fee - processor fees, when available
   *    net - gross less fees
   *
   * @param $msg
   *
   * @return array
   * @throws \Civi\WMFException\WMFException
   */
  private function normalizeContributionAmounts($msg) {
    $msg = $this->formatCurrencyFields($msg);

    // If there is anything fishy about the amount...
    if ((empty($msg['gross']) or empty($msg['currency']))
      and (empty($msg['original_gross']) or empty($msg['original_currency']))
    ) {
      // just... don't
      \Civi::log('wmf')->info('wmf_civicrm: Not freaking out about non-monetary message.');
      return $msg;
    }

    if (empty($msg['original_currency']) && empty($msg['original_gross'])) {
      $msg['original_currency'] = $msg['currency'];
      $msg['original_gross'] = $msg['gross'];
    }

    $validFee = array_key_exists('fee', $msg) && is_numeric($msg['fee']);
    $validNet = array_key_exists('net', $msg) && is_numeric($msg['net']);
    if (!$validFee && !$validNet) {
      $msg['fee'] = '0.00';
      $msg['net'] = $msg['gross'];
    }
    elseif ($validNet && !$validFee) {
      $msg['fee'] = $msg['gross'] - $msg['net'];
    }
    elseif ($validFee && !$validNet) {
      $msg['net'] = $msg['gross'] - $msg['fee'];
    }

    $settlement_currency = wmf_civicrm_get_settlement_currency($msg);
    if ($msg['currency'] !== $settlement_currency) {
      \Civi::log('wmf')->info('wmf_civicrm: Converting to settlement currency: {old} -> {new}',
        ['old' => $msg['currency'], 'new' => $settlement_currency]);
      try {
        $settlement_convert = exchange_rate_convert($msg['original_currency'], 1, $msg['date']) / exchange_rate_convert($settlement_currency, 1, $msg['date']);
      }
      catch (ExchangeRatesException $ex) {
        throw new WMFException(WMFException::INVALID_MESSAGE, "UNKNOWN_CURRENCY: '{$msg['original_currency']}': " . $ex->getMessage());
      }

      // Do exchange rate conversion
      $msg['currency'] = $settlement_currency;
      $msg['fee'] = $msg['fee'] * $settlement_convert;
      $msg['gross'] = $msg['gross'] * $settlement_convert;
      $msg['net'] = $msg['net'] * $settlement_convert;
    }

    $msg['fee'] = CurrencyRoundingHelper::round($msg['fee'], $msg['currency']);
    $msg['gross'] = CurrencyRoundingHelper::round($msg['gross'], $msg['currency']);
    $msg['net'] = CurrencyRoundingHelper::round($msg['net'], $msg['currency']);

    return $msg;
  }

  /**
   * Format currency fields in passed array.
   *
   * Currently we are just stripping out commas on the assumption they are a
   * thousand separator and unhelpful.
   *
   * @param array $values
   * @param array $currencyFields
   *
   * @return array
   */
  private function formatCurrencyFields($values, $currencyFields = [
    'gross',
    'fee',
    'net',
  ]
  ) {
    foreach ($currencyFields as $field) {
      if (isset($values[$field])) {
        $values[$field] = str_replace(',', '', $values[$field]);
      }
    }
    return $values;
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

}
