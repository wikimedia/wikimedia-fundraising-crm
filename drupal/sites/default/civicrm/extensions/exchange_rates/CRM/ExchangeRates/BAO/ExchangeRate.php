<?php

use CRM_ExchangeRates_ExtensionUtil as E;

class CRM_ExchangeRates_BAO_ExchangeRate extends CRM_ExchangeRates_DAO_ExchangeRate {

  /**
   * Override base writeRecord to support the unique constraint on currency and bank_update
   *
   * @param array $record
   * @return CRM_Core_DAO
   * @throws CRM_Core_Exception
   */
  public static function writeRecord(array $record): CRM_Core_DAO {
    $existingId = self::singleValueQuery(
      'SELECT id FROM ' . self::$_tableName . ' WHERE currency = %1 AND bank_update = %2',
      [1 => [$record['currency'], 'String'], 2 => [$record['bank_update'], 'Timestamp']]
    );
    if ($existingId) {
      $record['id'] = $existingId;
    }
    return parent::writeRecord($record);
  }
}
