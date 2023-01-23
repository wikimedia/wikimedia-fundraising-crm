<?php
namespace Civi\Api4\Action\PendingTransaction;

use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use \DateTime;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\PaymentsFraudDatabase;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentData\ValidationAction;
use SmashPig\PaymentProviders\IPaymentProvider;
use SmashPig\PaymentProviders\Responses\PaymentDetailResponse;
use SmashPig\PaymentProviders\PaymentProviderFactory;

/**
 * Resolves a pending transaction by completing, canceling or discarding it.
 *
 * This is the action formerly known as 'rectifying' or 'slaying' an 'orphan'.
 *
 * @method $this setMessage(array $msg) Set WMF normalised values.
 * @method array getMessage() Get WMF normalised values.
 * @method $this setAlreadyResolved(array $alreadyResolved) Set array of already-resolved transactions
 * @method array getAlreadyResolved() Get array of already-resolved transactions
 *
 * @package Civi\Api4
 */
class Resolve extends AbstractAction {

  protected static $_resolvableMethods = ['cc', 'google'];

  /**
   * Associative array of data from SmashPig's pending table.
   *
   * @var array
   */
  protected $message = [];

  /**
   * List of transaction details that have already been resolved this run.
   * We don't want to approve multiple transactions for the same email in
   * a single run, as they might not have been consumed into the database
   * yet and so not be caught by the hasDonationsInPastDay check.
   * @var array
   */
  protected $alreadyResolved = [];

  /**
   * The ValidationAction representing an initial determination of
   * fraudiness. This is calculated based on risk scores from fredge and
   * from the status lookup call to the processor, and action thresholds
   * set in the SmashPig processor-specific configuration.
   * @var string
   */
  protected $validationAction;

  /**
   * Some constants to represent what action to take on any payment method,
   * taking into account both the validationAction and other indicators of
   * duplicate status or donor trustworthiness.
   */
  private const CAPTURE = 'capture';
  private const CANCEL = 'cancel';
  private const LEAVE_AT_CONSOLE = 'leave at console';

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

    // Get the latest payment detail, currently only adyen and ingenico have this function
    $latestPaymentDetailResult = $provider->getLatestPaymentStatus($this->message);
    $gatewayTxnId = $latestPaymentDetailResult->getGatewayTxnId();
    $riskScores = $latestPaymentDetailResult->getRiskScores();
    // start building the Civi API4 output
    $result[$this->message['order_id']] = [
      'email' => $this->message['email'] ?? NULL,
      'gateway_txn_id' => $gatewayTxnId,
      'status' => $latestPaymentDetailResult->getStatus(),
      'risk_scores' => $riskScores,
    ];

    // check if status is 600 (PENDING_POKE)
    // if not, just delete the message
    if (!$latestPaymentDetailResult->requiresApproval()) {
      return;
    }

    if (
      $this->trackingRecordHasContributionId() ||
      $this->approvedDonationForSameDonorInThisRun()
    ) {
      $whatToDo = self::CANCEL;
    }
    else {
      $whatToDo = $this->decideWhatToDoBasedOnRiskScores($riskScores);
    }

    switch ($whatToDo) {
      case self::CANCEL:
        $cancelResult = $provider->cancelPayment($gatewayTxnId);
        $newStatus = $cancelResult->getStatus();
        break;

      case self::CAPTURE:
        $newStatus = $this->approvePaymentAndReturnStatus($provider, $latestPaymentDetailResult);
        break;

      case self::LEAVE_AT_CONSOLE:
        // Just delete the pending message and leave the transaction at the
        // merchant console for review. Return early so as not to send a
        // payments-init message since nothing new has happened.
        $result[$this->message['order_id']]['status'] = FinalStatus::FAILED;
        return;
    }

    // Update Civi API4 output
    $result[$this->message['order_id']]['status'] = $newStatus;

    $this->sendInitMessageIfNeeded($newStatus, $gatewayTxnId);
  }

  protected function isMessageResolvable() {
    // payment method checks
    // should never be empty, and should already filter out non resolvable methods, that's just a sanity check
    if (empty($this->message['payment_method']) ||
        !in_array(
          $this->message['payment_method'],
          self::$_resolvableMethods
        )) {
      return FALSE;
    }
    // gateway_txn_id check, Adyen needs this but it can be set to false if its from a redirect
    if ((empty($this->message['gateway_txn_id']) ||
        $this->message['gateway_txn_id'] == "false") &&
        ($this->message['gateway'] == 'adyen')
      ) {
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
    if (empty($this->message['gateway_session_id']) && in_array($this->message['gateway'], ['ingenico', 'paypal'])) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Check if there is already a contribution with this same ct_id.
   * Multiple donation attempts at the front-end in quick succession often
   * share a contribution_tracking_id. If the first is left in pending and
   * a subsequent one succeeded, we don't want to capture the first one as
   * the donor in all likelihood only meant to donate once.
   *
   * @return bool
   */
  protected function trackingRecordHasContributionId(): bool {
    $existingContributionTrackingRecord = db_select('contribution_tracking', 'ct')
      ->fields('ct')
      ->condition('id', $this->message['contribution_tracking_id'], '=')
      ->execute()
      ->fetchAssoc();
    $hasId = !empty($existingContributionTrackingRecord['contribution_id']);
    if ($hasId) {
      // contribution_id is set on the contribution_tracking table when we
      // consume the donations queue
      \Civi::Log('wmf')->info(
        'Front-end is potentially confusing donors - ct_id ' .
        $this->message['contribution_tracking_id'] . ' has a completed txn ' .
        'as well as a pending one. Cancelling the pending one.'
      );
    }
    return $hasId;
  }

  /**
   * @return bool true if we have just resolved a donation for the
   * same email address.
   */
  protected function approvedDonationForSameDonorInThisRun(): bool {
    foreach($this->alreadyResolved as $orderId => $alreadyResolved) {
      if (empty($alreadyResolved['email']) || empty($this->message['email'])) {
        continue;
      }
      if (
        $alreadyResolved['email'] === $this->message['email'] &&
        $alreadyResolved['status'] === FinalStatus::COMPLETE
      ) {
        return true;
      }
    }
    return false;
  }

  /**
   * Decides one of three courses of action based on risk scores and
   * whether the message matches a donor in the database with a clean
   * record. Also takes into consideration whether we have recorded a
   * donation from the donor in the past day.
   *
   * @param array $riskScores
   * @return string
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function decideWhatToDoBasedOnRiskScores(array $riskScores): string {
    $this->validationAction = $this->getValidationAction($riskScores);
    switch ($this->validationAction) {
      case ValidationAction::PROCESS:
        // If score less than review threshold and no donation in past day, approve the transaction.
        if ($this->hasDonationsInPastDay()) {
          return self::CANCEL;
        } else {
          return self::CAPTURE;
        }

      case ValidationAction::REJECT:
        if ($this->matchesUnrefundedDonor()) {
          return self::CAPTURE;
        } else {
          return self::CANCEL;
        }

      case ValidationAction::REVIEW:
        if ($this->matchesUnrefundedDonor()) {
          return self::CAPTURE;
        } else {
          // Just delete the pending message and leave the transaction at the
          // merchant console for review.
          return self::LEAVE_AT_CONSOLE;
        }

      default:
        throw new \UnexpectedValueException("Should not get action $this->validationAction");
    }
  }

  /**
   * Determine what action to take based on risk scores from the hosted checkout
   * status call combined with risk scores from the payments_fraud table.
   *
   * @param array $riskScoresFromStatus 'cvv' and 'avs' keys are examined
   * @return string one of the ValidationAction constants
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function getValidationAction(array $riskScoresFromStatus): string {
    $totalRiskScore = 0;
    $fredgeHadCvvScore = false;
    $fredgeHadAvsScore = false;
    $statusHasNewFraudScores = false;

    $paymentsFraudRowWithBreakdown = PaymentsFraudDatabase::get()->fetchMessageByGatewayOrderId(
      $this->message['gateway'], $this->message['order_id'], TRUE
    );
    $scoreBreakdownFromFredge = $paymentsFraudRowWithBreakdown['score_breakdown'] ?? [];
    // $scoreBreakdownFromFredge looks like [
    //    'getCVVResult' => 80,
    //    'minfraud_filter' => 0.25,
    // ]
    foreach($scoreBreakdownFromFredge as $filterName => $score) {
      if ($filterName === 'getCVVResult') {
        $fredgeHadCvvScore = true;
      } elseif ($filterName === 'getAVSResult') {
        $fredgeHadAvsScore = true;
      }
      $totalRiskScore += $score;
    }
    // At this point $totalRiskScore should be equal to $paymentsFraudRowWithBreakdown['risk_score']
    // but we can spare the cycles to do the math again ourselves.

    // If we have new info about CVV and AVS risk scores, add that to
    // the message and note that we have new scores to send to the queue.
    // We can re-use the array retrieved from PaymentsFraudDatabase as a
    // basis for the antifraud message, as it is in the exact same format.
    $antifraudMessage = $paymentsFraudRowWithBreakdown;

    if (isset($riskScoresFromStatus['cvv'])) {
      if ($fredgeHadCvvScore) {
        // Log a warning if we already had a different score recorded. This
        // probably indicates that we are assigning the same raw result two
        // different scores on front-end and back end settings.
        if ($scoreBreakdownFromFredge['getCVVResult'] != $riskScoresFromStatus['cvv']) {
          \Civi::Log('wmf')->warning(
            "CVV score mismatch for order_id {$this->message['order_id']}. " .
            "Front end score {$scoreBreakdownFromFredge['getCVVResult']}, " .
            "pending resolver score {$riskScoresFromStatus['cvv']}. " .
            "Please check that cvv_map settings are consistent."
          );
        }
      } else {
        $antifraudMessage['score_breakdown']['getCVVResult'] = $riskScoresFromStatus['cvv'];
        $totalRiskScore += $riskScoresFromStatus['cvv'];
        $statusHasNewFraudScores = TRUE;
      }
    }
    if (isset($riskScoresFromStatus['avs'])) {
      if ($fredgeHadAvsScore) {
        // Log a warning if we already had a different score recorded. This
        // probably indicates that we are assigning the same raw result two
        // different scores on front-end and back end settings.
        if ($scoreBreakdownFromFredge['getAVSResult'] != $riskScoresFromStatus['avs']) {
          \Civi::Log('wmf')->warning(
            "AVS score mismatch for order_id {$this->message['order_id']}. " .
            "Front end score {$scoreBreakdownFromFredge['getAVSResult']}, " .
            "pending resolver score {$riskScoresFromStatus['avs']}. " .
            "Please check that avs_map settings are consistent."
          );
        }
      } else {
        $antifraudMessage['score_breakdown']['getAVSResult'] = $riskScoresFromStatus['avs'];
        $totalRiskScore += $riskScoresFromStatus['avs'];
        $statusHasNewFraudScores = TRUE;
      }
    }
    $antifraudMessage['risk_score'] = $totalRiskScore;

    if ($statusHasNewFraudScores) {
      QueueWrapper::push( 'payments-antifraud', $antifraudMessage );
    }

    $config = Context::get()->getProviderConfiguration();
    if ($totalRiskScore > $config->val('fraud-filters/reject-threshold')) {
      return ValidationAction::REJECT;
    }
    if ($totalRiskScore > $config->val('fraud-filters/review-threshold')) {
      return ValidationAction::REVIEW;
    }

    // If no CVV results for a credit card, leave in review
    if (
      $this->message['payment_method'] === 'cc' &&
      !$fredgeHadCvvScore &&
      !array_key_exists('cvv', $riskScoresFromStatus)
    ) {
      return ValidationAction::REVIEW;
    }

    return ValidationAction::PROCESS;
  }

  /**
   * If there is new information to send to the payments-init queue, build a message
   * and send it.
   *
   * @param string $newStatus
   * @param string $gatewayTxnId
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function sendInitMessageIfNeeded(string $newStatus, string $gatewayTxnId) {
    // If we haven't set a validationAction, there's no new information to send to the
    // payments-init queue.
    if (!empty($this->validationAction)) {
      // Drop a message off on the payments-init queue. This is usually done at the end
      // of a donation attempt at payments-wiki, but for orphan messages we often haven't
      // gotten that far at the front end. I think some reports assume payments-init
      // rows exist for all finished donation attempts.
      $paymentsInitMessage = $this->buildPaymentsInitMessage(
        $this->message,
        $this->validationAction,
        $newStatus,
        $gatewayTxnId
      );
      QueueWrapper::push( 'payments-init', $paymentsInitMessage );
    }
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
    $filteredPendingMessage = array_filter($pendingMessage, function ($key) {
      return in_array($key, [
        'payment_method',
        'payment_submethod',
        'country',
        'currency',
        'gateway',
        'contribution_tracking_id',
        'order_id',
      ]);
    }, ARRAY_FILTER_USE_KEY);

    $paymentsInitMessage = array_merge(
      $filteredPendingMessage,
      [
        'validation_action' => $validationAction,
        'payments_final_status' => $finalStatus,
        'amount' => $pendingMessage['gross'],
        'date' => UtcDate::getUtcTimestamp(),
        'gateway_txn_id' => $gatewayTxnId,
        'server' => gethostname(), // FIXME: payments-init qc should be able to get this from source_host
      ]
    );

    return $paymentsInitMessage;
  }

  /**
   * Looks for a matching donor with at least one 'good' (Completed)
   * contribution and no contributions in other statuses, and who
   * has not made a contribution in the past 24 hours (so we avoid
   * pushing through duplicate contributions).
   *
   * @return bool True if donor found matching the conditions, false otherwise
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function matchesUnrefundedDonor(): bool {
    if (
      empty($this->message['email']) ||
      empty($this->message['first_name']) ||
      empty($this->message['last_name'])
    ) {
      // Don't try to match if we have incomplete information
      return FALSE;
    }
    $statusCountsByDonor = $this->getDonationStatistics(TRUE);
    if ($statusCountsByDonor->count() === 0) {
      // No matching donor found
      return FALSE;
    }
    foreach ($statusCountsByDonor as $counts) {
      if (
        $counts['completedDonations'] > 0 &&
        $counts['otherDonations'] === 0 &&
        (new DateTime($counts['latestCompleted'])) < (new DateTime("-1 day"))
      ) {
        return TRUE;
      }
    }
    // All matched donors either had no completed donations, had some
    // donations in another status, or had a donation in the past day.
    return FALSE;
  }

  protected function hasDonationsInPastDay(): bool {
    if (
      empty($this->message['email']) ||
      empty($this->message['first_name']) ||
      empty($this->message['last_name'])
    ) {
      // Don't try to match if we have incomplete information
      return FALSE;
    }
    $statusCountsByDonor = $this->getDonationStatistics(FALSE);
    foreach($statusCountsByDonor as $counts) {
      if ((new DateTime($counts['latestCompleted'])) > (new DateTime("-1 day"))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get statistics on donations for all donor records matching the name & email.
   *
   * @param bool $includeNonCompleteDonation pass FALSE to skip a join to contribution
   * @return Result
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getDonationStatistics(bool $includeNonCompleteDonation): Result {
    $getApiCall = Contact::get(FALSE)
      ->addSelect(
        'id',
        'COUNT(DISTINCT completedContrib.id) AS completedDonations',
        'MAX(completedContrib.receive_date) AS latestCompleted',
      )
      ->addJoin('Email AS email', 'LEFT', ['email.is_primary', '=', 1])
      ->addJoin(
        'Contribution AS completedContrib', 'LEFT',
        ['completedContrib.contribution_status_id', '=', 1]
      )
      ->setGroupBy(['id',])
      ->addWhere('email.email', '=', $this->message['email'])
      ->addWhere('first_name', '=', $this->message['first_name'])
      ->addWhere('last_name', '=', $this->message['last_name'])
      ->addWhere('is_deleted', '=', 0)
      ->setLimit(10);
    if ($includeNonCompleteDonation) {
      $getApiCall
        ->addSelect('COUNT(DISTINCT otherContrib.id) AS otherDonations')
        ->addJoin(
          'Contribution AS otherContrib', 'LEFT',
          ['otherContrib.contribution_status_id', '<>', 1]
        );
    }
    return $getApiCall->execute();
  }

  protected function approvePaymentAndReturnStatus(
    IPaymentProvider $provider, PaymentDetailResponse $statusResult
  ): string {
    $gatewayTxnId = $statusResult->getGatewayTxnId();
    // Ingenico only needs the gateway_txn_id, but we send more info to
    // be generic like the SmashPig extension recurring charge logic.
    $approveResult = $provider->approvePayment([
      'amount' => $this->message['gross'],
      'currency' => $this->message['currency'],
      'gateway_txn_id' => $gatewayTxnId,
    ]);
    if ($approveResult->isSuccessful()) {
      $newStatus = FinalStatus::COMPLETE;
      $donationsMessage = $this->message;
      $donationsMessage['gateway_txn_id'] = $gatewayTxnId;
      unset($donationsMessage['gateway_session_id']);
      $token = $statusResult->getRecurringPaymentToken();
      if ($token) {
        $donationsMessage['recurring_payment_token'] = $token;
      }
      QueueWrapper::push('donations', $donationsMessage);
    }
    else {
      $newStatus = FinalStatus::FAILED;
    }
    return $newStatus;
  }
}
