<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Name;
use Civi\WMFHelper\FinanceInstrument;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFException\WMFException;

class DonationMessage {

  /**
   * @var array WMF message with keys (incomplete list)
   *  - recurring
   *  - contribution_recur_id
   *  - subscr_id
   *  - recurring_payment_token
   */
  protected $message;

  /**
   * @param $message
   */
  public function __construct($message) {
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
    // Decode the message body.
    if (!is_array($msg)) {
      $msg = json_decode($msg->body, TRUE);
    }

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
      // FIXME: Default to now. If you can think of a better thing to do in
      // the name of historical exchange rates.  Searching ts and
      // source_enqueued_time is a good start.
      'date' => time(),
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
      'thankyou_date' => NULL,
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

    wmf_civicrm_removeKnownBadStringsFromAddressFields($msg);

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

    // Convert times to Unix timestamps.
    if (!is_numeric($msg['date'])) {
      $msg['date'] = wmf_common_date_parse_string($msg['date']);
    }
    // if all else fails, fall back to now.
    if (empty($msg['date'])) {
      $msg['date'] = time();
    }

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

    if (!empty($msg['thankyou_date'])) {
      if (!is_numeric($msg['thankyou_date'])) {
        $unix_time = wmf_common_date_parse_string($msg['thankyou_date']);
        if ($unix_time !== FALSE) {
          $msg['thankyou_date'] = $unix_time;
        }
        else {
          \Civi::log('wmf')->debug('wmf_civicrm: Could not parse thankyou date: {date} from {id}', [
            'date' => $msg['thankyou_date'],
            'id' => $msg['contribution_tracking_id'],
          ]);
          unset($msg['thankyou_date']);
        }
      }
    }

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

    if (wmf_civicrm_is_endowment_gift($msg)) {
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
    $msg = wmf_civicrm_normalize_contribution_amounts($msg);

    return $msg;
  }

}
