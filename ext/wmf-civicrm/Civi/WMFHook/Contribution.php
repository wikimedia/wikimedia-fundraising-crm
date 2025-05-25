<?php

namespace Civi\WMFHook;

use Civi\API\Event\PrepareEvent;
use Civi\Api4\ExchangeRate;
use Civi\Omnimail\MailFactory;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\Contribution as ContributionHelper;
use Civi\WMFHelper\Database;
use Civi\WMFTransaction;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;

class Contribution {

  /**
   * Intervene with apiv4 Contribution create & edit calls to set `source`.
   *
   * This ensures that on our Donation Queue processing and on imports
   * the `source` field is populated based on the original currency &
   * original amount, if they are present.
   *
   * It makes sense to consolidate this with the `pre` code below - but I kinda hate
   * the way that works so I have created upstream GL
   * https://lab.civicrm.org/dev/core/-/issues/5413 to see if we can come up with a
   * cleaner interface.
   *
   * @param \Civi\API\Event\PrepareEvent $event
   *
   * @return void
   */
  public static function apiPrepare(PrepareEvent $event): void {
    if ($event->getEntityName() !== 'Contribution' || !in_array($event->getActionName(), ['create', 'update'], TRUE)) {
      return;
    }
    $apiRequest = $event->getApiRequest();
    if ($apiRequest['version'] !== 4) {
      // Just handling apiV4 here - which covers the UI imports, along with our
      // DonationQueue. See function comment block.
      return;
    }
    $values = $originalValues = $apiRequest->getValues();
    $isCreate = $event->getActionName() === 'create';
    if ($isCreate) {
      // It should always be source but we have some legacy code still using the old ways.
      $source = $values['source'] ?? $values['contribution_source'] ?? '';
      if ($source) {
        $originalAmountData = ContributionHelper::getOriginalCurrencyAndAmountFromSource($source, $values['total_amount']);
        if (!isset($values['contribution_extra.original_currency'], $values['contribution_extra.original_amount'])) {
          if (!isset($originalAmountData['original_amount'])) {
            throw new \CRM_Core_Exception('unable to determine original currency and amount from source');
          }
          $values['contribution_extra.original_currency'] = $originalAmountData['original_currency'];
          $values['contribution_extra.original_amount'] = $originalAmountData['original_amount'];
        }
      }
      else {
        $values['contribution_extra.original_currency'] = $values['contribution_extra.original_currency'] ?? 'USD';
        $values['contribution_extra.original_amount'] = $values['contribution_extra.original_amount'] ?? $values['total_amount'];
        $values['source'] = $values['contribution_extra.original_currency'] . ' ' . CurrencyRoundingHelper::round($values['contribution_extra.original_amount'], $values['contribution_extra.original_currency']);
      }
    }

    // Now ensure source & converted total_amount are set.
    $originalCurrency = $values['contribution_extra.original_currency'] ?? '';
    $originalAmount = $values['contribution_extra.original_amount'] ?? NULL;
    if ($originalCurrency && is_numeric($originalAmount)) {
      // Fill in total_amount, if necessary.
      if (!isset($values['total_amount']) && $isCreate) {
        if ($originalCurrency === 'USD') {
          $values['total_amount'] = $originalAmount;
        }
        else {
          $values['total_amount'] = (float) ExchangeRate::convert(FALSE)
            ->setFromCurrency($originalCurrency)
            ->setFromAmount($originalAmount)
            ->setTimestamp($values['receive_date'] ?? 'now')
            ->execute()
            ->first()['amount'];
        }
      }
    }
    if ($values !== $originalValues) {
      $apiRequest->setValues($values);
    }
  }

  public static function pre($op, &$contribution): void {
    // @todo consolidate with apiPrepare - I'm kinda holding off in the hope of
    // https://lab.civicrm.org/dev/core/-/issues/5413 helping us here.
    switch ($op) {
      case 'create':
      case 'edit':
        // Add derived wmf_contribution_extra fields to contribution parameters
        if (Database::isNativeTxnRolledBack()) {
          throw new WMFException(
            WMFException::IMPORT_CONTRIB,
            'Native txn rolled back before running pre contribution hook'
          );
        }
        $extra = self::getContributionExtra($contribution);

        if ($extra) {
          $map = self::wmf_civicrm_get_custom_field_map(
            array_keys($extra), 'contribution_extra'
          );
          $mapped = [];
          foreach ($extra as $key => $value) {
            $mapped[$map[$key]] = $value;
          }
          $contribution += $mapped;
          // FIXME: Seems really ugly that we have to do this, but when
          // a contribution is created via api3, the _pre hook fires
          // after the custom field have been transformed and copied
          // into the 'custom' key
          $formatted = [];
          _civicrm_api3_custom_format_params($mapped, $formatted, 'Contribution');
          if (isset($contribution['custom'])) {
            $contribution['custom'] += $formatted['custom'];
          }
          else {
            $contribution['custom'] = $formatted['custom'];
          }
        }

        break;
    }
  }

  /**
   * @param $field_names
   * @param $group_name
   *
   * @deprecated - should not be needed with correct api v4 useage.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private static function wmf_civicrm_get_custom_field_map($field_names, $group_name = NULL) {
    static $custom_fields = [];
    foreach ($field_names as $name) {
      if (empty($custom_fields[$name])) {
        $id = \CRM_Core_BAO_CustomField::getCustomFieldID($name, $group_name);
        if (!$id) {
          throw new \CRM_Core_Exception('id is missing: ' . $name . ' ' . $group_name);
        }
        $custom_fields[$name] = "custom_{$id}";
      }
    }

    return $custom_fields;
  }
  /**
   * @param array $contribution
   *
   * @return array
   */
  private static function getContributionExtra(array $contribution) {
    $extra = [];

    if (!empty($contribution['trxn_id'])) {
      try {
        $transaction = WMFTransaction::from_unique_id($contribution['trxn_id']);
        $extra['gateway'] = strtolower($transaction->gateway);
        $extra['gateway_txn_id'] = $transaction->gateway_txn_id;
      }
      catch (WMFException $ex) {
        \Civi::log('wmf')->info('wmf_civicrm: Failed to parse trxn_id: {trxn_id}, {message}',
          ['trxn_id' => $contribution['trxn_id'], 'message' => $ex->getMessage()]
        );
      }
    }

    if (!empty($contribution['source'])) {
      $extra = array_merge($extra, ContributionHelper::getOriginalCurrencyAndAmountFromSource((string) $contribution['source'], $contribution['total_amount']));
    }
    return $extra;
  }

  /**
   * Additional validations for the contribution form
   *
   * @param array $fields
   * @param \CRM_Core_Form $form
   *
   * @return array of any errors in the form
   */
  public static function validateForm(array $fields, $form): array {
    $errors = [];

    // Only run on add or update
    if (!($form->getAction() & (\CRM_Core_Action::UPDATE | \CRM_Core_Action::ADD))) {
      return $errors;
    }
    // Source has to be of the form USD 15.25 so as not to gum up the works,
    // and the currency code on the front should be something we understand
    $source = $fields['source'];
    if (preg_match('/^([a-z]{3}) -?[0-9]+(\.[0-9]+)?$/i', $source, $matches)) {
      $currency = strtoupper($matches[1]);
      if (!ContributionHelper::isValidCurrency($currency)) {
        $errors['source'] = t('Please set a supported currency code');
      }
    }
    else {
      $errors['source'] = t('Source must be in the format USD 15.25');
    }

    return $errors;
  }

  /**
   * Implements hook_civicrm_post
   */
  public static function post($action, $contribution) {
    switch ($action) {
      case 'create':
        if ($contribution->total_amount <= self::getMinimumLargeDonationThreshold()) {
          return;
        }

        foreach (self::getLargeDonationThresholds() as $notification) {
          $excludedFinancialTypes = explode(',', $notification['financial_types_excluded']);
          if (!in_array($contribution->financial_type_id, $excludedFinancialTypes)) {
            if ($contribution->total_amount > $notification['threshold']) {
              \Civi::log( 'wmf' )->info(
                'large_donation: Notifying of large donation, contribution: {contribution_id} above threshold {threshold}',
                [ 'contribution_id' => $contribution->id, 'threshold' => $notification['threshold'] ]
              );
              self::sendLargeDonationNotification( $contribution, $notification );
            }
          }
        }
        break;
      default:
    }
  }

  /**
   * Get large donation thresholds.
   */
  private static function getLargeDonationThresholds(): array {
    return \Civi::settings()->get('large_donation_notifications') ?? [];
  }

  /**
   * Get the minimum amount that could trigger a notification.
   *
   * @return float
   */
  private static function getMinimumLargeDonationThreshold(): float {
    if (!isset( \Civi::$statics[__CLASS__]['large_donation_minimum_threshold'])) {
      $minThreshold = 100000000000000;
      $notifications = self::getLargeDonationThresholds();
      foreach ( $notifications as $threshold ) {
        if ( $threshold['threshold'] < $minThreshold ) {
          $minThreshold = $threshold['threshold'];
        }
      }
      \Civi::$statics[__CLASS__]['large_donation_minimum_threshold'] = $minThreshold;
    }
    return \Civi::$statics[__CLASS__]['large_donation_minimum_threshold'];

  }

  /**
   * The email should include the total amount, source amount, contact and
   * contribution ids, and a link to the contact/contribution.
   *
   * No personally identifiable information should be included.
   *
   * @param \CRM_Contribute_BAO_Contribution $contribution
   * @param array $notification
   */
  private static function sendLargeDonationNotification($contribution, $notification): void {
    $contact_link = \CRM_Utils_System::url(
      'civicrm/contact/view',
      [
        'selectedChild' => 'contribute',
        'cid' => $contribution->contact_id,
        'reset' => 1,
      ],
      TRUE,
      'Contributions'
    );

    $contribution_link = \CRM_Utils_System::url(
      'civicrm/contact/view/contribution',
      [
        'action' => 'view',
        'id' => $contribution->id,
        'reset' => 1,
      ],
      TRUE
    );

    $to = $notification['addressee'];

    $params = [
      'threshold' => (float) $notification['threshold'],
      'total_amount' => $contribution->total_amount,
      'source' => $contribution->source,
      'contact_link' => $contact_link,
      'contribution_link' => $contribution_link,
    ];

    if (!$to) {
      \Civi::log('wmf')->error('large_donation: Notification recipient address is not set up!');
      return;
    }

    $mailer = MailFactory::singleton()->getMailer();

    $message = self::getLargeDonationMessage($params);

    try {
      $email = [
        'to' => $to,
        'from_address' => 'fr-tech+large_donation@wikimedia.org',
        'from_name' => 'Large Donation Bot',
        'subject' => "WMF - large donation: \${$contribution->total_amount}",
        'html' => $message,
      ];
      $mailer->send($email);
      \Civi::log('wmf')->info(
        'large_donation: A large donation notification was sent to: {to}',
        [ 'to' => print_r( $to, TRUE ) ]
      );
    }
    catch (\Exception $e) {
      \Civi::log('wmf')->alert(
        'large_donation: Sending large donation message failed for contribution: {contribution_id}<pre> {contribution} \n\n {message}</pre>',
        [
          'contribution_id' => $contribution->id,
          'contribution' => $contribution,
          'message' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Get the contents of the notification email.
   *
   * @param array $params
   *
   * @return string
   */
  private static function getLargeDonationMessage( array $params): string {

    return "<p>To whom it may concern:</p>

<p>A large donation was made (over {$params['threshold']} USD):</p>

<p>converted amount: {$params['total_amount']} USD</p>

<p>original amount: {$params['source']}</p>

<p><a href='{$params['contact_link']}'>View Contributions from Contact in CiviCRM</a></p>

<p><a href='{$params['contribution_link']}'>View Contribution in CiviCRM</a></p>

<p>You may need to examine this donation.</p>
  ";
  }

}
