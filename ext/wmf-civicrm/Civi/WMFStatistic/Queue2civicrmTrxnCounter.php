<?php

namespace Civi\WMFStatistic;

/**
 * A class to keep track of transaction counts, grouped by payment gateway
 */
class Queue2civicrmTrxnCounter {

  protected static $singleton;

  protected $trxnCounts = [];

  protected $ages = [];

  protected function __construct() {}

  public static function instance() {
    if (!self::$singleton) {
      self::$singleton = new Queue2civicrmTrxnCounter();
    }
    return self::$singleton;
  }

  /**
   * Increment the trxn count for a given gateway
   *
   * @param string $gateway
   * @param int $count
   */
  public function increment($gateway, $count = 1) {
    if (!array_key_exists($gateway, $this->trxnCounts)) {
      $this->trxnCounts[$gateway] = 0;
    }
    $this->trxnCounts[$gateway] += $count;
  }

  /**
   * Add a
   *
   * @param string $gateway
   * @param float $age of donation in seconds
   */
  public function addAgeMeasurement($gateway, $age) {
    $this->ages[$gateway][] = $age;
  }

  /**
   * Get counts for all gateways combined or one particular gateway.
   *
   * @param string $gateway
   *
   * @return integer|false trxn count for all gateways ( when $gateway === null
   *   ) or specified gateway
   */
  public function getCountTotal($gateway = NULL) {
    if ($gateway === NULL) {
      return array_sum($this->trxnCounts);
    }
    if (!array_key_exists($gateway, $this->trxnCounts)) {
      return FALSE;
    }
    return $this->trxnCounts[$gateway];
  }

  /**
   * Getter for $this->trxn_counts
   */
  public function getTrxnCounts() {
    return $this->trxnCounts;
  }

  /**
   * Get the average age in seconds for donations
   *
   * @return array
   */
  public function getAverageAges() {
    $averages = [];
    $overallTotal = 0;
    $overallCount = 0;
    foreach ($this->ages as $gateway => $ages) {
      $total = 0;
      foreach ($ages as $age) {
        $total += $age;
        $overallCount += 1;
      }
      $overallTotal += $total;
      $averages[$gateway] = $total / count($ages);
    }
    $averages['overall'] = $overallCount === 0 ? 0 : $overallTotal / $overallCount;
    return $averages;
  }

}
