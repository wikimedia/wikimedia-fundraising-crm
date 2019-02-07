<?php namespace queue2civicrm\refund;

use Exception;
use wmf_common\TransactionalWmfQueueConsumer;
use WmfException;

class RefundQueueConsumer extends TransactionalWmfQueueConsumer {

  public function processMessage($message) {

    // Sanity checking :)
    $required_fields = [
      "gateway_parent_id",
      "gross_currency",
      "gross",
      "date",
      "gateway",
      "type",
    ];
    foreach ($required_fields as $field_name) {
      if (!array_key_exists($field_name, $message)) {
        $error = "Required field '$field_name' not present! Dropping message on floor.";
        throw new WmfException(WmfException::CIVI_REQ_FIELD, $error);
      }
    }

    $gateway = strtoupper($message['gateway']);
    $parentTxn = $message['gateway_parent_id'];
    $refundTxn = isset($message['gateway_refund_id']) ? $message['gateway_refund_id'] : NULL;
    if ($refundTxn === NULL) {
      $logId = $parentTxn;
    }
    else {
      $logId = $refundTxn;
    }


    if ($message['gross'] < 0) {
      $message['gross'] = abs($message['gross']);
    }

    if ($contributions = wmf_civicrm_get_contributions_from_gateway_id($gateway, $parentTxn)) {
      // Perform the refund!
      try {
        watchdog('refund', "$logId: Marking as refunded", NULL, WATCHDOG_INFO);
        wmf_civicrm_mark_refund($contributions[0]['id'], $message['type'], TRUE, $message['date'],
          $refundTxn,
          $message['gross_currency'],
          $message['gross']
        );

        watchdog('refund', "$logId: Successfully marked as refunded", NULL, WATCHDOG_INFO);
      } catch (Exception $ex) {
        watchdog('refund', "$logId: Could not refund due to internal error: " . $ex->getMessage(), NULL, WATCHDOG_ERROR);
        throw $ex;
      }
    }
    else {
      watchdog('refund', "$logId: Contribution not found for this transaction!", NULL, WATCHDOG_ERROR);
      throw new WmfException(WmfException::MISSING_PREDECESSOR, "Parent not found: $gateway $parentTxn");
    }
  }

}
