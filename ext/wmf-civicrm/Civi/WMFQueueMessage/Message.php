<?php

namespace Civi\WMFQueueMessage;

use Civi\API\EntityLookupTrait;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ExchangeRate;
use Civi\Api4\Utils\ReflectionUtils;
use Civi\WMFException\WMFException;
use CRM_Wmf_ExtensionUtil as E;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;

class Message {

  use EntityLookupTrait;

  /**
   * WMF message with keys relevant to the message.
   *
   * This is an incomplete list of parameters used in the past
   * but it should be message specific.
   *
   * We have started documenting them in the getFields() function which
   * will eventually be used for mapping & validation.
   *
   * @var array{
   *   type: string,
   *   phone: string,
   *   email: string,
   *   country: string,
   *  }
   */
  protected array $message;

  protected array $supportedFields = [];
  protected array $requiredFields = [];
  protected bool $isRestrictToSupportedFields = FALSE;
  protected bool $isLogUnsupportedFields = FALSE;
  protected bool $isLogUnavailableFields = FALSE;

  protected array $availableFields;

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
    if (!isset(\Civi::$statics[__CLASS__]['supportedFields'])) {
      \Civi::$statics[__CLASS__]['supportedFields'] = [];
      $messageProperty = ReflectionUtils::getCodeDocs((new \ReflectionProperty($this, 'message')), 'Property');
      if (isset($messageProperty['shape'])) {
        \Civi::$statics[__CLASS__]['supportedFields'] = $messageProperty['shape'];
      }
    }
    $this->supportedFields = \Civi::$statics[__CLASS__]['supportedFields'];

    foreach (array_keys($message) as $key) {
      if ($this->isLogUnavailableFields && !isset($this->getAvailableFields()[$key])) {
        \Civi::log('wmf')->info(__CLASS__ . ' undeclared field ' . $key);
      }
      if (!isset($this->supportedFields[$key])) {
        if ($this->isRestrictToSupportedFields) {
          // Currently ONLY SettleMessage defines supported values
          // We clear out the other values in the hope of forcing tightening
          // of the metadata - probably only realistic with NEW Message
          // types - when we extend to others we probably need to be noisy rather
          // than clearing them out, or perhaps behave differently when running tests.
          // Ideally we would log undeclared fields for the others to see what there is.
          unset($message[$key]);
        }
        else {
          if ($this->isLogUnsupportedFields) {
            // log the key here? That way we can see what is not documented
            // and over time reduce it to nothing.
            \Civi::log('wmf')->info(__CLASS__ . ' unsupported field ' . $key);
          }
        }
      }
    }

    $this->message = $message;
    foreach ($this->message as $key => $input) {
      if (is_string($input)) {
        $this->message[$key] = trim($input);
      }
    }
  }

  /**
   * Get the array of fields supported.
   *
   * @return array
   */
  public function getFields(): array {
    $supported = $this->supportedFields;
    $fields = [];
    foreach (array_keys($supported) as $fieldName) {
      $fields[$fieldName] = $this->getAvailableFields()[$fieldName] + ['required' => in_array($fieldName, $this->requiredFields)];
    }
    return $fields;
  }

  /**
   * Get metadata for fields available for use in the MessageSubsystem.
   *
   * This function should provide metadata about all the fields supported
   * by the Message subsystem.
   *
   * Not all Message classes will support all fields, but if they do they should be
   * as described in this function.
   *
   * Note that this is a new/evolving approach. The intent is that this
   * will be the primary source of documentation for ALL the fields supported
   * in our Message Subsystem. We will work to add an array on each message class
   * specifying which of these fields is supported by that class.
   *
   * As of writing the new Settle message is the only one that does this.
   */
  public function getAvailableFields(): array {
    if (isset($this->availableFields)) {
      return $this->availableFields;
    }
    if (!isset(\Civi::$statics[__CLASS__]['availableFields'])) {
      \Civi::$statics[__CLASS__]['availableFields'] = [];
    }
    $this->availableFields = &\Civi::$statics[__CLASS__]['availableFields'];
    $fields = [
      'gateway' => [
        'name' => 'gateway',
        'title' => 'Gateway',
        'description' => 'gateway processor for this payment - eg. adyen, paypal etc',
        'data_type' => 'String',
        'api_field' => 'contribution_extra.gateway',
        'api_entity' => 'Contribution',
        'used_for' => 'All payment messages',
        'notes' => 'We could move from a description to a list of valid options. Might enforce consistency in a good way?',
      ],
      'gateway_txn_id' => [
        'name' => 'gateway_txn_id',
        'description' => 'Gateway Transaction reference',
        'data_type' => 'String',
        'api_field' => 'contribution_extra.gateway_txn',
        'api_entity' => 'Contribution',
        'used_for' => 'All payment messages',
      ],
      'gateway_refund_id' => [
        'name' => 'gateway_refund_id',
        'description' => 'Gateway Transaction reference for refund',
        'data_type' => 'String',
        'used_for' => 'Audit messages',
      ],
      'gateway_parent_id' => [
        'name' => 'gateway_parent_id',
        'description' => 'Parent Transaction contribution_extra.txn_id',
        'data_type' => 'String',
        'used_for' => 'Refunds ? recurrings ?',
      ],
      'gateway_account' => [
        'name' => 'gateway_account',
        'description' => 'Possibly unused field',
        'title' => 'Gateway Account',
        'data_type' => 'String',
        'api_field' => 'contribution_extra.gateway_account',
        'api_entity' => 'Contribution',
        'used_for' => 'All payment messages',
        'notes' => 'Propose removal - Does not appear to have been used in a meaningful way since 2018 - all values since are "live", "prod", "default", "WikimediaDonations" or "Wikimedia Foundation"',
      ],
      'audit_file_gateway' => [
        'name' => 'audit_file_gateway',
        'title' => 'Audit file Gateway',
        'description' => 'gateway processor for this audit file - could differ from the final gateway',
        'data_type' => 'String',
        'used_for' => 'Audit messages',
        'notes' => 'Differentiates gravy messages from gravy file vs adyen file',
      ],
      'backend_processor' => [
        'name' => 'backend_processor',
        'title' => 'Backend Processor - eg. adyen when main gateway is gravy',
        'data_type' => 'String',
        'used_for' => 'Audit messages',
        'api_field' => 'contribution_extra.backend_processor',
        'api_entity' => 'Contribution',
      ],
      'backend_processor_txn_id' => [
        'name' => 'backend_processor_txn_id',
        'title' => 'Backend Processor - eg. adyen trxn_id when main gateway is gravy',
        'data_type' => 'String',
        'used_for' => 'Audit messages',
        'api_field' => 'contribution_extra.backend_processor_txn_id',
        'api_entity' => 'Contribution',
      ],
      'backend_processor_parent_id' => [
        'name' => 'backend_processor_parent_id',
        'title' => 'Backend Processor parent ID - eg. adyen parent_trxn_id when main gateway is gravy',
        'data_type' => 'String',
        'used_for' => 'Audit messages',
        'api_entity' => 'Contribution',
      ],
      'backend_processor_refund_id' => [
        'name' => 'backend_processor_refund_id',
        'title' => 'Backend Processor refund ID - eg. adyen refund_trxn_id when main gateway is gravy',
        'data_type' => 'String',
        'used_for' => 'Audit messages',
        'api_entity' => 'Contribution',
      ],
      'payment_orchestrator_reconciliation_id' => [
        'name' => 'payment_orchestrator_reconciliation_id',
        'api_field' => 'contribution_extra.payment_orchestrator_reconciliation_id',
        'api_entity' => 'Contribution',
      ],
      'invoice_id' => [
        'title' => E::ts('Invoice ID'),
        'name' => 'invoice_id',
        'data_type' => 'String',
        'api_entity' => 'Contribution',
        'api_field' => 'invoice_id',
        'used_for' => 'All payment messages',
      ],
      'contribution_tracking_id' => [
        'name' => 'contribution_tracking_id',
        'data_type' => 'Integer',
        'api_entity' => 'ContributionTracking',
        'api_field' => 'id',
        'used_for' => 'All payment messages',
      ],
      'currency' => [
        'name' => 'currency',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_extra.original_currency',
        'description' => E::ts('Original Currency'),
        'data_type' => 'String',
        'used_for' => 'All payment messages',
        'replaced_by' => 'original_currency',
      ],
      'gross' => [
        'name' => 'gross',
        'description' => E::ts('Total amount in original currency'),
        'data_type' => 'Money',
        'used_for' => 'All payment messages',
      ],
      'fee' => [
        'name' => 'fee',
        'description' => E::ts('Fee in the original currency'),
        'data_type' => 'Money',
        'api_entity' => 'Contribution',
        'api_field' => 'fee_amount',
        'used_for' => 'All payment messages',
        'notes' => 'we do not have a place to store original currency fee amount',
      ],
      'original_fee_amount' => [
        'name' => 'original_fee_amount',
        'description' => E::ts('Fee in the original currency'),
        'data_type' => 'Money',
        'used_for' => '*tbd',
        'replacement_for' => 'fee',
      ],
      'original_net_amount' => [
        'name' => 'original_net_amount',
        'description' => E::ts('Amount less the fee in the original currency'),
        'data_type' => 'Money',
        'used_for' => '*tbd',
      ],
      'original_total_amount' => [
        'name' => 'original_total_amount',
        'description' => E::ts('Total amount in the original currency'),
        'data_type' => 'Money',
        'used_for' => '*tbd',
        'replacement_for' => 'gross',
      ],
      'settled_gross' => [
        'name' => 'settled_gross',
        'description' => E::ts('At least with adyen this maps to net amount (amount paid, less fee) in the settled currency'),
        'replaced_by' => 'settled_net_amount',
      ],
      'settled_total_amount' => [
        'name' => 'settled_total_amount',
        'description' => E::ts('Total amount (including fee) in the Settled currency'),
        'data_type' => 'Money',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_settlement.settled_donation_amount',
        'api_field_reversal' => 'contribution_settlement.settled_reversal_amount',
        'used_for' => 'settle',
        'notes' => '',
      ],
      'settled_net_amount' => [
        'name' => 'settled_net_amount',
        'description' => E::ts('Total Amount less any fees in the Settled currency'),
        'data_type' => 'Money',
        'api_entity' => 'Contribution',
        // Not saved directly - Civi re-calculates
        // 'api_field' => 'net_amount',
        'used_for' => 'settle',
        'notes' => '',
      ],
      'settled_fee_amount' => [
        'name' => 'settled_fee',
        'description' => E::ts('Fee in the Settled currency'),
        'data_type' => 'Money',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_settlement.settled_fee_amount',
        'api_field_reversal' => 'contribution_settlement.settled_fee_reversal_amount',
        'used_for' => 'settle',
        'notes' => '',
      ],
      'gross_currency' => [
        'name' => 'gross_currency',
        'data_type' => 'String',
        'description' => 'Currency in which gross was originally provided',
        'used_for' => 'settle',
        'replaced_by' => 'original_currency',
      ],
      'original_currency' => [
        'name' => 'original_currency',
        'data_type' => 'String',
        'description' => 'Currency in which payment was originally provided.',
        'used_for' => '*tbd',
        'replacement_for' => 'currency',
      ],
      'settled_currency' => [
        'title' => E::ts('Settled Currency'),
        'name' => 'settled_currency',
        'data_type' => 'String',
        'used_for' => 'settle',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_settlement.settlement_currency',
      ],
      'settled_date' => [
        'name' => 'settled_date',
        'description' => E::ts('Date this settled at the payment processor - this is when their conversion is finalized'),
        'data_type' => 'Datetime',
        'used_for' => 'settle',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_settlement.settlement_date',
      ],
      'exchange_rate' => [
        'name' => 'exchange_rate',
        'data_type' => 'Float',
        'used_for' => 'audit,settle,not saved',
      ],
      'payment_method' => [
        'name' => 'payment_method',
        'data_type' => 'String',
        'used_for' => '*tbd',
      ],
      'payment_submethod' => [
        'name' => 'payment_submethod',
        'data_type' => 'String',
        'used_for' => '*tbd',
      ],
      'type' => [
        'description' => 'refund or chargeback or other to be documented',
        'data_type' => 'String',
        'used_for' => '*tbd',
      ],
      'txn_type' => [
        'name' => 'txn_type',
        'data_type' => 'String',
        'description' => 'Transaction type (e.g. payment, refund, chargeback)',
        'used_for' => '*tbd',
      ],
      'modification_reference' => [
        'name' => 'modification_reference',
        'data_type' => 'String',
        'description' => 'Reference ID for a modification (e.g. refund)',
      ],
      'date' => [
        'name' => 'date',
        'api_field' => 'receive_date',
        'api_entity' => 'Contribution',
        'label' => E::ts('Transaction Date'),
        'data_type' => 'Datetime',
      ],
      'recurring' => [
        'name' => 'recurring',
        'label' => E::ts('Is recurring?'),
        'data_type' => 'Bool',
      ],
      'contribution_recur_id' => [
        'name' => 'contribution_recur_id',
        'label' => E::ts('Contribution Recur ID'),
        'data_type' => 'Int',
        'api_field' => 'id',
        'api_entity' => 'ContributionRecur',
      ],
      'subscr_id' => [
        'name' => 'subscr_id',
        'label' => E::ts('Subscription ID'),
        'data_type' => 'String',
        'api_field' => 'trxn_id',
        'api_entity' => 'ContributionRecur',
      ],
      'recurring_payment_token' => [
        'name' => 'recurring_payment_token',
        'label' => E::ts('Token identifier for recharging a recurring'),
        'data_type' => 'String',
        'api_field' => 'token',
        'api_entity' => 'PaymentToken',
      ],
      'utm_medium' => [
        'name' => 'utm_medium',
        'label' => E::ts('UTM Medium'),
        'data_type' => 'String',
        'api_field' => 'contribution_extra.utm_medium',
        'api_entity' => 'Contribution',
      ],
      'utm_campaign' => [
        'name' => 'utm_campaign',
        'data_type' => 'String',
        'description' => 'UTM Campaign identifier for analytics',
        'used_for' => 'All payment messages',
      ],
      'gift_source' => [
        'name' => 'gift_source',
        'label' => 'Gift Source',
        'api_entity' => 'Contribution',
        'api_field' => 'Gift_Data.Campaign',
      ],
      'contact_id' => [
        'name' => 'contact_id',
        'data_type' => 'Int',
        'api_field' => 'contact_id',
        'api_entity' => 'Contact',
      ],
      'language' => [
        'name' => 'language',
        'label' => E::ts('Language'),
        'description' => E::ts('en or en_US or en-US format'),
        'api_entity' => 'Contact',
        'api_field' => 'preferred_language',
      ],
      'phone' => [
        'name' => 'phone',
        'api_field' => 'phone_primary.phone',
        'label' => E::ts('Phone'),
        'api_entity' => 'Contact',
        'data_type' => 'String',
      ],
      'email' => [
        'name' => 'email',
        'api_field' => 'email_primary.email',
        'label' => E::ts('Email'),
        'api_entity' => 'Contact',
        'data_type' => 'String',
      ],
      'state_province' => [
        'name' => 'state_province',
        'data_type' => 'String',
        'api_field' => 'address_primary.state_province_id',
        'api_entity' => 'Contact',
      ],
      'street_address' => [
        'name' => 'street_address',
        'data_type' => 'String',
        'api_field' => 'address_primary.street_address',
        'api_entity' => 'Contact',
      ],
      'supplemental_address_1' => [
        'name' => 'supplemental_address_1',
        'data_type' => 'String',
        'api_field' => 'address_primary.supplemental_address_1',
        'api_entity' => 'Contact',
      ],
      'supplemental_address_2' => [
        'name' => 'supplemental_address_2',
        'data_type' => 'String',
        'api_field' => 'address_primary.supplemental_address_2',
        'api_entity' => 'Contact',
      ],
      'city' => [
        'name' => 'city',
        'data_type' => 'String',
        'api_field' => 'address_primary.city',
        'api_entity' => 'Contact',
      ],
      'postal_code' => [
        'name' => 'postal_code',
        'data_type' => 'String',
        'api_field' => 'address_primary.postal_code',
        'api_entity' => 'Contact',
      ],
      'country' => [
        'name' => 'country',
        'label' => E::ts('Country'),
        'api_field' => 'address_primary.country_id',
        'api_entity' => 'Contact',
        'data_type' => 'String',
      ],
      'comment' => [
        'name' => 'comment',
        'data_type' => 'String',
        'description' => 'Free-form comment submitted with contribution',
      ],
      'create_date' => [
        'name' => 'create_date',
        'data_type' => 'Datetime',
        'description' => 'Date the contribution record was created',
      ],
      'effort_id' => [
        'name' => 'effort_id',
        'data_type' => 'String',
        'description' => 'Effort tracking ID (e.g. fundraising campaign)',
      ],
      'first_name' => [
        'name' => 'first_name',
        'data_type' => 'String',
        'api_field' => 'first_name',
        'api_entity' => 'Contact',
      ],
      'last_name' => [
        'name' => 'last_name',
        'data_type' => 'String',
        'api_field' => 'last_name',
        'api_entity' => 'Contact',
      ],
      'middle_name' => [
        'name' => 'middle_name',
        'data_type' => 'String',
        'api_field' => 'middle_name',
        'api_entity' => 'Contact',
      ],
      'organization_name' => [
        'name' => 'organization_name',
        'data_type' => 'String',
        'api_field' => 'organization_name',
        'api_entity' => 'Contact',
      ],
      'order_id' => [
        'name' => 'order_id',
        'data_type' => 'String',
        'description' => 'Commerce system order ID if applicable',
      ],
      'net' => [
        'name' => 'net',
        'data_type' => 'Money',
        'description' => 'Net amount after fees in original currency',
      ],
      'payment_date' => [
        'name' => 'payment_date',
        'data_type' => 'Datetime',
        'description' => 'Date payment was received',
      ],
      'payment_instrument_id' => [
        'name' => 'payment_instrument_id',
        'data_type' => 'Int',
        'description' => 'Internal ID for payment instrument type',
      ],
      'payment_instrument' => [
        'name' => 'payment_instrument',
        'data_type' => 'String',
        'description' => 'Human-readable payment instrument name',
      ],
      'source_enqueued_time' => [
        'name' => 'source_enqueued_time',
        'data_type' => 'Datetime',
        'description' => 'Timestamp when source record was queued',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_extra.source_enqueued_time',
      ],
      'source_name' => [
        'name' => 'source_name',
        'data_type' => 'String',
        'description' => 'Source Name',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_extra.source_name',
      ],
      'source_host' => [
        'name' => 'source_host',
        'data_type' => 'String',
        'description' => 'Source host',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_extra.source_host',
      ],
      'source_type' => [
        'name' => 'source_type',
        'data_type' => 'String',
        'description' => 'Source type',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_extra.source_type',
      ],
      'source_run_id' => [
        'name' => 'source_run_id',
        'data_type' => 'String',
        'description' => 'Source run ID',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_extra.source_run_id',
      ],
      'source_version' => [
        'name' => 'source_version',
        'data_type' => 'String',
        'description' => 'Source version',
        'api_entity' => 'Contribution',
        'api_field' => 'contribution_extra.source_version',
      ],
      'Gift_Data.Appeal' => [
        'name' => 'Gift_Data.Appeal',
        'data_type' => 'String',
        'description' => 'Appeal code from Gift Data',
      ],
      'start_date' => [
        'name' => 'civicrm_contribution_recur.start_date',
        'data_type' => 'Datetime',
        'description' => 'Start date of recurring contribution',
      ],
      'opt_in' => [
        'name' => 'opt_in',
        'data_type' => 'Boolean',
        'description' => 'Selection on opt in check box (if presented)',
        'api_field' => 'Communication.opt_in',
        'api_entity' => 'Contact',
      ],
      'settlement_batch_reference' => [
        'name' => 'settlement_batch_reference',
        'data_type' => 'String',
        'label' => E::ts('Gateway batch reference'),
        'description' => E::ts('The gateway batch number'),
        'api_field' => 'contribution_settlement.settlement_batch_reference',
        'api_field_reversal' => 'contribution_settlement.settlement_batch_reversal_reference',
        'api_entity' => 'Contribution',
        'used_for' => 'settle',
      ],
    ];
    $contactFields = Contact::getFields(FALSE)->setAction('save')->execute()->indexBy('name');
    $contributionFields = Contribution::getFields(FALSE)->setAction('save')->execute()->indexBy('name');
    foreach ($fields as $index => $field) {
      if (($field['api_entity'] ?? '') === 'Contact' && isset($contactFields[$field['api_field']])) {
        $field += $contactFields[$field['api_field']];
      }
      if (($field['api_entity'] ?? '') === 'Contribution' && !empty($field['api_field']) && isset($contributionFields[$field['api_field']])) {
        $field += $contributionFields[$field['api_field']];
      }
      $this->availableFields[$index] = $field;
    }
    return $this->availableFields;
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
  public function setContributionTrackingID(?int $contributionTrackingID): void {
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
    if ($this->isDefined('Contact')) {
      return $this->lookup('Contact', 'id');
    }
    $contactID = !empty($this->message['contact_id']) ? (int) $this->message['contact_id'] : NULL;
    if (!empty($this->message['contact_hash'])) {
      $contact = Contact::get(FALSE)
        ->addWhere('id', '=', $contactID)
        ->addWhere('hash', '=', $this->message['contact_hash'])
        ->addWhere('is_deleted', '=', FALSE)
        ->addSelect('email_primary.email')
        ->execute()->first();
      if ($contact) {
        // Store the values in case we want to look them up.
        $this->define('Contact', 'Contact', $contact);
        return $contactID;
      }
      return NULL;
    }
    return $contactID;
  }

  public function filterNull($array): array {
    foreach ($array as $key => $value) {
      if ($value === NULL) {
        unset($array[$key]);
      }
    }
    return $array;
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
   * @throws \Civi\ExchangeRates\ExchangeRatesException
   */
  protected function currencyConvert(string $currency, float $amount, ?int $timestamp = NULL): float {
    return (float) ExchangeRate::convert(FALSE)
      ->setFromCurrency($currency)
      ->setFromAmount($amount)
      ->setTimestamp('@' . ($timestamp ?: $this->getTimestamp()))
      ->execute()
      ->first()['amount'];
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

  public function getDate(): string {
    return date('Y-m-d H:i:s', $this->getTimestamp());
  }

  /**
   * Run strtotime in UTC
   *
   * @param string $date Random date format you hope is parseable by PHP, and is
   * in UTC.
   *
   * @return int Seconds since Unix epoch
   * @throws \DateMalformedStringException
   */
  protected function parseDateString(string $date): int {
    // Funky hack to trim decimal timestamp.  More normalizations may follow.
    $text = preg_replace('/^(@\d+)\.\d+$/', '$1', $date);
    return (new \DateTime($text, new \DateTimeZone('UTC')))->getTimestamp();
  }

  public function isAmazon(): bool {
    return $this->isGateway('amazon');
  }

  public function isPaypal(): bool {
    return $this->isGateway('paypal') || $this->isGateway('paypal_ec');
  }

  public function isBraintreeVenmo(): bool {
    return $this->isGateway('braintree') || $this->message['payment_method'] === 'venmo';
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

  /**
   * Get any keyed custom fields, transforming them to apiv4 style values.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getCustomFields(): array {
    $customFields = array_filter(['Gift_Data.Channel' => $this->getChannel()]);
    foreach ($this->message as $fieldName => $value) {
      if ($fieldName === 'direct_mail_appeal' && !empty($this->message['utm_campaign'])) {
        // This is a weird one, utm_campaign beats direct_mail_appeal because ... code history.
        continue;
      }
      $field = $this->getCustomFieldMetadataByFieldName($fieldName);
      if ($field && !isset($this->message[$field['api_field']])) {
        if (!empty($field['option_group_id'])) {
          // temporary handling while I adjust the code to apiv4.
          $entity = $field['custom_group']['extends'] === 'Contribution' ? 'Contribution' : 'Contact';
          $field['options'] = civicrm_api4($entity, 'getfields', [
            'loadOptions' => TRUE,
            'where' => [
              ['custom_field_id', '=', $field['id']],
            ],
            'checkPermissions' => FALSE,
          ])->first()['options'];
        }
        if ($field['data_type'] === 'Date' && is_integer($value)) {
          $value = '@' . $value;
        }
        if (empty($field['options'])) {
          $customFields[$field['api_field']] = $value;
        }
        else {
          if ($value === '' || $value === NULL || !empty($field['options'][$value])) {
            $customFields[$field['api_field']] = $value;
          }
          elseif (in_array($value, $field['options'])) {
            $customFields[$field['api_field']] = array_search($value, $field['options']);
          }
          else {
            $found = FALSE;
            // Do a slower case-insensitve search before barfing
            foreach ($field['options'] as $optionValue) {
              if (mb_strtolower($optionValue) === mb_strtolower($value)) {
                $found = TRUE;
                $customFields[$field['api_field']] = $optionValue;
                break;
              }
            }
            if (!$found) {
              // @todo - maybe move to validate? Might be easier once separation from import has been done.
              $value = \CRM_Utils_Type::escape($value, 'String');
              throw new WMFException(
                WMFException::INVALID_MESSAGE,
                "Invalid value ($value) submitted for custom field {$field['id']}:"
                . "{$field['custom_group']['title']} {$field['label']} - {$field['custom_group']['name']}.{$field['name']}"
              );
            }
          }
        }
      }
    }
    return $customFields;
  }

  public function getChannel(): string {
    if (!empty($this->message['Gift_Data.Channel'])) {
      return (string) $this->message['Gift_Data.Channel'];
    }
    if (!empty($this->message['channel'])) {
      return $this->message['channel'];
    }
    if (!empty($this->message['recipient_id'])) {
      return 'SMS';
    }
    return '';
  }

  public function getPhoneFields() : array {
    $phoneFields = [];
    if (!empty($this->message['phone'])) {
      $phoneFields['phone_primary.phone'] = $this->message['phone'];
      $phoneFields['phone_primary.phone_type_id:name'] = 'Phone';
    }
    // The recipient ID is a value sent from Acoustic which can be used to look
    // up the actual phone number.
    $recipient_id = $this->message['recipient_id'] ?? NULL;
    if (!empty($recipient_id)) {
      // Depending on how the message originates from acoustic it can be numeric or base64 encoded
      if (!is_numeric($recipient_id)) {
        // There is a random? S0 at the end, remove it (it might not always be S0) T381931
        $recipient_id = substr($recipient_id, 0, -2);
        $recipient_id = base64_decode($recipient_id,'true');
        if (!$recipient_id) {
          throw new WMFException(
            WMFException::INVALID_MESSAGE,
            "Invalid value ($this->message['recipient_id']) for recipient_id"
          );
        }
      }

      $phoneFields['phone_primary.phone_data.recipient_id'] = $recipient_id;
      $phoneFields['phone_primary.phone_data.phone_source'] = 'Acoustic';
      $phoneFields['phone_primary.phone_type_id:name'] = 'Mobile';
      $phoneFields['phone_primary.location_type_id:name'] = 'sms_mobile';
      // Use a dummy value for the mandatory phone field.
      $phoneFields['phone_primary.phone'] = $phoneFields['phone_primary.phone'] ?? \CRM_Omnimail_Omnicontact::DUMMY_PHONE;
    }
    if (!empty($phoneFields)) {
      $phoneFields['phone_primary.phone_data.phone_update_date'] = 'now';
    }
    return $phoneFields;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function getCustomFieldMetadataByFieldName(string $name): ?array {
    $declaredField = $this->getFields()[$name] ?? [];
    if (isset($declaredField['api_entity'])) {
      // Ideally this function would end after this if....
      return empty($declaredField['custom_field_id']) ? NULL : $declaredField;
    }
    $fieldsToMap = [
      'utm_campaign' => 'Gift_Data.Appeal',
      'direct_mail_appeal' => 'Gift_Data.Appeal',
      'gift_source' => 'Gift_Data.Campaign',
      'restrictions' => 'Gift_Data.Fund',
      'gateway_status' => 'contribution_extra.gateway_status_raw',
      'do_not_solicit' => 'Communication.do_not_solicit',
      'opt_in' => 'Communication.opt_in',
      'employer' => 'Communication.Employer_Name',
    ];
    if (!empty($fieldsToMap[$name])) {
      $name = $fieldsToMap[$name];
    }
    $parts = explode('.', $name);
    if (count($parts) == 1) {
      $fieldID = \CRM_Core_BAO_CustomField::getCustomFieldID($parts[0]);
    }
    else {
      $fieldID = \CRM_Core_BAO_CustomField::getCustomFieldID($parts[1], $parts[0]);
    }
    if (!$fieldID) {
      return NULL;
    }
    $field = $this->getCustomFieldMetadata($fieldID);
    $field['api_field'] = $field ? $field['custom_group']['name'] . '.' . $field['name'] : NULL;
    // Only pass through Contribution fields on the allow-list.
    // This filtering may or may not be a good idea but historically the
    // code has been permission on contact field pass-through &
    // restrictive on contribution fields.
    if ($field['custom_group']['extends'] === 'Contribution' && !in_array($field['name'], [
      'gateway_account',
      'no_thank_you',
      'Donor_Specified',
      'Appeal',
      'Fund',
      'Campaign',
      'gateway_status_raw',
    ])) {
      return NULL;
    }
    return $field;
  }

  public function getCustomFieldMetadata(int $id): array {
    return \CRM_Core_BAO_CustomField::getField($id);
  }

  /**
   * Clean up a string by
   *  - trimming preceding & ending whitespace
   *  - removing any in-string double whitespace
   *
   * @param string $string
   * @param int $length
   *
   * @return string
   */
  protected function cleanString(string $string, int $length): string {
    $replacements = [
      // Hex for &nbsp;
      '/\xC2\xA0/' => ' ',
      '/&nbsp;/' => ' ',
      // Replace multiple ideographic space with just one.
      '/(\xE3\x80\x80){2}/' => html_entity_decode("&#x3000;"),
      // Trim ideographic space (this could be done in trim further down but seems a bit fiddly)
      '/^(\xE3\x80\x80)/' => ' ',
      '/(\xE3\x80\x80)$/' => ' ',
      // Replace multiple space with just one.
      '/\s\s+/' => ' ',
      // And html ampersands with normal ones.
      '/&amp;/' => '&',
      '/&Amp;/' => '&',
    ];
    return mb_substr(trim(preg_replace(array_keys($replacements), $replacements, $string)), 0, $length);
  }

  public function getExternalIdentifierFields(): array {
    if (!$this->getGateway() || empty($this->message['external_identifier'])) {
      return [];
    }
    if ($this->isBraintreeVenmo()) {
      return ['External_Identifiers.venmo_user_name' => $this->cleanString($this->message['external_identifier'], 64)];
    }
    if (\CRM_Core_BAO_CustomField::getCustomFieldID($this->getGateway() . '_id')) {
      return ['External_Identifiers.' . $this->getGateway() . '_id' => $this->cleanString($this->message['external_identifier'], 64)];
    }
    return [];
  }

  public function getBackendProcessor(): ?string {
    return $this->message['backend_processor'] ?? NULL;
  }

  public function getBackendProcessorTxnID(): ?string {
    return $this->message['backend_processor_txn_id'] ?? NULL;
  }

  public function getSettlementFields(): array {
    $fields = [];
    if ($this->getSettledDate()) {
      foreach ($this->getAvailableFields() as $key => $spec) {
        if ($key === 'settled_date') {
          $fields['contribution_settlement.settled_date'] = $this->getSettledDate();
        }
        elseif (($spec['used_for'] ?? NULL ) === 'settle' && isset($spec['api_field']) && isset($this->message[$key])) {
          $apiKey = $this->isReversal() ? ($spec['api_field_reversal'] ?? $spec['api_field']) : $spec['api_field'];
          $fields[$apiKey] = $this->message[$key];
        }
      }
    }
    return $fields;
  }

  public function getSettledDate() : ?string {
    return empty($this->message['settled_date']) ? NULL : date('Y-m-d H:i:s T', $this->message['settled_date']);
  }

  /**
   * Is this a donation reversal?
   *
   * @return bool
   */
  public function isReversal(): bool {
    return FALSE;
  }

}
