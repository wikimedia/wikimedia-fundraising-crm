<?php

use CRM_ExchangeRates_ExtensionUtil as E;

class CRM_ExchangeRates_BAO_ExchangeRate extends CRM_ExchangeRates_DAO_ExchangeRate {

  public static function getFromCache(string $currency, string $timestamp): ?float {
    $key = self::makeCacheKey($currency, $timestamp);
    return Civi::$statics[__CLASS__][$key] ?? NULL;
  }

  public static function addToCache(string $currency, string $timestamp, float $valueInUsd): void {
    $key = self::makeCacheKey($currency, $timestamp);
      Civi::$statics[__CLASS__][$key] = $valueInUsd;
  }

  public static function makeCacheKey(string $currency, string $timestamp): string {
    $date = new DateTime($timestamp);
    $granularity = \Civi::settings()->get('exchange_rates_cache_granularity') ?? 'day';
    $formatMap = [
      'hour' => 'Y-m-d H:00:00',
      'day' => 'Y-m-d 00:00:00',
      'month' => 'Y-m-01 00:00:00',
    ];
    return $currency . '-' . $date->format($formatMap[$granularity]);
  }

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
