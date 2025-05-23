<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ExchangeRate;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFQueueMessage\RefundMessage;
use Civi\WMFTransaction;
use Exception;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentProviders\IRecurringPaymentProfileProvider;
use SmashPig\PaymentProviders\PaymentProviderFactory;

class RefundQueueConsumer extends TransactionalQueueConsumer {

  const PAYPAL_GATEWAY = 'paypal';

  const PAYPAL_EXPRESS_CHECKOUT_GATEWAY = 'paypal_ec';

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  public function processMessage(array $message): void {
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
    $messageObject = new RefundMessage($message);
    $contributionStatus = $messageObject->getContributionStatus();
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

    // @todo move all these lookups to RefundMessage - add functions
    // ->getOriginalContributionID() and `->getOriginalContributionValue()`
    // similar to getRecurringPriorContributionValue on the RecurringQueue class.
    // The message class is responsible for interpreting the message - this
    // class should only be 'doing' with the interpreted message.
    $originalContribution = Contribution::get(FALSE)
      ->addWhere('contribution_extra.gateway', '=', $messageObject->getGateway())
      ->addWhere('contribution_extra.gateway_txn_id', '=', $parentTxn)
      ->execute()->first();
    // Fall back to searching by invoice ID, generally for Ingenico recurring
    if (!$originalContribution && !empty($message['invoice_id'])) {
      $originalContribution = Contribution::get(FALSE)
        ->addClause(
          'OR',
          ['invoice_id', '=', $message['invoice_id']],
          // For recurring payments, we sometimes append a | and a random number after the invoice ID
          ['invoice_id', 'LIKE', $message['invoice_id'] . '|%']
        )
        ->execute()->first();
    }

    if ($messageObject->isPaypal() && !$originalContribution) {
      /**
       * Refunds raised by Paypal do not indicate whether the initial
       * payment was taken using the paypal express checkout (paypal_ec) integration or
       * the legacy paypal integration (paypal). We try to work this out by checking for
       * the presence of specific values in messages sent over, but it appears this
       * isn't watertight as we've seen refunds failing due to incorrect mappings
       * on some occasions. To mitigate this we now fall back to the alternative
       * gateway if no match is found for the gateway supplied.
       */
      $originalContribution = Contribution::get(FALSE)
        ->addWhere('contribution_extra.gateway', 'IN', [static::PAYPAL_GATEWAY, static::PAYPAL_EXPRESS_CHECKOUT_GATEWAY])
        ->addWhere('contribution_extra.gateway_txn_id', '=', $parentTxn)
        ->execute()->first();
    }
    $context = ['log_id' => $logId];
    // not all messages have a reason
    $reason = $message['reason'] ?? '';
    if ($originalContribution) {
      // Perform the refund!
      try {
        \Civi::log('wmf')->info('refund {log_id}: Marking as refunded', $context);
        $this->markRefund(
          $originalContribution['id'],
          $messageObject,
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

      // add activity to record refund reason (e.g. why do we get chargeback),
      // currently only adyen has this field, need to ask gr4vy if they can also pass it back
      if (!empty($reason)) {
        // Log the refund reason to activity
        Activity::create(FALSE)
          ->addValue('date', $message['date'])
          ->addValue('activity_type_id:name', 'Refund')
          ->addValue('subject', ts('Refund Reason'))
          ->addValue('status_id:name', 'Completed')
          ->addValue('details', $contributionStatus . ' reason: ' . $message['reason'])
          ->addValue('source_contact_id', $originalContribution['contact_id'])
          ->addValue('target_contact_id', $originalContribution['contact_id'])
          ->addValue('source_record_id', $originalContribution['id'])
          ->execute();
      }
      // Some chargebacks for ACH and SEPA are retryable, don't cancel the recurrings
      if (!$this->isRetryableChargeback($reason)) {
        $this->cancelRecurringOnChargeback($contributionStatus, $originalContribution, $gateway);
      }
    }
    else {
      // For SEPA and iDEAL, we may not have a contribution in CiviCRM yet
      // because the payment is pending, and if failed due to RetryableChargeback, will rescue later
      if (!empty($message['payment_method']) && in_array($message['payment_method'], ['rtbt_ideal', 'sepadirectdebit'])) {
          // delete message from pending table
          PendingDatabase::get()->deleteMessage($message);
          \Civi::log('wmf')->info( 'Deleting pending contribution for ' . strtoupper($gateway) . " " . $parentTxn . ' ('. $message['payment_method'] . ') due to' . $reason, $context);
      } else if(!empty($message['backend_processor']) && $message['backend_processor'] == "trustly"){
        \Civi::log('wmf')->error('refund {log_id}: Skipping failed trustly transaction, as contribution not found in Civi!', $context);
      } else {
        \Civi::log('wmf')->error('refund {log_id}: Contribution not found for this transaction!', $context);
        throw new WMFException(WMFException::MISSING_PREDECESSOR, "Parent not found: " . strtoupper($gateway) . " " . $parentTxn);
      }
     }
  }

  /**
   * @param int $contribution_id
   * @param \Civi\WMFQueueMessage\RefundMessage $messageObject
   * @param string|null $refund_gateway_txn_id
   * @param string|null $refund_currency
   *   If provided this will be checked against the original contribution and an
   *   exception will be thrown on mismatch.
   * @param float|null $refund_amount
   *   If provided this will be checked against the original contribution and an
   *   exception will be thrown on mismatch.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \Civi\WMFException\WMFException
   * @todo - fix tests to process via the queue consumer, move this to the queue consumer.
   * Sets the civi records to reflect a contribution refund.
   *
   * The original contribution is set to status "Refunded", or "Chargeback" and a
   * negative financial transaction record is created. If the amount refunded
   * does not match a second contribution is added for the balance. The
   * parent_contribution_id custom field is set on the balance contribution to
   * connect it to the parent.
   *
   * Prior to the 4.6 CiviCRM upgrade refunds resulted in second contribution
   * with a negative amount. They were linked to the original through the
   * parent_contribution_id custom field. This was consistent with 4.2 behaviour
   * which was the then current version.
   *
   * 4.3 (included in the 4.6 upgrade) introduced recording multiple financial
   * transactions (payments) against one contribution. In order to adapt to this
   * the markRefund function now records second financial transactions against
   * the original contribution (using the contribution.create api). Discussion
   * about this change is at https://phabricator.wikimedia.org/T116317
   *
   * Some refunds do not have the same $ amount as the original transaction.
   * Prior to Oct 2014 these were seemingly always imported to CiviCRM. Since
   * that time the code was changed to throw an exception when the refund
   * exceeded the original amount, and not import it into CiviCRM. (this does
   * have visibility as it results in fail_mail).
   *
   * The code suggested an intention to record mismatched refunds with a the
   * difference in the custom fields settlement_usd. However, this returns no
   * rows. select * from wmf_contribution_extra WHERE settlement_usd IS NOT NULL
   * LIMIT. It would appear they have been recorded without any record of the
   * discrepancy, or there were none.
   *
   * That issue should be addressed (as a separate issue). The methodology for
   * recording the difference needs to be considered e.g T89437 - preferably in
   * conjunction with getting the appropriate method tested within the core
   * codebase.
   *
   * Note that really core CiviCRM should have a way of handling this and we
   * should work on getting that resolved and adopting it.
   *
   * An earlier iteration of this function reconstructed the value of the
   * original contribution when it had been zero'd or marked as 'RFD'. This
   * appears to be last used several years ago & this handling has been removed
   * now.
   */
  private function markRefund(
    int $contribution_id,
    RefundMessage $messageObject,
    ?string $refund_gateway_txn_id,
    ?string $refund_currency,
    ?float $refund_amount
  ): void {
    $amount_scammed = 0;

    try {
      $contribution = civicrm_api3('Contribution', 'getsingle', [
        'id' => $contribution_id,
        'return' => [
          'total_amount',
          'trxn_id',
          'contribution_source',
          'contact_id',
          'receive_date',
          'contribution_status_id',
        ],
      ]);
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(
        WMFException::INVALID_MESSAGE, "Could not load contribution: $contribution_id with error " . $e->getMessage()
      );
    }

    // Note that my usual reservation about using BAO functions from custom code is overridden by the
    // caching problems we are hitting in testing (plus the happy knowledge the tests care about this line of
    // code).
    if (\CRM_Contribute_BAO_Contribution::isContributionStatusNegative($contribution['contribution_status_id'])
    ) {
      throw new WMFException(WMFException::DUPLICATE_CONTRIBUTION, "Contribution is already refunded: $contribution_id");
    }
    // Deal with any discrepancies in the refunded amount.
    [$original_currency, $original_amount] = explode(" ", $contribution['contribution_source']);

    if ($refund_currency !== NULL) {
      if ($refund_currency != $original_currency) {
        if ($refund_currency === 'USD') {
          // change original amount and currency to match refund
          $original_amount = round((float) ExchangeRate::convert(FALSE)
            ->setFromCurrency($original_currency)
            ->setFromAmount($original_amount)
            ->setTimestamp(is_int($contribution['receive_date'])
              ? ('@' . $contribution['receive_date'])
              : $contribution['receive_date'])
            ->execute()
            ->first()['amount'], 2);
        }
        else {
          throw new WMFException(WMFException::INVALID_MESSAGE, "Refund was in a different currency.  Freaking out.");
        }
      }
    }
    else {
      $refund_currency = $original_currency;
    }

    try {
      civicrm_api3('Contribution', 'create', [
        'id' => $contribution_id,
        'debug' => 1,
        'contribution_status_id' => $messageObject->getContributionStatus(),
        'cancel_date' => $messageObject->getDate(),
        'refund_trxn_id' => $refund_gateway_txn_id,
      ]);
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(
        WMFException::IMPORT_CONTRIB,
        "Cannot mark original contribution as refunded:
                $contribution_id, " . $e->getMessage() . print_r($e->getExtraParams(), TRUE)
      );
    }

    if ($refund_amount !== NULL) {
      $amount_scammed = round($refund_amount, 2) - round($original_amount, 2);
      if ($amount_scammed != 0) {
        $transaction = WMFTransaction::from_unique_id($contribution['trxn_id']);
        if ($refund_gateway_txn_id) {
          $transaction->gateway_txn_id = $refund_gateway_txn_id;
        }
        $transaction->is_refund = TRUE;
        $refund_unique_id = $transaction->get_unique_id();

        try {
          $convertedTotalAmount = ExchangeRate::convert(FALSE)
            ->setFromCurrency($refund_currency)
            ->setFromAmount(-$amount_scammed)
            ->setTimestamp('@' . $messageObject->getTimestamp())
            ->execute()
            ->first()['amount'];

          Contribution::create(FALSE)->setValues([
            'total_amount' => round((float) $convertedTotalAmount, 2),
            'financial_type_id:name' => 'Refund',
            'contact_id' => $contribution['contact_id'],
            'contribution_source' => $refund_currency . " " . (-$amount_scammed),
            'trxn_id' => $refund_unique_id,
            'receive_date' => $messageObject->getDate(),
            'currency' => 'USD',
            'debug' => 1,
            'contribution_extra.parent_contribution_id' => $contribution_id,
            'contribution_extra.no_thank_you' => 1,
          ])->execute();
        }
        catch (\CRM_Core_Exception $e) {
          throw new WMFException(
            WMFException::IMPORT_CONTRIB,
            "Cannot create new contribution for the refund difference:
                $contribution_id, " . $e->getMessage() . print_r($e->getExtraParams(), TRUE)
          );
        }
      }
    }

    $alert_factor = \Civi::settings()->get('wmf_refund_alert_factor');
    if ($amount_scammed > $alert_factor * $original_amount) {
      \Civi::log('wmf')->alert("Refund amount mismatch for : $contribution_id, difference is {$amount_scammed}. See "
        . \CRM_Utils_System::url('civicrm/contact/view/contribution', [
          'reset' => 1,
          'id' => $contribution_id,
          'action' => 'view',
        ], TRUE),
        ['subject' => "Refund amount mismatch for : $contribution_id, difference is {$amount_scammed}."]
      );
    }
  }

  /**
   * For gateways where we can unilaterally decide to stop charging the donor, cancel any recurring donation
   * as soon as we get a chargeback.
   *
   * @param string $contributionStatus
   * @param array $firstContribution
   * @param string $gateway
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  private function cancelRecurringOnChargeback(string $contributionStatus, array $firstContribution, string $gateway): void {
    if (
      $contributionStatus === 'Chargeback' &&
      !empty($firstContribution['contribution_recur_id'])
    ) {
      if (RecurHelper::gatewayManagesOwnRecurringSchedule($gateway)) {
        $recurRecord = ContributionRecur::get(FALSE)
          ->addWhere('id', '=', $firstContribution['contribution_recur_id'])
          ->execute()
          ->first();
        /** @var IRecurringPaymentProfileProvider $provider */
        $provider = PaymentProviderFactory::getDefaultProvider();
        $provider->cancelSubscription(['subscr_id' => $recurRecord['trxn_id']]);
      }
      $message = [
        'gateway' => $gateway,
        'txn_type' => 'subscr_cancel',
        'contribution_recur_id' => $firstContribution['contribution_recur_id'],
        'cancel_reason' => 'Automatically cancelling because we received a chargeback',
        // We add this to satisfy a check in the common message normalization function.
        'payment_instrument_id' => $firstContribution['payment_instrument_id'],
      ];
      QueueWrapper::push('recurring', $message);
    }
  }

  /**
   * Some payment methods have retryable chargebacks, SEPA and ACH
   * SEPA retryable reasons: https://docs.adyen.com/online-payments/auto-rescue/sepa/
   *
   * @param string $reason
   */
  private function isRetryableChargeback(string $reason): bool {
    $reasons = [
      'AM04:InsufficientFunds',
      'MS03: No reason specified',
    ];

    return in_array($reason,$reasons);
  }

}
