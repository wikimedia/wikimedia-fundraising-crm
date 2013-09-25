<?php

/**
 * A class to keep track of transaction counts for various payment gateways
 */
class Queue2civicrmTrxnCounter {
  protected $gateways = array();
  protected $trxn_counts = array();

  /**
   * Constructor
   *
   * Takes an array of gateway names to keep track of trxn counts.  The
   * gateway names should be exactly as they appear in transactional messages.
   * @param array $gateways
   */
  public function __construct( array $gateways ) {
    $this->gateways = $gateways;
    foreach ( $gateways as $gateway ) {
      $this->trxn_counts[ $gateway ] = 0;
    }
  }

  /**
   * Increment the trxn count for a given gateway
   * @param string $gateway
   * @param int $count
   */
  public function add( $gateway, $count ) {
    if ( !in_array( $gateway, $this->gateways )) {
      return false;
    }
    $this->trxn_counts[ $gateway ] += $count;
  }

  /**
   * Get counts for all gateways combined or one particular gateway.
   * @param string $gateway
   * @return trxn count for all gateways ( when $gateway === null ) or specified gateway
   */
  public function get_count_total( $gateway = null ) {
    if ( $gateway ) {
      if ( !in_array( $gateway, $this->gateways )) {
        return false;
      }
      return $this->trxn_counts[ $gateway ];
    } else {
      return array_sum( $this->trxn_counts );
    }
  }

  /**
   * Getter for $this->trxn_counts
   */
  public function get_trxn_counts() {
    return $this->trxn_counts;
  }
}

<?php

/**
 * A class to keep track of transaction counts for various payment gateways
 */
class Queue2civicrmTrxnCounter {
  protected $gateways = array();
  protected $trxn_counts = array();

  /**
   * Constructor
   *
   * Takes an array of gateway names to keep track of trxn counts.  The
   * gateway names should be exactly as they appear in transactional messages.
   * @param array $gateways
   */
  public function __construct( array $gateways ) {
    $this->gateways = $gateways;
    foreach ( $gateways as $gateway ) {
      $this->trxn_counts[ $gateway ] = 0;
    }
  }

  /**
   * Increment the trxn count for a given gateway
   * @param string $gateway
   * @param int $count
   */
  public function add( $gateway, $count ) {
    if ( !in_array( $gateway, $this->gateways )) {
      return false;
    }
    $this->trxn_counts[ $gateway ] += $count;
  }

  /**
   * Get counts for all gateways combined or one particular gateway.
   * @param string $gateway
   * @return trxn count for all gateways ( when $gateway === null ) or specified gateway
   */
  public function get_count_total( $gateway = null ) {
    if ( $gateway ) {
      if ( !in_array( $gateway, $this->gateways )) {
        return false;
      }
      return $this->trxn_counts[ $gateway ];
    } else {
      return array_sum( $this->trxn_counts );
    }
  }

  /**
   * Getter for $this->trxn_counts
   */
  public function get_trxn_counts() {
    return $this->trxn_counts;
  }
}

