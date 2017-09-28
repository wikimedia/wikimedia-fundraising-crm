<?php

/**
 * A class to keep track of transaction counts, grouped by payment gateway
 */
class Queue2civicrmTrxnCounter {
  protected static $singleton;
  protected $trxn_counts = array();
  protected $ages = array();

  protected function __construct() {}

  public static function instance() {
    if ( !self::$singleton ) {
	  self::$singleton = new Queue2civicrmTrxnCounter();
    }
    return self::$singleton;
  }

  /**
   * Increment the trxn count for a given gateway
   * @param string $gateway
   * @param int $count
   */
  public function increment( $gateway, $count = 1 ) {
    if ( !array_key_exists( $gateway, $this->trxn_counts )) {
      $this->trxn_counts[$gateway] = 0;
    }
    $this->trxn_counts[$gateway] += $count;
  }

	/**
	 * Add a 
	 * @param string $gateway
	 * @param float $age of donation in seconds
	 */
  public function addAgeMeasurement( $gateway, $age ) {
    $this->ages[$gateway][] = $age;
  }

  /**
   * Get counts for all gateways combined or one particular gateway.
   * @param string $gateway
   * @return integer|false trxn count for all gateways ( when $gateway === null ) or specified gateway
   */
  public function get_count_total( $gateway = null ) {
    if ( $gateway === null ) {
      return array_sum( $this->trxn_counts );
    }
    if ( !array_key_exists( $gateway, $this->trxn_counts ) ) {
      return false;
    }
    return $this->trxn_counts[$gateway];
  }

  /**
   * Getter for $this->trxn_counts
   */
  public function get_trxn_counts() {
    return $this->trxn_counts;
  }

  /**
   * Get the average age in seconds for donations
   * @return array
   */
  public function get_average_ages() {
    $averages = array();
    $overallTotal = 0;
    $overallCount = 0;
    foreach ( $this->ages as $gateway => $ages ) {
      $total = 0;
      foreach ( $ages as $age ) {
        $total += $age;
        $overallCount += 1;
      }
      $overallTotal += $total;
      $averages[$gateway] = $total / count( $ages );
    }
    $averages['overall'] = $overallCount === 0 ? 0 : $overallTotal / $overallCount;
    return $averages;
  }
}
