<?php

namespace Civi\WMFQueueMessage;

use Civi\API\EntityLookupTrait;
use Civi\Api4\Contact;
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
    $messageProperty = ReflectionUtils::getCodeDocs((new \ReflectionProperty($this, 'message')), 'Property');
    if (isset($messageProperty['shape'])) {
      $this->supportedFields = $messageProperty['shape'];
    }

    foreach (array_keys($message) as $key) {
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
          // log the key here? That way we can see what is not documented
          // and over time reduce it to nothing.
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
    return [
      'gateway' => [
        'name' => 'gateway',
        'title' => 'Gateway',
        // Note that we could move from a description to a list of valid options.
        // Might enforce consistency in a good way?
        'description' => 'gateway processor for this payment - eg. adyen, paypal etc',
        'data_type' => 'String',
        'api_field' => 'contribution_extra.gateway',
        'api_entity' => 'Contribution',
      ],
      'gateway_txn_id' => [
        'name' => 'gateway_txn_id',
        'description' => 'Gateway Transaction reference',
        'data_type' => 'String',
        'api_field' => 'contribution.extra.gateway_txn',
        'api_entity' => 'Contribution',
      ],
      'gateway_account' => [
        'name' => 'gateway_account',
        'description' => 'Possibly unused field',
        'title' => 'Gateway Account',
        'data_type' => 'String',
        'api_field' => 'contribution.extra.gateway_account',
        'api_entity' => 'Contribution',
      ],
      'invoice_id' => [
        'title' => E::ts('Invoice ID'),
        'name' => 'invoice_id',
        'data_type' => 'String',
      ],
      'contribution_tracking_id' => [
        'name' => 'contribution_tracking_id',
        'data_type' => 'Integer',
      ],
      'currency' => [
        'name' => 'currency',
        'description' => E::ts('Original Currency'),
        'data_type' => 'String',
      ],
      'gross' => [
        'name' => 'gross',
        'description' => E::ts('Total amount in original currency'),
        'data_type' => 'Money',
      ],
      'fee' => [
        'name' => 'fee',
        'description' => E::ts('Fee in the Settled currency'),
        'data_type' => 'Money',
      ],
      'settled_currency' => [
        'title' => E::ts('Settled Currency'),
        'name' => 'settled_currency',
        'data_type' => 'String',
      ],
      'settled_date' => [
        'name' => 'settled_date',
        'description' => E::ts('Date this settled at the payment processor - this is when their conversion is finalized'),
        'data_type' => 'Datetime',
      ],
      'payment_method' => [
        'name' => 'payment_method',
        'data_type' => 'String',
      ],
      'payment_submethod' => [
        'name' => 'payment_submethod',
        'data_type' => 'String',
      ],
      'type' => [
        'description' => 'refund or chargeback or other to be documented',
        'data_type' => 'String',
      ],
      'phone' => [
        'api_field' => 'phone_primary.phone',
        'label' => E::ts('Phone'),
        'api_entity' => 'Contact',
        'data_type' => 'String',
      ],
      'email' => [
        'api_field' => 'email_primary.email',
        'label' => E::ts('Email'),
        'api_entity' => 'Contact',
        'data_type' => 'String',
      ],
      'date' => [
        'api_field' => 'receive_date',
        'api_entity' => 'Contribution',
        'label' => E::ts('Transaction Date'),
        'data_type' => 'Datetime',
      ],
      'country' => [
        'label' => E::ts('Phone'),
        'api_field' => 'address_primary.country_id',
        'api_entity' => 'Contact',
        'data_type' => 'String',
      ],
      'recurring' => [
        'label' => E::ts('Is recurring?'),
        'data_type' => 'Bool',
      ],
      'contribution_recur_id' => [
        'label' => E::ts('Contribution Recur ID'),
        'data_type' => 'Int',
        'api_field' => 'id',
        'api_entity' => 'ContributionRecur',
      ],
      'subscr_id' => [
        'label' => E::ts('Subscription ID'),
        'data_type' => 'String',
        'api_field' => 'trxn_id',
        'api_entity' => 'ContributionRecur',
      ],
      'recurring_payment_token' => [
        'label' => E::ts('Token identifier for recharging a recurring'),
        'data_type' => 'String',
        'api_field' => 'token',
        'api_entity' => 'PaymentToken',
      ],
      'utm_medium' => [
        'label' => E::ts('UTM Medium'),
        'data_type' => 'String',
        'api_field' => 'contribution_extra.utm_medium',
        'api_entity' => 'Contribution',
      ],
    ];
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
      $api4FieldName = $field ? $field['custom_group']['name'] . '.' . $field['name'] : NULL;
      if ($field && !isset($this->message[$api4FieldName])) {
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
          $customFields[$api4FieldName] = $value;
        }
        else {
          if ($value === '' || $value === NULL || !empty($field['options'][$value])) {
            $customFields[$api4FieldName] = $value;
          }
          elseif (in_array($value, $field['options'])) {
            $customFields[$api4FieldName] = array_search($value, $field['options']);
          }
          else {
            $found = FALSE;
            // Do a slower case-insensitve search before barfing
            foreach ($field['options'] as $optionValue) {
              if (mb_strtolower($optionValue) === mb_strtolower($value)) {
                $found = TRUE;
                $customFields[$api4FieldName] = $optionValue;
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
    $declaredFields = $this->getFields();
    if (!empty($declaredFields[$name]) && empty($declaredFields['custom_field_id'])) {
      return NULL;
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
    // Only pass through Contribution fields on the allow-list.
    // This filtering may or may not be a good idea but historically the
    // code has been permission on contact field pass-through &
    // restrictive on contribution fields.
    if ($field['custom_group']['extends'] === 'Contribution' && !in_array($field['name'], [
      'gateway_account',
      'import_batch_number',
      'no_thank_you',
      'source_name',
      'source_type',
      'source_host',
      'source_run_id',
      'source_version',
      'source_enqueued_time',
      'Donor_Specified',
      'Appeal',
      'Fund',
      'Campaign',
      'backend_processor',
      'backend_processor_txn_id',
      'payment_orchestrator_reconciliation_id',
      'gateway_status_raw',
      'gateway_txn_id',
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

}
