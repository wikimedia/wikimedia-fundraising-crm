<?php
namespace Civi\Api4\Action\PendingTransaction;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentData\ValidationAction;
use SmashPig\PaymentProviders\PaymentProviderFactory;

/**
 * Resolves a pending transaction by completing, canceling or discarding it.
 *
 * This is the action formerly known as 'rectifying' or 'slaying' an 'orphan'.
 *
 * @method $this setMessage(array $msg) Set WMF normalised values.
 * @method array getMessage() Get WMF normalised values.
 *
 * @package Civi\Api4
 */
class Resolve extends AbstractAction {

  protected static $_resolvableMethods = ['cc'];

  /**
   * Associative array of data from SmashPig's pending table.
   *
   * @var array
   */
  protected $message = [];

  public function _run(Result $result) {
    // Determine whether to resolve (i.e. not possible with iDEAL)
    if (!$this->isMessageResolvable()) {
      // discard if not possible
      \Civi::Log('wmf')->info(
        'Discarding unresolvable pending transaction and marking as failed: ' . json_encode($this->message)
      );
      $result[$this->message['order_id']]['status'] = FinalStatus::FAILED;
      return;
    }
    else {
      \Civi::Log('wmf')->info(
        'Resolving pending transaction: ' . json_encode($this->message)
      );
    }
    $provider = PaymentProviderFactory::getProviderForMethod(
      $this->message['payment_method']
    );

    // Get hosted payment status
    $statusResult = $provider->getHostedPaymentStatus(
      $this->message['gateway_session_id']
    );
    $gatewayTxnId = $statusResult->getGatewayTxnId();
    $riskScores = $statusResult->getRiskScores();
    // start building the API output
    $result[$this->message['order_id']] = [
      'gateway_txn_id' => $gatewayTxnId,
      'status' => $statusResult->getStatus(),
      'risk_scores' => $riskScores,
    ];

    // check if status is 600 (PENDING_POKE)
    // if not, just delete the message
    if (!$statusResult->requiresApproval()) {
      return;
    }

    // Cancel if there is already a contribution with this same ct_id.
    // Multiple donation attempts at the front-end in quick succession often
    // share a contribution_tracking_id. If the first is left in pending and
    // a subsequent one succeeded, we don't want to capture the first one as
    // the donor in all likelihood only meant to donate once.
    $existingContributionTrackingRecord = db_select('contribution_tracking', 'ct')
      ->fields('ct')
      ->condition('id', $this->message['contribution_tracking_id'], '=')
      ->execute()
      ->fetchAssoc();

    // contribution_id is set on the contribution_tracking table when we
    // consume the donations queue
    if (!empty($existingContributionTrackingRecord['contribution_id'])) {
      \Civi::Log('wmf')->info(
        'Front-end is potentially confusing donors - ct_id ' .
        $this->message['contribution_tracking_id'] . ' has a completed txn ' .
        'as well as a pending one. Cancelling the pending one.'
      );

      $response = $provider->cancelPayment($gatewayTxnId);
      $result[$this->message['order_id']]['status'] = $response->getStatus();
      return;
    }

    $validationAction = $this->getValidationAction($riskScores);
    switch ($validationAction) {
      case ValidationAction::PROCESS:
        // If score less than review threshold, approve the transaction.
        // Ingenico only needs the gateway_txn_id, but we send more info to
        // be generic like the SmashPig extension recurring charge logic.
        $approveResult = $provider->approvePayment([
          'amount' => $this->message['gross'],
          'currency' => $this->message['currency'],
          'gateway_txn_id' => $gatewayTxnId,
        ]);
        if ($approveResult->isSuccessful()) {
          $newStatus = FinalStatus::COMPLETE;
          $this->message['gateway_txn_id'] = $gatewayTxnId;
          QueueWrapper::push('donations', $this->message);
        }
        else {
          $newStatus = FinalStatus::FAILED;
        }
        // Update Civi API4 output
        $result[$this->message['order_id']]['status'] = $newStatus;
        break;

      case ValidationAction::REJECT:
        $cancelResult = $provider->cancelPayment($gatewayTxnId);
        $newStatus = $cancelResult->getStatus();
        $result[$this->message['order_id']]['status'] = $newStatus;
        break;

      case ValidationAction::REVIEW:
        // Just delete the pending message and leave the transaction at the
        // merchant console for review.
        return;

      default:
        throw new \UnexpectedValueException("Should not get action $validationAction");
    }
    // TODO Send an antifraud message

    // Drop a message off on the payments-init queue. This is usually done at the end
    // of a donation attempt at payments-wiki, but for orphan messages we often haven't
    // gotten that far at the front end. I think some reports assume payments-init
    // rows exist for all finished donation attempts.
    $paymentsInitMessage = $this->buildPaymentsInitMessage(
      $this->message,
      $validationAction,
      $newStatus,
      $gatewayTxnId
    );
    QueueWrapper::push('payments-init', $paymentsInitMessage);
  }

  protected function isMessageResolvable() {
    // payment method checks
    // should never be empty, that's just a sanity check
    if (empty($this->message['payment_method']) ||
        !in_array(
          $this->message['payment_method'],
          self::$_resolvableMethods
        )) {
      return FALSE;
    }
    // contribution_tracking_id sanity check, also should never be empty
    if (empty($this->message['contribution_tracking_id'])) {
      return FALSE;
    }
    // gateway_session_id sanity check
    // This is needed for the first two gateways we wrote rectifiers for
    // (Ingenico and PayPal) but won't be the ID we use for Adyen
    // Should never be empty for Ingenico or PayPal
    if (empty($this->message['gateway_session_id'])) {
      return FALSE;
    }
    return TRUE;
  }

  protected function getValidationAction($riskScores) : string {
    $config = Context::get()->getProviderConfiguration();
    $totalRiskScore = ($riskScores['cvv'] ?? 0) + ($riskScores['avs'] ?? 0);
    if ($totalRiskScore > $config->val('fraud-filters/reject-threshold')) {
      return ValidationAction::REJECT;
    }
    // If no CVV results for a credit card, discard message
    if (
      $this->message['payment_method'] === 'cc' &&
      !isset($riskScores['cvv'])
    ) {
      return ValidationAction::REVIEW;
    }
    if ($totalRiskScore > $config->val('fraud-filters/review-threshold')) {
      return ValidationAction::REVIEW;
    }
    return ValidationAction::PROCESS;
  }

  /**
   * Build a payments-init queue message using the pending message,
   * validation action and the final status.
   *
   * @param array $pendingMessage
   * @param string $validationAction
   * @param string $finalStatus
   * @param string $gatewayTxnId
   *
   * @return array
   */
  protected function buildPaymentsInitMessage(
    array $pendingMessage,
    string $validationAction,
    string $finalStatus,
    string $gatewayTxnId
  ) : array {
    $combinedParams = array_merge(
      $pendingMessage,
      [
        'validation_action' => $validationAction,
        'payments_final_status' => $finalStatus,
        'amount' => $pendingMessage['gross'],
        'date' => UtcDate::getUtcTimestamp(),
        'gateway_txn_id' => $gatewayTxnId
      ]
    );

    $paymentsInitMessage = array_filter($combinedParams, function ($key) {
      return in_array($key, [
        'validation_action',
        'payments_final_status',
        'payment_method',
        'payment_submethod',
        'country',
        'currency',
        'amount',
        'date',
        'gateway',
        'gateway_txn_id',
        'contribution_tracking_id',
        'order_id',
      ]);
    }, ARRAY_FILTER_USE_KEY);

    return $paymentsInitMessage;
  }

}
