<?php

namespace Civi\WMFQueueMessage;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Contribution;
use Civi\Api4\Name;
use Civi\Api4\OptionValue;
use Civi\Api4\PaymentToken;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFHelper\FinanceInstrument;
use Civi\WMFTransaction;
use CRM_Core_Exception;

class DonationMessage extends Message {

  /**
   * WMF Donation Message.
   *
   * @var array{
   *   gateway: string,
   *   gateway_txn_id: string,
   *   gateway_account: string,
   *   backend_processor: string,
   *   backend_processor_txn_id: string,
   *   employer: string,
   *   initial_scheme_transaction_id: string,
   *   payment_orchestrator_reconciliation_id: string,
   *   parent_contribution_id: int,
   *   original_currency: string,
   *   recurring: bool,
   *   contribution_recur_id: int,
   *   subscr_id: string,
   *   recurring_payment_token: string,
   *   date: string,
   *   utm_medium: string,
   *   utm_campaign: string,
   *   gift_source: string,
   *   source_name: string,
   *   type: string,
   *   phone: string,
   *   email: string,
   *   country: string,
   *   opt_in: string,
   *   source_enqueued_time: string,
   *   source_name: string,
   *   source_host: string,
   *   source_type: string,
   *   source_run_id: string,
   *   source_version: string,
   *   settled_date: string,
   *   settled_total_amount: float,
   *   settled_net_amount: float,
   *   settled_fee_amount: float,
   *   settled_currency: string,
   *   settlement_batch_reference: string,
   *   recipient_id: integer,
   *  }
   */
  protected array $message;

  protected array $parsedName;

  /**
   * Is a payment being processed as part of this Message.
   *
   * This is always TRUE for donation messages but not always for
   * the recurring donation messages that override this class
   * (e.g. sign up or cancel messages).
   *
   * @var bool
   */
  protected bool $isPayment;

  /**
   * Set is Payment.
   *
   * This is set to false when being called from our recurring code when
   * we are unsure is some message might be being processed that does not
   * include a payment (e.g an end of term notice).
   *
   * @param bool $isPayment
   */
  public function setIsPayment(bool $isPayment): void {
    $this->isPayment = $isPayment;
  }

  public function isPayment() : bool {
    if (isset($this->isPayment)) {
      return $this->isPayment;
    }
    return TRUE;
  }

  public function isChargebackReversal() : bool {
    if (empty($this->message['type'])) {
      return FALSE;
    }
    return $this->message['type'] === 'chargeback_reversed';
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
      'recurring' => NULL,
      'effort_id' => NULL,
    ];
    $msg = $msg + $defaults;
    $this->removeKnownBadStringsFromAddressFields($msg);

    $msg['financial_type_id'] = $this->getFinancialTypeID();
    $msg['contribution_recur_id'] = $this->getContributionRecurID();
    $msg['contact_id'] = $this->getContactID();
    if (empty($msg['contact_id']) && !empty($this->message['contact_id'])) {
      if (\CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_contact WHERE id = %1 AND is_deleted = 0', [
        1 => [$this->message['contact_id'], 'Integer']
      ])) {
        $msg['referral_id'] = $this->message['contact_id'];
      }
      else {
        \Civi::log('wmf')->warning('unavailable contact ID provided {contact_id}', ['contact_id' => $this->message['contact_id']]);
      }
    }
    $msg['payment_instrument_id'] = $this->getPaymentInstrumentID();
    $msg['date'] = $this->getTimestamp();
    $parsed = $this->getParsedName();
    if (!empty($parsed)) {
      $msg = array_merge(array_filter((array) $parsed), $msg);
      $msg['addressee_custom'] = $this->cleanString($msg['full_name'], 128);
      $msg['addressee_display'] = $msg['addressee_custom'];
    }

    $contactFields = [
      'first_name' => $this->cleanString($this->getFirstName() ?? '', 64),
      'last_name' => $this->cleanString($this->getLastName() ?? '', 64),
      'middle_name' => $this->cleanString($this->getMiddleName() ?? '', 64),
      'language' => $this->getLanguage(),
      'legal_identifier' => $this->cleanString($this->message['fiscal_number'] ?? '', 32),
    ] + $this->getExternalIdentifierFields();
    foreach ($contactFields as $name => $contactField) {
      if ($contactField) {
        $msg[$name] = $contactField;
      }
      else {
        unset($msg[$name]);
      }
    }

    $msg += $this->getSettlementFields() + $this->getCustomFields();
    if ($this->getOriginalAmount()) {
      // Set is major gift but do not try to calculate on (e.g) cancel messages.
      $msg['Gift_Data.is_major_gift'] = $this->isMajorGift();
    }
    $msg['Gift_Data.Appeal'] = $this->getAppeal();
    $msg['gateway_txn_id'] = $this->getGatewayTxnID();
    $msg['trxn_id'] = $this->getTrxnID();
    $msg += $this->getPhoneFields();

    if ($this->isEndowmentGift()) {
      $msg['Gift_Data.Fund'] = 'Endowment Fund';
      $msg['Gift_Data.Campaign'] = 'Online Gift';
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
        ['old' => $msg['currency'], 'new' => $this->getReportingCurrency()]);
    }

    $msg['original_gross'] = $msg['contribution_extra.original_amount'] = $this->getOriginalAmount();
    $msg['original_currency'] = $msg['contribution_extra.original_currency'] = $this->getOriginalCurrency();
    // Note that it possibly makes sense to leave these to the apiPrepare hook,
    // in \Civi\WMFHook\Contribution, which
    // covers other imports (e.g via the UI). However, that code currently does not
    // handle fees.
    $msg['currency'] = $this->getReportingCurrency();
    $msg['fee'] = $this->getReportingFeeAmountRounded();
    $msg['gross'] = $this->getReportingAmountRounded();
    $msg['net'] = $this->getReportingNetAmountRounded();

    return $msg;
  }

  public function isMajorGift() : bool {
    if ($this->getReportingAmount() > 9999.99) {
      return TRUE;
    }
    $utmMedium = $this->message['utm_medium'] ?? '';
    $appeal = $this->getAppeal() ?: '';
    // This pattern is in use in 2026 & hopefully will be going forwards.
    // However getChannel() could change to Direct Mail and we might add
    // str_ends_with($appeal, 'MGF') on the principle we should show endowment
    // is_major_gift vs not is_major_gift.
    $isDirectMail = $this->getChannel() === 'Direct Mail';
    if ($isDirectMail) {
      if (str_ends_with($appeal, 'MGF')) {
        return TRUE;
      }
      // Whitemail - subject to $ threshold.
      if ($this->getReportingAmountRounded() >= 250
        && (in_array($appeal, ['WMF1124RE' , 'White Mail'])
        || str_ends_with($appeal, 'WM'))
      ) {
        return TRUE;
      }
    }

    // Note question about case over at https://phabricator.wikimedia.org/T406193#11338650
    if (str_starts_with($utmMedium, 'MG') || str_starts_with($appeal, 'MG')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @return int|null
   * @throws \CRM_Core_Exception
   */
  public function getContactID(): ?int {
    $contactID = parent::getContactID();
    // Override parent to ensure that the email address matches too.
    // This is not applied to the OptInConsumer
    try {
      if ($contactID && !empty($this->message['contact_hash'])
        && !empty($this->message['email']) && $this->message['email'] !== $this->lookup('Contact', 'email_primary.email')) {
        return NULL;
      }
    }
    catch (CRM_Core_Exception $e) {
      // An exception would be thrown if there is no existing contact with this as their
      // primary email.
      return NULL;
    }
    return $contactID;
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

  public function getInvoiceID(): ?string {
    if (!empty($this->message['invoice_id'])) {
      return (string) $this->message['invoice_id'];
    }
    return $this->message['order_id'] ?? NULL;
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
    if ($this->isChargebackReversal()) {
      return (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Chargeback Reversal');
    }
    if ($this->isSubsequentRecurring()) {
      return ContributionRecur::getFinancialType($this->getContributionRecurID());
    }
    if ($this->isRecurring()) {
      // No contribution recur record yet -> Recurring Gift is used for the first in the series,
      // Recurring Gift - Cash thereafter.
      return ContributionRecur::getFinancialTypeForFirstContribution();
    }
    return (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash');
  }

  public function getGatewayTxnID(): ?string {
    return $this->message['gateway_txn_id'] ?? NULL;
  }

  /**
   * Validate the message
   *
   * @return void
   * @throws \Civi\WMFException\WMFException|\CRM_Core_Exception
   */
  public function validate(): void {
    if (!$this->getPaymentInstrumentID()) {
      throw new WMFException(WMFException::INVALID_MESSAGE, "No payment type found for message.");
    }
    $missingFields = [];
    if (!$this->getOriginalCurrency()) {
      $missingFields[] = 'currency';
    }
    if (!$this->getOriginalAmount()) {
      $missingFields[] = 'gross';
    }
    if (!$this->getGateway()) {
      $missingFields[] = 'gateway';
    }
    if (!$this->getGatewayTxnID()) {
      $missingFields[] = 'gateway_txn_id';
    }
    $errors = [];
    if (!empty($missingFields)) {
      $errors[] = 'Required field/s missing from message: ' . implode(', ', $missingFields);
    }

    if ($this->getReportingNetAmount() <= 0 || $this->getReportingAmount() <= 0) {
      $errors[] = "Positive amount required.";
    }

    if (!empty($errors)) {
      throw new WMFException(WMFException::CIVI_REQ_FIELD, implode("\n", $errors));
    }

    // Now check to make sure this isn't going to be a duplicate message for this gateway.
    // Special handling for chargeback reversals, which have the same gateway_txn_id but
    // a different trxn_id.
    if ($this->isChargebackReversal()) {
      $duplicate = \CRM_Core_DAO::singleValueQuery(
        'SELECT count(*)
          FROM civicrm_contribution
          WHERE trxn_id = %1', [
        1 => [$this->getTrxnId(), 'String'],
      ]);
    } else {
      $duplicate = \CRM_Core_DAO::singleValueQuery(
        'SELECT count(*)
          FROM wmf_contribution_extra cx
          WHERE gateway = %1 AND gateway_txn_id = %2', [
          1 => [ $this->getGateway(), 'String' ],
          2 => [ $this->getGatewayTxnID(), 'String' ],
      ]);
    }
    if ($duplicate) {
      throw new WMFException(
        WMFException::DUPLICATE_CONTRIBUTION,
        'Contribution already exists. Ignoring message.'
      );
    }
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
   * @throws \CRM_Core_Exception
   */
  public function getPaymentTokenID(): ?int {
    if ($this->isDefined('PaymentToken')) {
      return $this->lookup('PaymentToken', 'id');
    }
    if (empty($this->message['recurring_payment_token'])) {
      return NULL;
    }
    $paymentToken = PaymentToken::get(FALSE)
      ->addWhere('token', '=', $this->message['recurring_payment_token'])
      ->addWhere('payment_processor_id.name', '=', $this->getGateway())
      ->execute()->first();
    if ($paymentToken) {
      \Civi::log('wmf')->info('wmf_civicrm: Found matching recurring payment token: {token}', ['token' => $this->message['recurring_payment_token']]);
      $this->define('PaymentToken', 'PaymentToken', $paymentToken);
      return $paymentToken['id'];
    }
    return NULL;
  }

  /**
   * @param string $value
   *   The value to fetch, in api v4 format (e.g supports payment_processor_id.name).
   *
   * @return mixed|null
   * @noinspection PhpDocMissingThrowsInspection
   * @noinspection PhpUnhandledExceptionInspection
   */
  public function getExistingPaymentTokenValue(string $value) {
    if (!$this->getPaymentTokenID()) {
      return NULL;
    }
    if (!$this->isDefined('PaymentToken')) {
      $this->define('PaymentToken', 'PaymentToken', ['id' => $this->getPaymentTokenID()]);
    }
    return $this->lookup('PaymentToken', $value);
  }

  /**
   * @return int
   * @throws \Civi\WMFException\WMFException
   */
  public function getPaymentInstrumentID(): ?int {
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
    try {
      // In most cases this will return NULL but one last attempt to look it up.
      $paymentInstrumentID = $this->getExistingContributionRecurValue('payment_instrument_id');
      return (int) $paymentInstrumentID;
    }
    catch (\CRM_Core_Exception $exception) {
      return NULL;
    }
  }

  /**
   * Ensure the specified option value exists.
   *
   * @param array $field
   * @param string $value
   * @throws CRM_Core_Exception
   * @throws UnauthorizedException
   */
  private function ensureOptionValueExists(array &$field, $value) {
    if (empty($field['option_group_id'])) {
      $field['option_group_id'] = \CRM_Core_BAO_CustomField::getField($field['custom_field_id'])['option_group_id'];
    }
    OptionValue::save(FALSE)
      ->addRecord([
        'option_group_id' => $field['option_group_id'],
        'name' => $value,
        'label' => $value,
        'value' => $value,
        'is_active' => TRUE,
      ])
      ->setMatch(['option_group_id', 'value'])
      ->execute()->first();
    $field['options'][$value] = $value;
  }

  /**
   * Get the specified value from any prior contribution that exists.
   *
   * If the contribution is part of a series of recurring contributions
   * this will find any earlier contribution & return the relevant value.
   *
   * If there is not a prior contribution it will return NULL.
   *
   * @param string $value
   * @return mixed|null
   * @throws \CRM_Core_Exception
   */
  public function getRecurringPriorContributionValue(string $value) {
    // Note that in some cases looking up the contributionRecurID will lazy load the
    // prior contribution so there is a possible performance advantage in checking this up front.
    if (!$this->getContributionRecurID()) {
      return NULL;
    }
    if (!$this->isDefined('PriorContribution')) {
      $contribution = Contribution::get(FALSE)
        ->addWhere('contribution_recur_id', '=', $this->getContributionRecurID())
        ->addClause('OR',
          ['contribution_extra.gateway', '!=', $this->getGateway()],
          ['contribution_extra.gateway_txn_id', '!=', $this->getGatewayTxnID()])
        ->setLimit(1)
        ->execute()->first();
      if (!$contribution) {
        return NULL;
      }
      $this->define('Contribution', 'PriorContribution', $contribution);
    }
    return $this->lookup('PriorContribution', $value);
  }

  /**
   * Is this transaction coming from a matching gift import - ie Benevity.
   *
   * Note that in the medium term we are trying to get Benevity to stop going
   * through the donation flow.
   *
   * @return bool
   */
  public function isMatchingGiftContribution(): bool {
    return stristr($this->getGatewayTxnID(), '_matched');
  }

  /**
   * Is this coming as a UPI.
   *
   * UPI is a bank transfer standard from India.
   *
   * @return bool
   */
  public function isUPI() : bool {
    return ($this->message['payment_submethod'] ?? '') === 'upi';
  }

  /**
   * Get the currency the donation is settled into at the gateway.
   */
  public function getSettlementDate(): ?string {
    if (empty($this->message['settled_date'])) {
      return NULL;
    }
    return date('Y-m-d H:i:s', $this->getSettlementTimeStamp());
  }

  public function getSettlementTimeStamp(): ?int {
    if (!empty($this->message['settled_date'])) {
      return $this->message['settled_date'];
    }
    return NULL;
  }

  /**
   * @return string|null
   * @throws CRM_Core_Exception
   */
  public function getAppeal(): ?string {
    $appealValue = $this->message['utm_campaign'] ?? $this->message['Gift_Data.Appeal'] ?? $this->message['direct_mail_appeal'] ?? NULL;
    if ($appealValue) {
      $field = &$this->availableFields['utm_campaign'];
      if (empty($field['options'][$appealValue])) {
        $this->ensureOptionValueExists($field, $appealValue);
      }
    }
    return $appealValue;
  }

  /**
   * Gets a unique transaction ID suitable for storing in civicrm_contribution.trxn_id
   * @return string|null
   * @throws WMFException
   */
  public function getTrxnID(): ?string {
    if (!$this->getGatewayTxnID()) {
      return NULL;
    }
    $transaction = WMFTransaction::from_message($this->message);
    return $transaction->get_unique_id();
  }

}
