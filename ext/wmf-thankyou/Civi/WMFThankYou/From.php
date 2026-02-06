<?php
namespace Civi\WMFThankYou;

class From {
  protected static $settingsMap = [
    'thank_you' => [
      'name' => 'wmf_thank_you_from_name',
      'address' => 'wmf_thank_you_from_address'
    ],
    'endowment_thank_you' => [
      'name' => 'wmf_endowment_thank_you_from_name',
      'address' => 'wmf_endowment_thank_you_from_address'
    ],
    'monthly_convert' => [
      'name' => 'wmf_monthly_convert_thank_you_from_name',
      'address' => 'wmf_monthly_convert_thank_you_from_address'
    ],
    'eoy' => [
      'name' => 'wmf_eoy_thank_you_from_name',
      'address' => 'wmf_eoy_thank_you_from_address'
    ],
    'double_opt_in' => [
      'name' => 'wmf_thank_you_from_name',
      'address' => 'wmf_thank_you_from_address'
    ],
  ];

  public static function getFromName($template) {
    return \Civi::settings()->get(self::$settingsMap[$template]['name']);
  }

  public static function getFromAddress($template) {
    return \Civi::settings()->get(self::$settingsMap[$template]['address']);
  }
}
