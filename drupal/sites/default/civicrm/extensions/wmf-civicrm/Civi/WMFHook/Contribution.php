<?php

namespace Civi\WMFHook;

use Civi\WMFException\WMFException;
use Civi\WMFHelper\Contribution as ContributionHelper;
use Civi\WMFHelper\Database;
use Civi\WMFTransaction;

class Contribution {

  public static function pre($op, &$contribution): void {
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
          $map = wmf_civicrm_get_custom_field_map(
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
      if (!wmf_civicrm_is_valid_currency($currency)) {
        $errors['source'] = t('Please set a supported currency code');
      }
    }
    else {
      $errors['source'] = t('Source must be in the format USD 15.25');
    }

    return $errors;
  }

}
