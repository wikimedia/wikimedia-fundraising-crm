<?php

namespace Civi\Api4\Action\PendingTransaction;

use Civi\Api4\Contact;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Name;
use Civi\Api4\PendingTransaction;
use \DateTime;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\PaymentsFraudDatabase;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentData\ValidationAction;
use SmashPig\PaymentProviders\ICancelablePaymentProvider;
use SmashPig\PaymentProviders\IPaymentProvider;
use SmashPig\PaymentProviders\IRecurringPaymentProfileProvider;
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
   *
   * @var array
   */
  protected $alreadyResolved = [];

  /**
   * The ValidationAction representing an initial determination of
   * fraudiness. This is calculated based on risk scores from fredge and
   * from the status lookup call to the processor, and action thresholds
   * set in the SmashPig processor-specific configuration.
   *
   * @var ?string
   */
  protected $validationAction = NULL;

  /**
   * Array of name fields that may have been parsed out of a full_name
   * field, cached here to avoid an extra API call to Name::parse
   *
   * @var ?array
   */
  protected $firstAndLastName = NULL;

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

    // Get the latest status for the payment, either via the provider API
    // or when not available (Adyen) just from the pending message.
    $latestPaymentDetailResult = $provider->getLatestPaymentStatus($this->message);
    $this->addNewInfoFromPaymentDetailToMessage($latestPaymentDetailResult);

    if (!$latestPaymentDetailResult->isSuccessful()) {
      \Civi::Log('wmf')->info('Status lookup call failed');
      \Civi::Log('wmf')->debug(json_encode($latestPaymentDetailResult->getRawResponse()));
      $result[$this->message['order_id']]['status'] = FinalStatus::FAILED;
      return;
    }

    $riskScores = $latestPaymentDetailResult->getRiskScores();
    // start building the Civi API4 output
    $result[$this->message['order_id']] = [
      'email' => $this->message['email'] ?? NULL,
      'gateway_txn_id' => $latestPaymentDetailResult->getGatewayTxnId(),
      'status' => $latestPaymentDetailResult->getStatus(),
      'risk_scores' => $riskScores,
    ];

    // Check if payment is awaiting approval.
    // If not, just delete the message
    if (!$latestPaymentDetailResult->requiresApproval()) {
      return;
    }

    if (
      $this->contributionTrackingRecordHasContributionId() ||
      $this->approvedDonationForSameDonorInThisRun()
    ) {
      $whatToDo = self::CANCEL;
    }
    else {
      $whatToDo = $this->decideWhatToDoBasedOnRiskScores($riskScores);
    }

    switch ($whatToDo) {
      case self::CAPTURE:
        $newStatus = $this->approvePaymentAndReturnStatus($provider, $latestPaymentDetailResult);
        break;

      case self::CANCEL:
        if ($provider instanceof ICancelablePaymentProvider) {
          $cancelResult = $provider->cancelPayment($latestPaymentDetailResult->getGatewayTxnId());
          $newStatus = $cancelResult->getStatus();
          break;
        }
      // If the provider doesn't support cancelling a payment in
      // pending-poke status, just fall through to the next case
      // and leave the payment at the console.

      case self::LEAVE_AT_CONSOLE:
        // Just delete the pending message and leave the transaction at the
        // merchant console for review. Return early so as not to send a
        // payments-init message since nothing new has happened.
        $result[$this->message['order_id']]['status'] = FinalStatus::FAILED;
        return;
    }

    // Update Civi API4 output
    $result[$this->message['order_id']]['status'] = $newStatus;

    $this->sendInitMessageIfNeeded($newStatus);
  }

  protected function isMessageResolvable() {
    // payment method checks
    // should never be empty, and should already filter out non resolvable methods, that's just a sanity check
    if (empty($this->message['payment_method']) ||
      !in_array(
        $this->message['payment_method'],
        PendingTransaction::getResolvableMethods()
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
   * Fill in any missing donor information that has come in on the status lookup call.
   *
   * @param PaymentDetailResponse $latestPaymentDetailResult
   */
  protected function addNewInfoFromPaymentDetailToMessage(PaymentDetailResponse $latestPaymentDetailResult): void {
    $donorDetails = $latestPaymentDetailResult->getDonorDetails();
    if ($donorDetails !== NULL) {
      $infoToAddToMessage = [
        'first_name' => $donorDetails->getFirstName(),
        'last_name' => $donorDetails->getLastName(),
        'full_name' => $donorDetails->getFullName(),
        'email' => $donorDetails->getEmail(),
      ];
    }
    else {
      $infoToAddToMessage = [];
    }
    $infoToAddToMessage['processor_contact_id'] = $latestPaymentDetailResult->getProcessorContactID();
    $infoToAddToMessage['gateway_txn_id'] = $latestPaymentDetailResult->getGatewayTxnId();

    // One or more of these is probably null - use array_filter to remove them
    $infoToAddToMessage = array_filter($infoToAddToMessage);

    // Don't overwrite fields where the message had info already, but DO overwrite blank values
    foreach ($infoToAddToMessage as $field => $value) {
      if (empty($this->message[$field])) {
        $this->message[$field] = $value;
      }
    }
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
  protected function contributionTrackingRecordHasContributionId(): bool {
    $existingContributionTrackingRecord = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $this->message['contribution_tracking_id'])
      ->execute()->first();
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
    foreach ($this->alreadyResolved as $orderId => $alreadyResolved) {
      if (empty($alreadyResolved['email']) || empty($this->message['email'])) {
        continue;
      }
      if (
        $alreadyResolved['email'] === $this->message['email'] &&
        $alreadyResolved['status'] === FinalStatus::COMPLETE
      ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Decides one of three courses of action based on risk scores and
   * whether the message matches a donor in the database with a clean
   * record. Also takes into consideration whether we have recorded a
   * donation from the donor in the past day.
   *
   * @param array $riskScores
   *
   * @return string
   * @throws \CRM_Core_Exception
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
        }
        else {
          return self::CAPTURE;
        }

      case ValidationAction::REJECT:
        if ($this->matchesUnrefundedDonor()) {
          return self::CAPTURE;
        }
        else {
          return self::CANCEL;
        }

      case ValidationAction::REVIEW:
        if ($this->matchesUnrefundedDonor()) {
          return self::CAPTURE;
        }
        else {
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
   *
   * @return string one of the ValidationAction constants
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function getValidationAction(array $riskScoresFromStatus): string {
    $totalRiskScore = 0;
    $fredgeHadCvvScore = FALSE;
    $fredgeHadAvsScore = FALSE;
    $statusHasNewFraudScores = FALSE;

    $paymentsFraudRowWithBreakdown = PaymentsFraudDatabase::get()->fetchMessageByGatewayOrderId(
      $this->message['gateway'], $this->message['order_id'], TRUE
    );
    $scoreBreakdownFromFredge = $paymentsFraudRowWithBreakdown['score_breakdown'] ?? [];
    // $scoreBreakdownFromFredge looks like [
    //    'getCVVResult' => 80,
    //    'minfraud_filter' => 0.25,
    // ]
    foreach ($scoreBreakdownFromFredge as $filterName => $score) {
      if ($filterName === 'getCVVResult') {
        $fredgeHadCvvScore = TRUE;
      }
      elseif ($filterName === 'getAVSResult') {
        $fredgeHadAvsScore = TRUE;
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
      }
      else {
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
      }
      else {
        $antifraudMessage['score_breakdown']['getAVSResult'] = $riskScoresFromStatus['avs'];
        $totalRiskScore += $riskScoresFromStatus['avs'];
        $statusHasNewFraudScores = TRUE;
      }
    }
    $antifraudMessage['risk_score'] = $totalRiskScore;

    if ($statusHasNewFraudScores) {
      QueueWrapper::push('payments-antifraud', $antifraudMessage);
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
   *
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function sendInitMessageIfNeeded(string $newStatus) {
    // If we haven't set a validationAction, there's no new information to send to the
    // payments-init queue.
    if ($this->validationAction !== NULL) {
      // Drop a message off on the payments-init queue. This is usually done at the end
      // of a donation attempt at payments-wiki, but for orphan messages we often haven't
      // gotten that far at the front end. I think some reports assume payments-init
      // rows exist for all finished donation attempts.
      $paymentsInitMessage = $this->buildPaymentsInitMessage(
        $this->message,
        $this->validationAction,
        $newStatus
      );
      QueueWrapper::push('payments-init', $paymentsInitMessage);
    }
  }

  /**
   * Build a payments-init queue message using the pending message,
   * validation action and the final status.
   *
   * @param array $pendingMessage
   * @param string $validationAction
   * @param string $finalStatus
   *
   * @return array
   */
  protected function buildPaymentsInitMessage(
    array $pendingMessage,
    string $validationAction,
    string $finalStatus
  ): array {
    $filteredPendingMessage = array_filter($pendingMessage, function($key) {
      return in_array($key, [
        'payment_method',
        'payment_submethod',
        'country',
        'currency',
        'gateway',
        'contribution_tracking_id',
        'order_id',
        'gateway_txn_id',
      ]);
    }, ARRAY_FILTER_USE_KEY);

    $paymentsInitMessage = array_merge(
      $filteredPendingMessage,
      [
        'validation_action' => $validationAction,
        'payments_final_status' => $finalStatus,
        'amount' => $pendingMessage['gross'],
        'date' => UtcDate::getUtcTimestamp(),
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
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function matchesUnrefundedDonor(): bool {
    if (!$this->messageHasMatchableFields()) {
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
    if (!$this->messageHasMatchableFields()) {
      // Don't try to match if we have incomplete information
      return FALSE;
    }
    $statusCountsByDonor = $this->getDonationStatistics(FALSE);
    foreach ($statusCountsByDonor as $counts) {
      if ((new DateTime($counts['latestCompleted'])) > (new DateTime("-1 day"))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Return true if we have enough info to look for a matching donor
   *
   * @return bool
   */
  protected function messageHasMatchableFields(): bool {
    $hasEmail = !empty($this->message['email']);
    $nameFields = $this->getFirstAndLastName();
    $hasFirstAndLastName = !empty($nameFields['first_name']) && !empty($nameFields['last_name']);
    return $hasEmail && $hasFirstAndLastName;
  }

  /**
   * Get statistics on donations for all donor records matching the name & email.
   *
   * @param bool $includeNonCompleteDonation pass FALSE to skip a join to contribution
   *
   * @return Result
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getDonationStatistics(bool $includeNonCompleteDonation): Result {
    // We have checked using messageHasMatchableFields to
    // make sure this returns both first and last name.
    $nameFields = $this->getFirstAndLastName();
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
      ->addWhere('first_name', '=', $nameFields['first_name'])
      ->addWhere('last_name', '=', $nameFields['last_name'])
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

  /**
   * Some messages will just have a 'full_name' field instead of
   * the separate first_name and last_name that we need to look
   * donors up in the contact table. Use a name parsing library
   * to get the first and last names into their own fields.
   *
   * We parse out the first and last names here to do our donor
   * lookup, but we don't add the parsed names to the message,
   * because the full_name parsing in the message import logic
   * handles many more components than first and last name.
   */
  protected function getFirstAndLastName(): array {
    if ($this->firstAndLastName === NULL) {
      $hasFullName = !empty($this->message['full_name']);
      $missingAtLeastOneSplitName = empty($this->message['first_name']) || empty($this->message['last_name']);
      if ($hasFullName && $missingAtLeastOneSplitName) {
        $parsed = Name::parse(FALSE)
          ->setNames([$this->message['full_name']])
          ->execute()->first();
        $sourceData = $parsed;
      }
      else {
        $sourceData = $this->message;
      }
      $this->firstAndLastName = [];
      // Either the original message or the parsed field could still be missing
      // first_name or last_name. Just return as much as we have.
      foreach (['first_name', 'last_name'] as $fieldName) {
        if (!empty($sourceData[$fieldName])) {
          $this->firstAndLastName[$fieldName] = $sourceData[$fieldName];
        }
      }
    }
    return $this->firstAndLastName;
  }

  protected function approvePaymentAndReturnStatus(
    IPaymentProvider $provider, PaymentDetailResponse $statusResult
  ): string {
    if (
      !empty($this->message['recurring']) &&
      $provider instanceof IRecurringPaymentProfileProvider
    ) {
      // This flow starts a recurring payment managed at the processor-side. It
      // does not create a payment now so does not send a donations queue message.
      // We could send a recurring queue message here to indicate the start of the
      // recurring payment, but the code that we are replacing does not. Instead
      // we let the IPN listener send the queue messages when it receives
      // notifications of recurring payment start and of the successful first
      // charge from the payment processor.
      return $this->startRecurringPaymentAndReturnStatus($provider);
    }
    else {
      // This flow approves a one-time payment and may also save a recurring
      // payment token for use in future recurring payments managed by the merchant
      return $this->approveOneTimePaymentAndReturnStatus($provider, $statusResult);
    }
  }

  protected function startRecurringPaymentAndReturnStatus(
    IRecurringPaymentProfileProvider $provider
  ): string {
    $profileResult = $provider->createRecurringPaymentsProfile([
      'gateway_session_id' => $this->message['gateway_session_id'],
      'description' => \Civi::settings()->get('wmf_resolved_charge_descriptor'),
      'order_id' => $this->message['order_id'],
      'amount' => $this->message['gross'],
      'currency' => $this->message['currency'],
      'email' => $this->message['email'],
      'date' => $this->message['date'],
    ]);
    return $profileResult->getStatus();
  }

  protected function approveOneTimePaymentAndReturnStatus(
    IPaymentProvider $provider, PaymentDetailResponse $statusResult
  ): string {
    // Ingenico only needs the gateway_txn_id, but we send more info to
    // be generic like the SmashPig extension recurring charge logic.
    $approveResult = $provider->approvePayment([
      'amount' => $this->message['gross'],
      'currency' => $this->message['currency'],
      'order_id' => $this->message['order_id'],
      'gateway_session_id' => $this->message['gateway_session_id'] ?? NULL,
      'processor_contact_id' => $this->message['processor_contact_id'] ?? NULL,
      'gateway_txn_id' => $this->message['gateway_txn_id'] ?? NULL,
    ]);
    if ($approveResult->isSuccessful()) {
      $newStatus = FinalStatus::COMPLETE;
      // Some processors (PayPal) don't assign a transaction ID until
      // after the approval
      if (empty($this->message['gateway_txn_id'])) {
        $this->message['gateway_txn_id'] = $approveResult->getGatewayTxnId();
      }
      $this->sendDonationsQueueMessage($statusResult);
    }
    else {
      $newStatus = FinalStatus::FAILED;
    }
    return $newStatus;
  }

  /**
   * @param PaymentDetailResponse $statusResult
   */
  protected function sendDonationsQueueMessage(PaymentDetailResponse $statusResult): void {
    $donationsMessage = $this->message;
    unset($donationsMessage['gateway_session_id']);
    $token = $statusResult->getRecurringPaymentToken();
    if ($token) {
      $donationsMessage['recurring_payment_token'] = $token;
    }
    QueueWrapper::push('donations', $donationsMessage);
  }

}
