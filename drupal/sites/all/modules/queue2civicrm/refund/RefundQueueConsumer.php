<?php namespace queue2civicrm\refund;

use Exception;
use wmf_common\TransactionalWmfQueueConsumer;
use \Civi\WMFException\WMFException;

class RefundQueueConsumer extends TransactionalWmfQueueConsumer {

  const PAYPAL_GATEWAY = 'paypal';

  const PAYPAL_EXPRESS_CHECKOUT_GATEWAY = 'paypal_ec';

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
        throw new WMFException(WMFException::CIVI_REQ_FIELD, $error);
      }
    }

    $gateway = $message['gateway'];
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

    $contributions = wmf_civicrm_get_contributions_from_gateway_id($gateway, $parentTxn);

    if ($this->isPaypalRefund($gateway) && empty($contributions)) {
      /**
       * Refunds raised by Paypal do not indicate whether the initial
       * payment was taken using the paypal express checkout (paypal_ec) integration or
       * the legacy paypal integration (paypal). We try to work this out by checking for
       * the presence of specific values in messages sent over, but it appears this
       * isn't watertight as we've seen refunds failing due to incorrect mappings
       * on some occasions. To mitigate this we now fall back to the alternative
       * gateway if no match is found for the gateway supplied.
       */
      $contributions = wmf_civicrm_get_contributions_from_gateway_id(
        $this->getAlternativePaypalGateway($gateway)
        , $parentTxn
      );
    }


    if ($contributions) {
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
      throw new WMFException(WMFException::MISSING_PREDECESSOR, "Parent not found: " . strtoupper($gateway) . " " . $parentTxn);
    }
  }

  private function isPaypalRefund($gateway) {
    return in_array($gateway, [
      static::PAYPAL_EXPRESS_CHECKOUT_GATEWAY,
      static::PAYPAL_GATEWAY,
    ]);
  }

  private function getAlternativePaypalGateway($gateway) {
    return ($gateway == static::PAYPAL_GATEWAY) ? static::PAYPAL_EXPRESS_CHECKOUT_GATEWAY : static::PAYPAL_GATEWAY;
  }

}
