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
      $name_salt = $contactParams['first_name'] . $contactParams['last_name'];
    }
    else {
      $name_salt = $contactParams['organization_name'];
    }

    if ($checkNumber) {
      return md5($contactParams['check_number'] . $name_salt);
    }
    // The scenario where this would happen is anonymous cash gifts.
    // the name would be 'Anonymous Anonymous' and there might be several on the same
    // day. Hence we rely on them all being carefully arranged in a spreadsheet and
    // no-one messing with the order. I was worried this was fragile but there
    // is no obvious better way.
    return md5($date . $name_salt . $rowIndex);
  }

}
