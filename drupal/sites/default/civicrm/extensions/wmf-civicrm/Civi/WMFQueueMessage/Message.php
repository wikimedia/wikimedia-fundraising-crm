<?php

namespace Civi\WMFQueueMessage;

use Civi\API\EntityLookupTrait;
use Civi\Api4\Contact;
use Civi\Api4\ExchangeRate;
use Civi\WMFException\WMFException;
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
   * @throws \Civi\ExchangeException\ExchangeRatesException
   */
  protected function currencyConvert(string $currency, float $amount, ?int $timestamp = NULL): float {
    return (float)ExchangeRate::convert(FALSE)
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
    return time();
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
    $customFields = [];
    foreach ($this->message as $fieldName => $value) {
      if ($fieldName === 'direct_mail_appeal' && !empty($this->message['utm_campaign'])) {
        // This is a weird one, utm_campaign beats direct_mail_appeal because ... code history.
        continue;
      }
      $field = $this->getCustomFieldMetadataByFieldName($fieldName);
      if ($field && !isset($this->message[$field['custom_group_id.name'] . '.' . $field['name']])) {
        if (empty($field['options'])) {
          $customFields[$field['custom_group_id.name'] . '.' . $field['name']] = $value;
        }
        else {
          if ($value === '' || $value === NULL || !empty($field['options'][$value])) {
            $customFields[$field['custom_group_id.name'] . '.' . $field['name']] = $value;
          }
          elseif (in_array($value, $field['options'])) {
            $customFields[$field['custom_group_id.name'] . '.' . $field['name']] = array_search($value, $field['options']);
          }
          else {
            // @todo - maybe move to validate? Might be easier once separation from import has been done.
            $value = \CRM_Utils_Type::escape($value, 'String');
            throw new WMFException(WMFException::INVALID_MESSAGE,
              "Invalid value ($value) submitted for custom field {$field['id']}:"
              . "{$field['custom_group_id.title']} {$field['label']} - {$field['custom_group_id.name']}.{$field['name']}");
          }
        }
      }
    }
    return $customFields;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function getCustomFieldMetadataByFieldName(string $name): ?array {
    $fieldsThatAreNotCustom = ['date', 'email', 'country', 'phone'];
    if (in_array($name, $fieldsThatAreNotCustom)) {
      return NULL;
    }
    $fieldsToMap = [
      'utm_campaign' => 'Gift_Data.Appeal',
      'direct_mail_appeal' => 'Gift_Data.Appeal',
      'gift_source' => 'Gift_Data.Campaign',
      'restrictions' => 'Gift_Data.Fund',
      'stock_description' => 'Stock_Information.Description_of_Stock',
      'postmark_date' => 'contribution_extra.Postmark_Date',
      'gateway_status' => 'contribution_extra.gateway_status_raw',
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
    if ($field['custom_group_id.extends'] === 'Contribution' && !in_array($field['name'], [
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
      'Description_of_Stock',
      'gateway_status_raw',
      'Postmark_Date',
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
