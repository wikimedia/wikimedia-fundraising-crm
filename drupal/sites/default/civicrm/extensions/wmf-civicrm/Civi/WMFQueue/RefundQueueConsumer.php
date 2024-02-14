<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Exception;
use SmashPig\Core\DataStores\QueueWrapper;
use Civi\WMFQueue\TransactionalQueueConsumer;
use \Civi\WMFException\WMFException;

class RefundQueueConsumer extends TransactionalQueueConsumer {

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
      if (!array_key_exists($field_name, $message) || empty($message[$field_name])) {
        $error = "Required field '$field_name' not present! Dropping message on floor.";
        throw new WMFException(WMFException::CIVI_REQ_FIELD, $error);
      }
    }

    $contributionStatus = $this->mapRefundTypeToContributionStatus($message['type']);
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
    // Fall back to searching by invoice ID, generally for Ingenico recurring
    if (empty($contributions) && !empty($message['invoice_id'])) {
      $contributions = Contribution::get(FALSE)
        ->addClause(
          'OR',
          ['invoice_id', '=', $message['invoice_id']],
          // For recurring payments, we sometimes append a | and a random number after the invoice ID
          ['invoice_id', 'LIKE', $message['invoice_id'] . '|%']
        )
        ->execute()
        // Flatten it to an array so it's false-y if no result
        ->getArrayCopy();
    }

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
    $context = ['log_id' => $logId];
    if ($contributions) {
      // Perform the refund!
      try {
        \Civi::log('wmf')->info('refund {log_id}: Marking as refunded', $context);
        wmf_civicrm_mark_refund($contributions[0]['id'], $contributionStatus, TRUE, $message['date'],
          $refundTxn,
          $message['gross_currency'],
          $message['gross']
        );

        \Civi::log('wmf')->info('refund {log_id}: Successfully marked as refunded', $context);
      }
      catch (Exception $ex) {
        \Civi::log('wmf')->error('refund {log_id}: Could not refund due to internal error: {message}', array_merge($context, ['message' => $ex->getMessage()]));
        throw $ex;
      }
      $this->cancelRecurringOnChargeback($contributionStatus, $contributions);
    }
    else {
      \Civi::log('wmf')->error('refund {log_id}: Contribution not found for this transaction!', $context);
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

  private function mapRefundTypeToContributionStatus(string $type): string {
    $validTypes = [
      'refund' => 'Refunded',
      'chargeback' => 'Chargeback',
      'cancel' => 'Cancelled',
      'reversal' => 'Chargeback', // from the audit processor
      'admin_fraud_reversal' => 'Chargeback', // raw IPN code
    ];

    if (!array_key_exists($type, $validTypes)) {
      throw new WMFException(WMFException::IMPORT_CONTRIB, "Unknown refund type '{$type}'");
    }
    return $validTypes[$type];
  }

  private function cancelRecurringOnChargeback(string $contributionStatus, array $contributions): void {
    if ($contributionStatus === 'Chargeback' && !empty($contributions[0]['contribution_recur_id'])) {
      $message = [
        'txn_type' => 'subscr_cancel',
        'contribution_recur_id' => $contributions[0]['contribution_recur_id'],
        'cancel_reason' => 'Automatically cancelling because we received a chargeback',
        // We add this to satisfy a check in the common message normalization function.
        'payment_instrument_id' => $contributions[0]['payment_instrument_id'],
      ];
      QueueWrapper::push('recurring', $message);
    }
  }

}
