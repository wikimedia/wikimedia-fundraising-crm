<?php
namespace Civi;

use Civi\WMFException\WMFException;

/**
 * Contain assumptions about our transactions.
 *
 * Data is lazy-loaded, so an object of this type is efficient to use as a
 * temporary helper variable.
 *
 * For example,
 *   $trxn_id = WmfTransaction::from_message( $msg )->get_unique_id();
 *
 * This wraps our unique ID generator / parser, and...
 */
class WMFTransaction {

  public $gateway;

  public $gateway_txn_id;

  /**
   * @var bool
   */
  public $is_refund;

  /**
   * @var bool
   */
  public $is_chargeback;

  /**
   * @var bool
   */
  public $is_chargeback_reversal;

  /**
   * @var bool
   */
  public $is_recurring;

  /**
   * @var int
   *
   * @deprecated
   */
  public $timestamp;

  /**
   * @var string
   */
  public $unique_id;

  /**
   * @return string
   * @throws \Civi\WMFException\WMFException
   */
  public function get_unique_id(): string {
    $parts = [];

    if ($this->is_refund) {
      $parts[] = "RFD";
    }

    if ($this->is_chargeback) {
      $parts[] = "CHARGEBACK";
    }

    if ($this->is_chargeback_reversal) {
      $parts[] = "CHARGEBACK_REVERSAL";
    }

    if ($this->is_recurring) {
      $parts[] = "RECURRING";
    }

    if (!$this->gateway) {
      throw new WMFException(WMFException::INVALID_MESSAGE, 'Missing gateway.');
    }
    if (!$this->gateway_txn_id) {
      throw new WMFException(WMFException::INVALID_MESSAGE, 'Missing gateway_txn_id.');
    }
    $parts[] = strtoupper($this->gateway);
    $parts[] = $this->gateway_txn_id;

    return implode(" ", $parts);
  }

  public static function from_message($msg) {
    // queue message, does not have a unique id yet
    $transaction = new WMFTransaction();
    if (isset($msg['gateway_refund_id'])) {
      $transaction->gateway_txn_id = $msg['gateway_refund_id'];
    }
    else {
      $transaction->gateway_txn_id = $msg['gateway_txn_id'];
    }
    $transaction->gateway = $msg['gateway'];
    $transaction->is_recurring = !empty($msg['recurring']);
    $messageType = $msg['type'] ?? NULL;
    $transaction->is_chargeback_reversal = ($messageType === 'chargeback_reversed');
    $transaction->is_chargeback = ($messageType === 'chargeback');
    $transaction->is_refund = in_array($messageType, ['chargeback', 'refund'], TRUE);
    return $transaction;
  }

  public static function from_unique_id($unique_id): WMFTransaction {
    $transaction = new WMFTransaction();

    $parts = explode(' ', trim($unique_id));

    $transaction->is_refund = FALSE;
    while ($parts and in_array($parts[0], ['RFD', 'REFUND'])) {
      $transaction->is_refund = TRUE;
      array_shift($parts);
    }

    $transaction->is_recurring = FALSE;
    while ($parts and $parts[0] === 'RECURRING') {
      $transaction->is_recurring = TRUE;
      array_shift($parts);
    }

    switch (count($parts)) {
      case 0:
        throw new WMFException(WMFException::INVALID_MESSAGE, "Unique ID is missing terms.");

      case 3:
        // TODO: deprecate timestamp
        $transaction->timestamp = array_pop($parts);
        if (!is_numeric($transaction->timestamp)) {
          throw new WMFException(WMFException::INVALID_MESSAGE, "Malformed unique id (timestamp does not appear to be numeric)");
        }
      // pass
      case 2:
        $transaction->gateway = strtolower(array_shift($parts));
      // pass
      case 1:
        // Note that this sucks in effort_id and any other stuff we're
        // using to maintain an actually-unique per-gateway natural key.
        $transaction->gateway_txn_id = array_shift($parts);
        if (empty($transaction->gateway_txn_id)) {
          throw new WMFException(WMFException::INVALID_MESSAGE, "Empty gateway transaction id");
        }
        break;
      default:
        throw new WMFException(WMFException::INVALID_MESSAGE, "Malformed unique id (too many terms): " . $unique_id);
    }

    // TODO: debate whether to renormalize here
    $transaction->unique_id = $transaction->get_unique_id();

    return $transaction;
  }

}
