<?php

use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Helper\SmashPigPaymentError;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentData\ErrorCode;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\FailureEmail;
use SmashPig\PaymentProviders\Responses\CreatePaymentWithProcessorRetryResponse;
use SmashPig\PaymentProviders\Responses\PaymentProviderExtendedResponse;
use SmashPig\PaymentProviders\Responses\PaymentProviderResponse;

class CRM_Core_Payment_SmashPigRecurringProcessor {

  protected $useQueue;

  protected $retryDelayDays;

  protected $maxFailures;

  protected $catchUpDays;

  protected $batchSize;

  protected $descriptor;

  protected $timeLimitInSeconds;

  protected $smashPigProcessors;

  const MAX_MERCHANT_REFERENCE_RETRIES = 3;
  /**
   * @param bool $useQueue Send messages to donations queue instead of directly
   *  inserting new contributions
   * @param int $retryDelayDays Days to wait before retrying failed payment
   * @param int $maxFailures Maximum failures before canceling subscription
   * @param int $catchUpDays Number of days in the past to look for payments
   * @param int $batchSize Maximum number of payments to process in a batch
   * @param string $descriptor Shown on donors' card statements
   * @param int $timeLimitInSeconds Maximum number of seconds to spend processing
   * @throws CRM_Core_Exception
   */
  public function __construct(
    $useQueue,
    $retryDelayDays,
    $maxFailures,
    $catchUpDays,
    $batchSize,
    $descriptor,
    $timeLimitInSeconds = 0
  ) {
    $this->useQueue = $useQueue;
    $this->retryDelayDays = $retryDelayDays;
    $this->maxFailures = $maxFailures;
    $this->catchUpDays = $catchUpDays;
    $this->batchSize = $batchSize;
    $this->descriptor = $descriptor;
    $processorsApiResult = civicrm_api3('PaymentProcessor', 'get', ['class_name' => 'Payment_SmashPig']);
    $this->smashPigProcessors = $processorsApiResult['values'];
    $this->timeLimitInSeconds = $timeLimitInSeconds;
  }

  /**
   * Charge a batch of recurring contributions (or just one, if
   * contributionRecurId is specified)
   *
   * @param ?int $contributionRecurId
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function run($contributionRecurId = NULL) {
    $startTime = time();
    $recurringPayments = $this->getPaymentsToCharge($contributionRecurId);
    $result = [
      'success' => ['ids' => []],
      'failed' => ['ids' => []]
    ];

    $errorCount = [
      'Failed{error="declined"}' => 0,
      'Failed{error="declined_do_not_retry"}' => 0,
      'Failed{error="missing_transaction_id"}' => 0,
      'Failed{error="no_response"}' => 0,
      'Failed{error="server_timeout"}' => 0,
      'Failed{error="transaction_not_found"}' => 0,
      'Failed{error="other"}' => 0
    ];

    foreach ($recurringPayments as $recurringPayment) {
      if ($this->timeLimitInSeconds > 0 && time() - $startTime > $this->timeLimitInSeconds) {
        Civi::log('wmf')->info('Reached time limit of ' . $this->timeLimitInSeconds . ' seconds');
        break;
      }
      try {
        $previousContribution = self::getPreviousContribution($recurringPayment);
        // run a small query to double-check the recurring status
        $recurStatus = self::getRecurStatus($recurringPayment['id']);
        if ($recurStatus === 'Cancelled') {
          Civi::log('wmf')
            ->info('No need to charge this one since recurring row '. $recurringPayment['id'] . ' is cancelled');
          continue;
        }
        if ($recurStatus === 'Processing') {
          throw new UnexpectedValueException(
            'Recurring row '. $recurringPayment['id'] . ' has been set to Processing! Is there another recurring ' .
            'charge job running at the same time? Bailing out to avoid double charges.'
          );
        }

        // Catch for double recurring payments in one month (23 days of one another)
        $days = date_diff(
          new DateTime($recurringPayment['next_sched_contribution_date']),
          new DateTime($previousContribution['receive_date'])
        )->days;

        // Ignore check when a specific contribution recur id is given
        if ($days < 24 && !$contributionRecurId) {
          // Allow autorescue donations to be charged in close date ranges
          if (!$this->getAutorescueReference($recurringPayment)) {
            Civi::log('wmf')->info('Skipping payment: Two recurring charges within 23 days. recurring_id: ' . $recurringPayment['id']);
            continue;
          }
        }

        // Mark the recurring contribution Processing
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recurringPayment['id'],
          'contribution_status_id' => 'Processing',
        ]);

        $paymentParams = $this->getPaymentParams(
          $recurringPayment,
          $previousContribution
        );
        $payment = $this->makePayment($paymentParams);

        // record payment if it's not a UPI bank transfer pre-notification
        if (!$this->isUpiBankTransferPreNotification($paymentParams['payment_instrument'])) {
          $this->recordPayment($payment, $recurringPayment, $previousContribution);
        }

        $this->setAsInProgressAndUpdateNextChargeDate($recurringPayment);
        $result['success']['ids'][] = $recurringPayment['id'];
        // display information in the result array
        $result[$recurringPayment['id']]['status'] = $payment['payment_status'];
        $result[$recurringPayment['id']]['invoice_id'] = $payment['invoice_id'];
        $result[$recurringPayment['id']]['processor_id'] = $payment['processor_id'];
      } catch (CRM_Core_Exception $e) {
        $this->recordFailedPayment($recurringPayment, $e);
        $this->addErrorStats($errorCount, $e->getCode());
        // display information in the result array
        $result[$recurringPayment['id']]['error'] = $e->getMessage();
        $result[$recurringPayment['id']]['error_code'] = $e->getCode();
        $result['failed']['ids'][] = $recurringPayment['id'];
      }
    }

    $stats = array_merge([
      'Completed' => count($result['success']['ids'])
    ], $errorCount);
    CRM_SmashPig_Hook::smashpigOutputStats($stats);
    return $result;
  }

  /**
   * Count the recurring transactions that has any of the error codes that corresponds
   * to any of the cases.
   * @param array $errorCount
   * @param int $code
   */
  private function addErrorStats(array &$errorCount, int $code): void {
    switch ($code) {
      case ErrorCode::DECLINED:
        ++$errorCount['Failed{error="declined"}'];
        break;
      case ErrorCode::DECLINED_DO_NOT_RETRY:
        ++$errorCount['Failed{error="declined_do_not_retry"}'];
        break;
      case ErrorCode::MISSING_TRANSACTION_ID:
        ++$errorCount['Failed{error="missing_transaction_id"}'];
        break;
      case ErrorCode::NO_RESPONSE:
        ++$errorCount['Failed{error="no_response"}'];
        break;
      case ErrorCode::SERVER_TIMEOUT:
        ++$errorCount['Failed{error="server_timeout"}'];
        break;
      case ErrorCode::TRANSACTION_NOT_FOUND:
        ++$errorCount['Failed{error="transaction_not_found"}'];
        break;
      default:
        ++$errorCount['Failed{error="other"}'];
    }
  }

  /**
   * There is a possibility that a recur row is changed to a different status after we
   * pick it up in getPaymentsToCharge, so we need to double-check the status before we
   * actually charge the payment.
   *
   * @param $contributionRecurId
   *
   * @return string|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getRecurStatus($contributionRecurId): ?string {
    $recurInfo = ContributionRecur::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $contributionRecurId)->execute()->first();
    return $recurInfo['contribution_status_id:name'];
  }

  /**
   * Get all the recurring payments that are due to be charged, in an
   * eligible status, and handled by SmashPig processor types. Or if
   * a $contributionRecurId is passed, just fetch details for that
   * specific recurring contribution.
   *
   * @param ?int $contributionRecurId
   *
   * @return \Civi\Api4\Generic\Result
   * @throws \CRM_Core_Exception
   */
  protected function getPaymentsToCharge($contributionRecurId = NULL) {
    $getAction = ContributionRecur::get(FALSE)
      ->addSelect('*')
      ->addSelect('custom.*')
      ->addSelect('contribution_recur_smashpig.original_country:abbr');

    if ($contributionRecurId) {
      $getAction->addWhere('id', '=', $contributionRecurId);
    } else {
      $earliest = "-$this->catchUpDays days";
      $getAction->addWhere(
        'next_sched_contribution_date',
        'BETWEEN',
        [
          UtcDate::getUtcDatabaseString($earliest),
          UtcDate::getUtcDatabaseString(),
        ]
      )->addWhere(
        'payment_processor_id', 'IN', array_keys($this->smashPigProcessors)
      )->addWhere(
        'contribution_status_id:name',
        'IN',
        [
          'Pending',
          'Overdue',
          'In Progress',
          'Failing',
          // @todo - remove Completed once our In Progress payments
          // all have an IN Progress status. Ditto Failed vs Failing.
          'Completed',
          'Failed',
        ]
      )->addWhere(
      // T335152 if cancel date not null, means it has been cancelled before
        'cancel_date', 'IS NULL'
      )->addWhere(
      // FIXME: we need this token not null clause because we've been
      // misusing the payment_processor_id for years :(
        'payment_token_id', 'IS NOT NULL'
      )->setLimit($this->batchSize);
    }
    return $getAction->execute();
  }

  /**
   * Given an invoice ID for a recurring payment, get the invoice ID for the
   * next payment in the series.
   *
   * TODO: hook? this logic is specific to the WMF's invoice ID format
   *
   * @param string $previousInvoiceId
   * @param int $failures
   *
   * @return string
   */
  protected static function getNextInvoiceId($previousInvoiceId, $failures = 0) {
    $invoiceParts = explode('|', $previousInvoiceId);
    $previousInvoiceId = $invoiceParts[0];
    $invoiceParts = explode('.', $previousInvoiceId);
    $ctId = $invoiceParts[0];
    if (count($invoiceParts) > 1 && intval($invoiceParts[1])) {
      $previousSequenceNum = intval($invoiceParts[1]);
    }
    else {
      $previousSequenceNum = 0;
    }

    // Include failed attempts in the sequence number
    $currentSequenceNum = $previousSequenceNum + $failures + 1;
    return "$ctId.$currentSequenceNum";
  }

  /**
   * @param array $payment
   * @param array $recurringPayment
   * @param array $previousPayment
   *
   * @throws \CRM_Core_Exception
   */
  protected function recordPayment(
    $payment, $recurringPayment, $previousPayment
  ) {
    $invoiceId = $payment['invoice_id'];
    // Recurring Gift is used for the first in the series, Recurring Gift - Cash thereafter.
    $financialTypeID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift - Cash");
    if (empty($previousPayment['contribution_recur_id'])) {
      // It seems like adyen has situations where the previous payment is not
      // attached to the recurring contribution - in which case it makes sense
      // to treat the contribution as the first one.
      $financialTypeID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift");
    }

    $pid = $recurringPayment['payment_processor_id'];
    $processorName = $this->smashPigProcessors[$pid]['name'];

    if ($this->useQueue) {
      $ctId = explode('.', $invoiceId)[0];
      $queueMessage = [
        'contact_id' => $recurringPayment['contact_id'],
        'financial_type_id' => $financialTypeID,
        'payment_instrument_id' => $previousPayment['payment_instrument_id'],
        'invoice_id' => $invoiceId,
        'gateway' => $processorName,
        'gross' => $recurringPayment['amount'],
        'currency' => $recurringPayment['currency'],
        'gateway_txn_id' => $payment['processor_id'],
        'payment_method' => CRM_Core_Payment_SmashPig::getPaymentMethod($previousPayment),
        'date' => UtcDate::getUtcTimestamp(),
        'contribution_recur_id' => $recurringPayment['id'],
        'contribution_tracking_id' => $ctId,
        'recurring' => TRUE,
        // Restrictions field
        'restrictions' => $previousPayment['Gift_Data.Fund'],
        // Gift Source field.
        'gift_source' => $previousPayment['Gift_Data.Campaign'],
        // Direct Mail Appeal field
        'direct_mail_appeal' => $previousPayment['Gift_Data.Appeal'],
      ];

      if ($this->isProcessorGravy($processorName)) {
        $queueMessage = $this->addProcessorSpecificFieldsToQueueMessage($queueMessage, $payment);
      }

      QueueWrapper::push('donations', $queueMessage, TRUE);
    }
    else {
      // Create the contribution
      $contributionValues = [
        'financial_type_id' => $financialTypeID,
        'payment_instrument_id' => $previousPayment['payment_instrument_id'],
        'total_amount' => $recurringPayment['amount'],
        'currency' => $recurringPayment['currency'],
        'contribution_recur_id' => $recurringPayment['id'],
        'contribution_status_id:name' => 'Completed',
        'invoice_id' => $invoiceId,
        'contact_id' => $recurringPayment['contact_id'],
        'trxn_id' => $payment['processor_id'],
        // https://phabricator.wikimedia.org/T345920
        // Restrictions field
        'Gift_Data.Fund' => $previousPayment['Gift_Data.Fund'],
        // Gift Source field.
        'Gift_Data.Campaign' => $previousPayment['Gift_Data.Campaign'],
        // Direct Mail Appeal field
        'Gift_Data.Appeal' => $previousPayment['Gift_Data.Appeal'],
      ];

      if ($this->isProcessorGravy($processorName)) {
        $contributionValues = $this->addProcessorSpecificFieldsToContribution($contributionValues, $payment);
      }

      Contribution::create(FALSE)
        ->setValues($contributionValues)
        ->execute();
    }
  }

  protected function createActivity($recurringPayment, $errorResponse, $errorMessage, $type) {
    if ($type == 'failure') {
      $name = 'Recurring Failure';
      $subject = 'Payment of ' . $recurringPayment['amount']. ' ' . $recurringPayment['currency'] . ' failed with ' . $errorMessage;
      $details = $subject;
    } else if ($type == 'processorRetry') {
      $name = 'Recurring Processor Retry - Start';
      $subject = 'Processor retry started with rescue reference ' . $errorResponse->getProcessorRetryRescueReference();
      $details = 'Payment of ' . $recurringPayment['amount'] . ' ' .  $recurringPayment['currency'] . ' failed with ' . $errorMessage;
    } else {
      throw new UnexpectedValueException('Bad activity type: ' . $type);
    }

    $createCall = Activity::create(FALSE)
      ->addValue('activity_type_id:name', $name)
      ->addValue('source_record_id', $recurringPayment['id'])
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', $subject)
      ->addValue('details', $details)
      ->addValue('source_contact_id', $recurringPayment['contact_id'])
      ->addValue('target_contact_id', $recurringPayment['contact_id']);
    $createCall->execute();
  }

  /**
   * @param array $recurringPayment
   * @param \CRM_Core_Exception $exception
   *
   * @throws \Exception
   */
  protected function recordFailedPayment($recurringPayment, CRM_Core_Exception $exception) {
    $cancelRecurringDonation = FALSE;
    $errorData = $exception->getErrorData();
    $errorResponse = $errorData['smashpig_processor_response'] ?? $exception->getMessage();

    // Get the text of the error
    $errorMessage = SmashPigPaymentError::getErrorText($errorResponse);

    $this->createActivity($recurringPayment, $errorResponse, $errorMessage, 'failure');

    $params = [
      'id' => $recurringPayment['id'],
    ];
    if (!empty($errorResponse) &&
                $errorResponse instanceof CreatePaymentWithProcessorRetryResponse
    ) {
      // if failed, also update the rescue_reference
      if (!empty($errorResponse->getProcessorRetryRescueReference())) {
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $recurringPayment['id'])
          ->setValues([
            'contribution_recur_smashpig.rescue_reference' => $errorResponse->getProcessorRetryRescueReference(),
          ])->execute();
      }
      if ($errorResponse->getIsProcessorRetryScheduled()) {
        // Set status to Pending but advance the next charge date a month so we don't try to charge again
        $params['contribution_status_id'] = 'Pending';
        $params['next_sched_contribution_date'] = CRM_Core_Payment_Scheduler::getNextContributionDate($recurringPayment);
        $this->createActivity($recurringPayment, $errorResponse, $errorMessage, 'processorRetry');
      } else {
        // This happens when a payment cannot be rescued.
        // For example, because of account closure or fraud.
        $cancelRecurringDonation = TRUE;
        // retryWindowHasElapsed: The rescue window expired.
        // maxRetryAttemptsReached: The maximum number of retry attempts was made.
        // fraudDecline: The retry was rejected due to fraud.
        // internalError: An internal error occurred while retrying the payment.
        $params['cancel_reason'] = 'Payment cannot be rescued: ' . $errorResponse->getProcessorRetryRefusalReason();
        Civi::log('wmf')->info($params['cancel_reason'] . ' with contribution_recur_id:' . $recurringPayment['id']. ', and order reference is ' .  $recurringPayment['invoice_id']);
      }
    }
    else {
      // only if not handle by auto rescue, compare failure with maxFailure or update next retry day
      $newFailureCount = $recurringPayment['failure_count'] + 1;
      $params['failure_count'] = $newFailureCount;
      if ($exception->getErrorCode() === ErrorCode::DECLINED_DO_NOT_RETRY) {
        $cancelRecurringDonation = TRUE;
        $params['cancel_reason'] = '(auto) un-retryable card decline reason code';
      }
      if ($newFailureCount >= $this->maxFailures) {
        $cancelRecurringDonation = TRUE;
        $params['cancel_reason'] = '(auto) maximum failures reached';
      }
      else {
        $params['contribution_status_id'] = 'Failing';
        $params['next_sched_contribution_date'] = UtcDate::getUtcDatabaseString(
        "+$this->retryDelayDays days"
         );
      }
    }
    if ($cancelRecurringDonation) {
      // @todo note the core terminology would moe accurately set this to Failed
      // leaving cancelled for something where a user or staff member made a choice.
      $params['contribution_status_id'] = 'Cancelled';
      $params['cancel_date'] = UtcDate::getUtcDatabaseString();
    }
    civicrm_api3('ContributionRecur', 'create', $params);

    if ($cancelRecurringDonation) {
      $hasOtherActiveRecurring = $this->hasOtherActiveRecurringContribution(
        $recurringPayment['contact_id'],
        $recurringPayment['id']
      );

      if (!$hasOtherActiveRecurring) {
        // we only send a recurring failure email if the contact has no
        // other active recurring donations. see T260910
        $this->sendFailureEmail($recurringPayment['id'], $recurringPayment['contact_id']);
      }
    }
  }

  /**
   * Send an email notifing of cancellation.
   *
   * @param int $contributionRecurID
   * @param int $contactID
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function sendFailureEmail(int $contributionRecurID, int $contactID) {
    if ( Civi::settings()->get('smashpig_recurring_send_failure_email') ) {
      FailureEmail::send()->setCheckPermissions(FALSE)->setContactID($contactID)->setContributionRecurID($contributionRecurID)->execute();
    }
  }

  /**
   * Check if this recurring donation has been autorescued
   *
   * @param array $recurringPayment
   *
   * @return string|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getAutorescueReference($recurringPayment): ?string {
    $autorescue = ContributionRecur::get(FALSE)
      ->addSelect('contribution_recur_smashpig.rescue_reference')
      ->addWhere('id','=',$recurringPayment['id'])
      ->execute()
      ->first();

    return $autorescue['contribution_recur_smashpig.rescue_reference'];
  }

  /**
   * Try to find a previous contribution using its recurring id
   *
   * @param int $recurringId
   * @param int $isTest
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getPreviousContributionByRecurringId($recurringId, $isTest) {
    return Contribution::get(FALSE)
      ->addSelect('*', 'Gift_Data.*', 'payment_instrument_id:name', 'contribution_extra.*' )
      ->addWhere('contribution_recur_id', '=', $recurringId)
      ->addWhere('is_test', '=', $isTest)
      ->addOrderBy('receive_date', 'DESC')
      ->setLimit(1)
      ->execute()
      ->first();
  }

  /**
   * Try to find a previous contribution using its invoice_id
   *
   * @param int $invoiceId
   * @param int $isTest
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getPreviousContributionByInvoiceId($invoiceId, $isTest) {
    return Contribution::get(FALSE)
      ->addSelect('*', 'Gift_Data.*', 'payment_instrument_id:name')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->addWhere('is_test', '=', $isTest)
      ->addOrderBy('receive_date', 'DESC')
      ->setLimit(1)
      ->execute()
      ->first();
  }

  /**
   * Given a recurring contribution record, try to find the most recent
   * contribution relating to it via either the contribution_recur_id
   * or invoice_id.
   *
   * For newer recurring subscriptions, we do not add a contribution_recur_id
   * record to the original contribution as in some cases the recurring
   * subscription is independent of the earlier original contribution. At this
   * point you're likely thinking so why are we looking up the previous
   * contribution?!?!? ... and the answer is that the original contribution has
   * foreign keys to other required data elements that we rely when processing
   * the payment so we call on it for those.
   *
   * TODO: use ContributionRecur::getTemplateContribution ?
   *
   * @param array $recurringPayment
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getPreviousContribution($recurringPayment): array {
    // throw an error if parameters required to find a matching contribution are not available
    if (empty($recurringPayment['id']) && empty($recurringPayment['invoice_id'])) {
      throw new CRM_Core_Exception('Missing required parameters to find a matching contribution');
    }
    $result = NULL;
    if (!empty($recurringPayment['id'])) {
      $result = self::getPreviousContributionByRecurringId($recurringPayment['id'], $recurringPayment['is_test']);
    }
    if (!$result && !empty($recurringPayment['invoice_id'])) {
      $result = self::getPreviousContributionByInvoiceId($recurringPayment['invoice_id'], $recurringPayment['is_test']);
    }
    if (!$result) {
      throw new CRM_Core_Exception('No matching contribution');
    }
    $result['payment_instrument'] = $result['payment_instrument_id:name'];
    return $result;
  }

  /**
   * Get all the details needed to submit a recurring payment installment
   * via makePayment
   *
   * @param $recurringPayment
   * @param $previousContribution
   *
   * @return array tailored to the needs of makePayment
   * @throws \CRM_Core_Exception
   */
  protected function getPaymentParams(
    $recurringPayment, $previousContribution
  ) {
    $donor = Contact::get(FALSE)
      ->addSelect(
        'address_primary.country_id:abbr',
        'email_primary.email',
        'first_name',
        'last_name',
        'legal_identifier',
        'preferred_language'
      )
      ->addWhere('id', '=', $recurringPayment['contact_id'])
      ->execute()
      ->first();
    $currentInvoiceId = self::getNextInvoiceId(
      $previousContribution['invoice_id'],
      $recurringPayment['failure_count']
    );
    $tokenData = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $recurringPayment['payment_token_id'],
      'return' => ['token', 'ip_address'],
    ]);
    $ipAddress = $tokenData['ip_address'] ?? NULL;

    $params = [
      'amount' => $recurringPayment['amount'],
      'country' => $recurringPayment['contribution_recur_smashpig.original_country:abbr'] ?? $donor['address_primary.country_id:abbr'],
      'currency' => $recurringPayment['currency'],
      'email' => $donor['email_primary.email'],
      'first_name' => $donor['first_name'],
      'last_name' => $donor['last_name'],
      'legal_identifier' => $donor['legal_identifier'],
      'invoice_id' => $currentInvoiceId,
      'payment_processor_id' => $recurringPayment['payment_processor_id'],
      'contactID' => $previousContribution['contact_id'],
      'is_recur' => TRUE,
      'contributionRecurID' => $recurringPayment['id'],
      'description' => $this->descriptor,
      'token' => $tokenData['token'],
      'ip_address' => $ipAddress,
      'payment_instrument' => $previousContribution['payment_instrument'],
      'processor_contact_id' => $recurringPayment['contribution_recur_smashpig.processor_contact_id'] ?? NULL,
      // FIXME: SmashPig should choose 'first' or 'recurring' based on seq #
      'installment' => 'recurring',
    ];
    if (isset($recurringPayment['contribution_recur_smashpig.initial_scheme_transaction_id'])) {
      $params['initial_scheme_transaction_id'] = $recurringPayment['contribution_recur_smashpig.initial_scheme_transaction_id'];
    }
    return $params;
  }

  /**
   * @param array $paymentParams expected keys:
   *  amount
   *  currency
   *  first_name
   *  last_name
   *  email
   *  invoice_id
   *  payment_processor_id
   *  contactID
   *  isRecur
   *  contributionRecurID
   *  description
   *  token
   *  installment
   * @param int $failures number of times we have tried so far
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function makePayment($paymentParams, $failures = 0) {
    Civi::log('wmf')->info('Charging contribution_recur id: '.$paymentParams['contributionRecurID'].' with invoice_id: '.$paymentParams['invoice_id']);
    try {
      // Per https://github.com/civicrm/civicrm-core/pull/15639
      // contribution_id is a required id but it's required in order to
      // force people to create the contribution first. Ahem, we don't do that.
      // Adding a dummy contribution_id allows us to get past the check (I
      // even said that in the PR comments) until we become well behaved enough
      // to create the contribution first.
      // The value is not validated or used but I made it large enough that if it ever
      // were to be validated in core it would fail tests like a dying canary.
      $paymentParams['contribution_id'] = 8888888888888888888888;
      $payment = civicrm_api3('PaymentProcessor', 'pay', $paymentParams);
      $paymentInfo = $payment['values'][0];
      Civi::log('wmf')->info('Payment successful - invoice_id: '.$paymentInfo['invoice_id'].' with status: '.$paymentInfo['payment_status'].' and processor_id: '.$paymentInfo['processor_id']);
      $payment = reset($payment['values']);
      return $payment;
    } catch (CRM_Core_Exception $exception) {
      if (
        $failures < self::MAX_MERCHANT_REFERENCE_RETRIES &&
        $this->handleException($exception, $paymentParams)
      ) {
        // If handleException returned true, and we're below the failure
        // threshold, try again (with potentially changed $paymentParams)
        $failures += 1;
        return $this->makePayment($paymentParams, $failures);
      }
      else {
        throw $exception;
      }
    }
  }

  /**
   * Check if the donor has another active recurring contribution set up.
   *
   * @param int $contactID
   * @param int $recurringID ID of recurring contribution record
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  protected function hasOtherActiveRecurringContribution(int $contactID, int $recurringID) {
    $result = civicrm_api3('ContributionRecur', 'get', [
      'id' => ['!=' => $recurringID],
      'contact_id' => $contactID,
      'contribution_status_id' => [
        'IN' => [
          'Pending',
          'Overdue',
          'In Progress',
          'Failing' // hmm this isn't very active.
        ],
      ],
      'payment_token_id' => ['IS NOT NULL' => TRUE],
    ]);

    return ($result['count'] ?? 0) > 0 ;
  }

  /**
   * Handle an exception in a payment attempt, indicating whether retry is
   * possible and potentially mutating payment parameters.
   *
   * @param \CRM_Core_Exception $exception from PaymentProcessor::pay
   * @param array $paymentParams Same keys as argument to makePayment. Values
   *  may be mutated, depending on the recommended way of handling the error.
   *
   * @return bool TRUE if the payment should be tried again
   */
  protected function handleException(
    CRM_Core_Exception $exception,
    &$paymentParams
  ) {
    Civi::log('wmf')->info('Error: '.$exception->getErrorCode().' invoice_id:'.$paymentParams['invoice_id']);
    $data = $exception->getErrorData();
    if (
      !empty($data['smashpig_processor_response']) &&
      $data['smashpig_processor_response'] instanceof PaymentProviderResponse
    ) {
      $rawResponse = $data['smashpig_processor_response']->getRawResponse();
      if (!is_string($rawResponse)) {
        $rawResponse = json_encode($rawResponse);
      }
      Civi::log('wmf')->info('Raw response: ' . $rawResponse);
    }
    switch ($exception->getErrorCode()) {
      case ErrorCode::DUPLICATE_ORDER_ID:
        // If we get an error that means the merchant reference has already
        // been used, increment it and try again.
        $currentInvoiceId = $paymentParams['invoice_id'];
        $nextInvoiceId = self::getNextInvoiceId($currentInvoiceId);
        $paymentParams['invoice_id'] = $nextInvoiceId;
        Civi::log('wmf')->info('Duplicate invoice ID: Current invoice_id: '.$currentInvoiceId.' Next invoice_id: '.$nextInvoiceId);
        return TRUE;
      default:
        return FALSE;
    }
  }

  /**
   * @param $payment_instrument
   *
   * @return bool
   */
  protected function isUpiBankTransferPreNotification($payment_instrument) : bool {
    return in_array(
      $payment_instrument, ['Bank Transfer: UPI', 'Bank Transfer: PayTM Wallet']
    );
  }

  /**
   * @param $recurringPayment
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  protected function setAsInProgressAndUpdateNextChargeDate($recurringPayment) : void {
    civicrm_api3('ContributionRecur', 'create', [
      'id' => $recurringPayment['id'],
      'failure_count' => 0,
      'failure_retry_date' => NULL,
      'contribution_status_id' => 'In Progress',
      'next_sched_contribution_date' => CRM_Core_Payment_Scheduler::getNextContributionDate($recurringPayment),
    ]);
  }

  /**
   * When making a recurring charge for a subscription added via Gravy, we need
   * to add in some additional backend-processor fields to the queue message
   *
   * @See T381866
   *
   * @param array $queueMessage
   * @param array $payment
   *
   * @return array
   */
  protected function addProcessorSpecificFieldsToQueueMessage(
    array $queueMessage,
    array $payment
  ): array {
    return array_merge($queueMessage, [
      'backend_processor' => $payment['backend_processor'],
      'backend_processor_txn_id' => $payment['backend_processor_txn_id'],
      'payment_orchestrator_reconciliation_id' => $payment['payment_orchestrator_reconciliation_id'],
    ]);
  }

  /**
   * When making a recurring charge for a subscription added via Gravy, we need
   * to add in some additional backend-processor fields to the contribution
   * record
   *
   * @See T381866
   *
   * @param array $contributionValues
   * @param array $payment
   *
   * @return []|array
   */
  protected function addProcessorSpecificFieldsToContribution(array $contributionValues, array $payment): array {
    return array_merge($contributionValues, [
      'contribution_extra.backend_processor' => $payment['backend_processor'],
      'contribution_extra.backend_processor_txn_id' => $payment['backend_processor_txn_id'],
      'contribution_extra.payment_orchestrator_reconciliation_id' => $payment['payment_orchestrator_reconciliation_id'],
    ]);
  }

  /**
   * @param $processorName
   *
   * @return bool
   */
  protected function isProcessorGravy($processorName): bool {
    return $processorName === 'gravy';
  }

}
