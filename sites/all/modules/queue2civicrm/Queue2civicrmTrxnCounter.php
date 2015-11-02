<?php

/**
 * A class to keep track of transaction counts, grouped by payment gateway
 */
class Queue2civicrmTrxnCounter {
  protected static $singleton;
  protected $trxn_counts = array();

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
}
