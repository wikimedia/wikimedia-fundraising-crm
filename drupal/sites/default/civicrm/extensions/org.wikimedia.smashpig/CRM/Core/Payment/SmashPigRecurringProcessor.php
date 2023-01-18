<?php

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentData\ErrorCode;
use Civi\Api4\FailureEmail;

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
   * @param int $timeLimitInSeconds Maximum number of seconds to spend processing
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
   * @throws \CiviCRM_API3_Exception
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

        // Catch for double recurring payments in one month (23 days of one another)
        $days = date_diff(
          new DateTime($recurringPayment['next_sched_contribution_date']),
          new DateTime($previousContribution['receive_date'])
        )->days;

        // Ignore check when a specific contribution recur id is given
        if ($days < 24 && !$contributionRecurId) {
          throw new UnexpectedValueException('Two recurring charges within 23 days. recurring_id: '.$recurringPayment['id']);
        }

        $result[$recurringPayment['id']]['previous_contribution'] = $previousContribution;
        // Mark the recurring contribution Processing
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recurringPayment['id'],
          'contribution_status_id' => 'Processing',
        ]);

        $paymentParams = $this->getPaymentParams(
          $recurringPayment, $previousContribution
        );
        $payment = $this->makePayment($paymentParams);
        $this->recordPayment(
          $payment, $recurringPayment, $previousContribution
        );

        // Mark the recurring contribution as completed and set next charge date
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recurringPayment['id'],
          'failure_count' => 0,
          'failure_retry_date' => NULL,
          'contribution_status_id' => 'In Progress',
          'next_sched_contribution_date' => CRM_Core_Payment_Scheduler::getNextDateForMonth(
            $recurringPayment
          ),
        ]);
        $result['success']['ids'][] = $recurringPayment['id'];
      } catch (CiviCRM_API3_Exception $e) {
        $this->recordFailedPayment($recurringPayment, $e);
        $this->addErrorStats($errorCount, $e->getCode());
        $result[$recurringPayment['id']]['error'] = $e->getMessage();
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
   * Get all the recurring payments that are due to be charged, in an
   * eligible status, and handled by SmashPig processor types. Or if
   * a $contributionRecurId is passed, just fetch details for that
   * specific recurring contribution.
   *
   * @param ?int $contributionRecurId
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getPaymentsToCharge($contributionRecurId = NULL) {
    if ($contributionRecurId) {
      $params = [
        'id' => $contributionRecurId
      ];
    } else {
      $earliest = "-$this->catchUpDays days";
      $params = [
        'next_sched_contribution_date' => [
          'BETWEEN' => [
            UtcDate::getUtcDatabaseString($earliest),
            UtcDate::getUtcDatabaseString(),
          ],
        ],
        'payment_processor_id' => ['IN' => array_keys($this->smashPigProcessors)],
        'contribution_status_id' => [
          'IN' => [
            'Pending',
            'Overdue',
            'In Progress',
            'Failing',
            // @todo - remove Completed once our In Progress payments
            // all have an IN Progress status. Ditto Failed vs Failing.
            'Completed',
            'Failed',
          ],
        ],
        // FIXME: we need this token not null clause because we've been
        // misusing the payment_processor_id for years :(
        'payment_token_id' => ['IS NOT NULL' => TRUE],
        'options' => ['limit' => $this->batchSize],
      ];
    }
    $recurringPayments = civicrm_api3('ContributionRecur', 'get', $params);
    return $recurringPayments['values'];
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
   * @throws \CiviCRM_API3_Exception
   */
  protected function recordPayment(
    $payment, $recurringPayment, $previousPayment
  ) {
    $invoiceId = $payment['invoice_id'];
    if ($this->useQueue) {
      $ctId = explode('.', $invoiceId)[0];
      $pid = $recurringPayment['payment_processor_id'];
      $processorName = $this->smashPigProcessors[$pid]['name'];
      $queueMessage = [
        'contact_id' => $recurringPayment['contact_id'],
        'financial_type_id' => $previousPayment['financial_type_id'],
        // Setting both until we are sure contribution_type_id is not being
        // used anywhere.
        'contribution_type_id' => $previousPayment['financial_type_id'],
        'payment_instrument_id' => $previousPayment['payment_instrument_id'],
        'invoice_id' => $invoiceId,
        'gateway' => $processorName,
        'gross' => $recurringPayment['amount'],
        'currency' => $recurringPayment['currency'],
        'gateway_txn_id' => $payment['processor_id'],
        'payment_method' => 'cc',
        'date' => UtcDate::getUtcTimestamp(),
        'contribution_recur_id' => $recurringPayment['id'],
        'contribution_tracking_id' => $ctId,
        'recurring' => TRUE,
      ];

      QueueWrapper::push('donations', $queueMessage, true);
    }
    else {
      // Create the contribution
      civicrm_api3('Contribution', 'create', [
        'financial_type_id' => $previousPayment['financial_type_id'],
        'payment_instrument_id' => $previousPayment['payment_instrument_id'],
        'total_amount' => $recurringPayment['amount'],
        'currency' => $recurringPayment['currency'],
        'contribution_recur_id' => $recurringPayment['id'],
        'contribution_status_id' => 'Completed',
        'invoice_id' => $invoiceId,
        'contact_id' => $recurringPayment['contact_id'],
        'trxn_id' => $payment['processor_id'],
      ]);
    }
  }

  /**
   * @param array $recurringPayment
   * @param \CiviCRM_API3_Exception $exception
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Exception
   */
  protected function recordFailedPayment($recurringPayment, CiviCRM_API3_Exception $exception) {
    $newFailureCount = $recurringPayment['failure_count'] + 1;
    $params = [
      'id' => $recurringPayment['id'],
      'failure_count' => $newFailureCount,
    ];
    $cancelRecurringDonation = false;
    if ($exception->getErrorCode() === ErrorCode::DECLINED_DO_NOT_RETRY) {
      $cancelRecurringDonation = true;
      $params['cancel_reason'] = '(auto) un-retryable card decline reason code';
    }
    if ($newFailureCount >= $this->maxFailures) {
      $cancelRecurringDonation = true;
      $params['cancel_reason'] = '(auto) maximum failures reached';
    }
    else {
      $params['contribution_status_id'] = 'Failing';
      $params['next_sched_contribution_date'] = UtcDate::getUtcDatabaseString(
        "+$this->retryDelayDays days"
      );
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
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function sendFailureEmail(int $contributionRecurID, int $contactID) {
    if ( Civi::settings()->get('smashpig_recurring_send_failure_email') ) {
        FailureEmail::send()->setCheckPermissions(FALSE)->setContactID($contactID)->setContributionRecurID($contributionRecurID)->execute();
    }
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
   * @throws \CiviCRM_API3_Exception
   */
  public static function getPreviousContribution($recurringPayment) {

    try {
      // first try to match on contribution_recur_id
      return civicrm_api3('Contribution', 'getsingle', [
        'contribution_recur_id' => $recurringPayment['id'],
        'options' => [
          'limit' => 1,
          'sort' => 'receive_date DESC',
        ],
        'is_test' => CRM_Utils_Array::value(
          'is_test', $recurringPayment['is_test']
        ),
      ]);
    } catch (CiviCRM_API3_Exception $e) {
      // if the above call yields no result we check to see if a previous contribution
      // can be found using the invoice_id. If we don't find one here, we let the
      // CiviCRM_API3_Exception exception bubble up.
      return civicrm_api3('Contribution', 'getsingle', [
        'invoice_id' => $recurringPayment['invoice_id'],
        'options' => [
          'limit' => 1,
          'sort' => 'receive_date DESC',
        ],
        'is_test' => CRM_Utils_Array::value(
          'is_test', $recurringPayment['is_test']
        ),
      ]);
    }

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
   * @throws \CiviCRM_API3_Exception
   */
  protected function getPaymentParams(
    $recurringPayment, $previousContribution
  ) {
    $donor = civicrm_api3('Contact', 'getsingle', [
      'id' => $recurringPayment['contact_id'],
      'return' => ['first_name', 'last_name', 'email', 'preferred_language'],
    ]);
    $currentInvoiceId = self::getNextInvoiceId(
      $previousContribution['invoice_id'],
      $recurringPayment['failure_count']
    );
    $description = $this->descriptor;
    $tokenData = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $recurringPayment['payment_token_id'],
      'return' => ['token', 'ip_address'],
    ]);
    $ipAddress = isset($tokenData['ip_address']) ? $tokenData['ip_address'] : NULL;

    return [
      'amount' => $recurringPayment['amount'],
      'currency' => $recurringPayment['currency'],
      'first_name' => $donor['first_name'],
      'last_name' => $donor['last_name'],
      'email' => $donor['email'],
      'invoice_id' => $currentInvoiceId,
      'payment_processor_id' => $recurringPayment['payment_processor_id'],
      'contactID' => $previousContribution['contact_id'],
      'is_recur' => TRUE,
      'contributionRecurID' => $recurringPayment['id'],
      'description' => $description,
      'token' => $tokenData['token'],
      'ip_address' => $ipAddress,
      'payment_instrument' => $previousContribution['payment_instrument'],
      // Checking against null to stop Undefined index: invoice_id in logs
      'recurring_invoice_id' => $recurringPayment['invoice_id'] ?? NULL,
      // FIXME: SmashPig should choose 'first' or 'recurring' based on seq #
      'installment' => 'recurring',
    ];
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
   * @throws \CiviCRM_API3_Exception
   */
  protected function makePayment($paymentParams, $failures = 0) {
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
      $payment = reset($payment['values']);
      return $payment;
    } catch (CiviCRM_API3_Exception $exception) {
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
   * @throws \CiviCRM_API3_Exception
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
   * @param \CiviCRM_API3_Exception $exception from PaymentProcessor::pay
   * @param array $paymentParams Same keys as argument to makePayment. Values
   *  may be mutated, depending on the recommended way of handling the error.
   *
   * @return bool TRUE if the payment should be tried again
   */
  protected function handleException(
    CiviCRM_API3_Exception $exception,
    &$paymentParams
  ) {
    Civi::log('wmf')->info('Error: '.$exception->getErrorCode().' invoice_id:'.$paymentParams['invoice_id']);
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

}
