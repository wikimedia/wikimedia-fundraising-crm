<?php

namespace Civi\WMFHelpers;

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
   *
   * @return string
   */
  public static function generateTransactionReference(array $contactParams, string $date, ?string $checkNumber, int $rowIndex): string {
    if ($contactParams['contact_type'] === 'Individual') {
      $name_salt = ($contactParams['first_name'] ?? '') . ($contactParams['last_name'] ?? '');
    }
    else {
      $name_salt = $contactParams['organization_name'];
    }
    return md5($checkNumber ?: $date . $name_salt . $rowIndex);
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

}
