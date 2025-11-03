<?php

namespace Civi\WMFHelper;

use Civi\Api4\Contact;
use Civi\Api4\ExchangeRate;
use Civi\WMFException\WMFException;
use SmashPig\PaymentData\ReferenceData\CurrencyRates;

class Contribution {

  /**
   * Generate a unique-ish transaction reference if there is not a provided one.
   *
   * This is used for assigning a unique gateway trxn id when importing csvs
   *
   * @param array $contactParams containing name fields
   * @param string $date
   * @param string|null $checkNumber
   * @param int $rowIndex
   *   Row number within the import file.
   * @param string|null $batchNumber
   * @param int $userJobID
   *
   * @return string
   */
  public static function generateTransactionReference(array $contactParams, string $date, ?string $checkNumber, int $rowIndex, ?string $batchNumber, int $userJobID): string {
    if ($contactParams['contact_type'] === 'Individual') {
      $name_salt = ($contactParams['first_name'] ?? '') . ($contactParams['last_name'] ?? '');
    }
    else {
      $name_salt = $contactParams['organization_name'];
    }
    return md5(($checkNumber ?: '') . $date . $name_salt . ($batchNumber ?? $userJobID) . $rowIndex);
  }

  /**
   * Does the contribution already exist.
   *
   * @param string $gateway
   * @param string $gatewayTrxnID
   *
   * @return false|int
   * @throws \CRM_Core_Exception
   */
  public static function exists(string $gateway, string $gatewayTrxnID) {
    return \Civi\Api4\Contribution::get(FALSE)->addWhere(
      'contribution_extra.gateway', '=', $gateway
    )->addWhere(
      'contribution_extra.gateway_txn_id', '=', $gatewayTrxnID
    )->execute()->first()['id'] ?? FALSE;
  }

  /**
   * @param string $op
   * @param \CRM_Contribute_BAO_Contribution $contribution
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public static function updateWMFDonorLastDonation(string $op, $contribution) {
    switch ($op) {
      case 'create':
      case 'edit':
        if (Database::isNativeTxnRolledBack()) {
          throw new WMFException(
            WMFException::IMPORT_CONTRIB,
            'Native txn rolled back before running post contribution hook'
          );
        }
        // Update wmf_donor row for the associated contact
        $params = self::getLastDonationParams($contribution);
        if (!empty($params)) {
          Contact::update(FALSE)
            ->setValues($params)
            ->addWhere('id', '=', $contribution->contact_id)
            ->execute();
        }

        break;
    }
  }

  /**
   * @param $values
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getConvertedTotalAmount($values): ?float {
    $originalCurrency = $values['contribution_extra.original_currency'] ?? '';
    $originalAmount = $values['contribution_extra.original_amount'] ?? NULL;
    $totalAmount = $values['total_amount'] ?? NULL;
    if ($originalCurrency && is_numeric($originalAmount)) {
      if ($originalCurrency === 'USD') {
        $totalAmount = $originalAmount;
      }
      else {
        $totalAmount = (float) ExchangeRate::convert(FALSE)
          ->setFromCurrency($originalCurrency)
          ->setFromAmount($originalAmount)
          ->setTimestamp($values['receive_date'] ?? 'now')
          ->execute()
          ->first()['amount'];
      }
    }
    return $totalAmount;
  }

  /**
   * Get the details of the last donation received if it differs from the current one.
   *
   * We use this information to update the last_donation_amount and
   * last_donation_currency fields. They will already have been updated to hold
   * the information about the current one in those fields so we only return the
   * information if it differs.
   *
   * @param \CRM_Contribute_BAO_Contribution $contribution
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  private static function getLastDonationParams($contribution) {
    $contributionStatus = \CRM_Core_PseudoConstant::getLabel('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $contribution->contribution_status_id);
    if (empty($contribution->total_amount) && (!$contributionStatus)) {
      return [];
    }
    $isRefund = substr($contribution->trxn_id ?? '', 0, 4) === 'RFD ';
    if ($contributionStatus === 'Completed'
      && !empty($contribution->receive_date)
      && !$isRefund
      && substr($contribution->receive_date, 0, 8) === date('Ymd')
    ) {
      // If the current donation was received today do an early return.
      // What we lose in accuracy (perhaps 2 have come in on the same day) we
      // gain in performance.
      return [];
    }
    if (!$contribution->contact_id || !$contribution->total_amount) {
      $contribution->find(TRUE);
    }
    $params = [];

    // The old code used to assume that 'any' insert was the latest. Here we check.
    // We could possibly 'assume' it to be the latest if on the latest day - although in that
    // case we probably lose a get query & gain an 'update' query as the extra fields are likely already
    // updated by the triggers.
    if (!$contribution->contact_id) {
      \Civi::log('wmf')->warning('no contact ID found for contribution {id} {contribution}',
        ['id' => $contribution->id, 'contribution' => (array) $contribution],
      );
    }
    $contactLastDonation = self::getContactLastDonationData((int) $contribution->contact_id);
    $extra = self::getOriginalCurrencyAndAmountFromSource((string) $contribution->source, $contribution->total_amount);
    if ($contributionStatus === 'Completed' && !$isRefund) {
      // This is a 'valid' transaction - it's either the latest or no update is required.
      if (
        !empty($contactLastDonation['wmf_donor.last_donation_date']) &&
        !empty($contribution->receive_date) &&
        strtotime($contactLastDonation['wmf_donor.last_donation_date']) === strtotime($contribution->receive_date)
      ) {
        if (!empty($extra['original_currency']) && $contactLastDonation['wmf_donor.last_donation_currency'] !== \CRM_Utils_Array::value('original_currency', $extra)) {
          $params['wmf_donor.last_donation_currency'] = $extra['original_currency'];
        }
        if (!empty($extra['original_amount']) && round($contactLastDonation['wmf_donor.last_donation_amount'], 2) !== round(\CRM_Utils_Array::value('original_amount', $extra), 2)) {
          $params['wmf_donor.last_donation_amount'] = $extra['original_amount'];
        }
        if (round($contactLastDonation['wmf_donor.last_donation_usd'], 2) !== round($contribution->total_amount, 2)) {
          $params['wmf_donor.last_donation_usd'] = $contribution->total_amount;
        }
      }
      if (!empty($params)) {
        \Civi::log('wmf')->info(
          'wmf_civicrm: Contribution post hook is changing values for contribution {contribution_id} ' .
          'from {from} to {to}', [
            'contribution_id' => $contribution->id,
            'from' => $contactLastDonation,
            'to' => $params,
          ]
        );
      }
      return $params;
    }

    // We don't have a completed transaction here - probably a refund - time to get the details of the latest & update it.
    // (From back office it could also be pending but we probably don't stand to gain much by special handling pendings as low volume).
    $latestContribution = \Civi\Api4\Contribution::get(FALSE)
      ->addWhere('contribution_status_id:name', '=', 'Completed')
      ->addWhere('contact_id', '=', $contribution->contact_id)
      ->addWhere('trxn_id', 'NOT LIKE', 'RFD %')
      ->addOrderBy('receive_date', 'DESC')
      ->addSelect('total_amount', 'contribution_extra.original_currency', 'contribution_extra.original_amount')
      ->setLimit(1)
      ->execute()->first();
    if (!$latestContribution) {
      return $params;
    }

    $latestContributionCurrency = $latestContribution['contribution_extra.original_currency'];
    $latestContributionAmount = $latestContribution['contribution_extra.original_amount'];

    if ($latestContributionCurrency !== \CRM_Utils_Array::value('original_currency', $extra)) {
      $params['wmf_donor.last_donation_currency'] = $latestContributionCurrency;
    }
    if (round($contactLastDonation['wmf_donor.last_donation_amount'], 2) !== round($latestContributionAmount, 2)) {
      $params['wmf_donor.last_donation_amount'] = $latestContributionAmount;
    }
    if (round($contactLastDonation['wmf_donor.last_donation_usd'], 2) !== round($latestContribution['total_amount'], 2)) {
      $params['wmf_donor.last_donation_usd'] = $latestContribution['total_amount'];
    }
    return $params;
  }

  /**
   * Get original currency & amount
   *
   * The source field holds the amount & currency - parse it out
   * e.g 'USD 15.25'
   *
   * @param string $source
   * @param float $usd_amount
   *
   * @return array
   */
  public static function getOriginalCurrencyAndAmountFromSource(string $source, $usd_amount): array {
    if (empty($source)) {
      return [];
    }
    [$original_currency, $original_amount] = explode(" ", str_replace('  ', ' ', trim($source)));
    if (is_numeric($original_amount) && self::isValidCurrency($original_currency)) {
      return ['original_currency' => $original_currency, 'original_amount' => $original_amount];
    }

    if (is_numeric($original_amount)) {
      return ['original_currency' => 'USD', 'original_amount' => $usd_amount];
    }
    return [];
  }

  /**
   * Determine if a code represents a supported currency. Uses the
   * SmashPig currency list as a canonical source.
   *
   * @param string $currency should be an ISO 4217 code
   *
   * @return bool true if it's a real currency that we can handle
   */
  public static function isValidCurrency(string $currency): bool {
    $all_currencies = array_keys(CurrencyRates::getCurrencyRates());
    return in_array($currency, $all_currencies);
  }

  /**
   * @param int $contactID
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private static function getContactLastDonationData(int $contactID): array {
    $result = Contact::get(FALSE)->addWhere('id', '=', $contactID)
      ->addSelect(
        'wmf_donor.last_donation_currency',
        'wmf_donor.last_donation_amount',
        'wmf_donor.last_donation_date',
        'wmf_donor.last_donation_usd'
      )
      ->execute()->first();
    if (!$result) {
      \Civi::log('wmf')->warning(__CLASS__ . ': ' . __FUNCTION__ . ' unexpectedly no result - since this is in the post hook it *should* exit. Contact ID {contact_id}', ['contact_id' => $contactID]);
      return [];
    }
    return $result;
  }

}
