<?php

namespace Civi\WMFHelper;

class Phone {

  /**
   * Split a raw phone number into a US country code and national number.
   *
   * We only handle United States numbers at the moment. No US area code
   * starts with 0 or 1, so a leading 1 indicates the number includes the
   * country code - it is stripped off. Otherwise the number is assumed to
   * already be a bare US number.
   *
   * @param string $rawNumber
   *
   * @return array{country_code: string, phone_number: string}
   */
  public static function splitUsNumber(string $rawNumber): array {
    $digits = preg_replace('/[^\d]/', '', $rawNumber);
    if (str_starts_with($digits, '1')) {
      return ['country_code' => '1', 'phone_number' => substr($digits, 1)];
    }
    return ['country_code' => '1', 'phone_number' => $digits];
  }

}
