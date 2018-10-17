<?php

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use CRM_SmashPig_ExtensionUtil as E;

class CRM_Core_Payment_SmashPigRecurringProcessor {

  protected $useQueue;

  protected $retryDelayDays;

  protected $maxFailures;

  protected $catchUpDays;

  protected $batchSize;

  const MAX_MERCHANT_REFERENCE_RETRIES = 3;

  /**
   * @param bool $useQueue Send messages to donations queue instead of directly
   *  inserting new contributions
   * @param int $retryDelayDays Days to wait before retrying failed payment
   * @param int $maxFailures Maximum failures before canceling subscription
   * @param int $catchUpDays Number of days in the past to look for payments
   * @param int $batchSize Maximum number of payments to process in a batch
   */
  public function __construct(
    $useQueue,
    $retryDelayDays,
    $maxFailures,
    $catchUpDays,
    $batchSize
  ) {
    $this->useQueue = $useQueue;
    $this->retryDelayDays = $retryDelayDays;
    $this->maxFailures = $maxFailures;
    $this->catchUpDays = $catchUpDays;
    $this->batchSize = $batchSize;
  }

  /**
   * Charge a batch of recurring contributions
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function run() {
    $recurringPayments = $this->getPaymentsToCharge();
    $result = [];
    foreach ($recurringPayments as $recurringPayment) {
      try {
        $previousContribution = $this->getPreviousContribution($recurringPayment);
        $result[$recurringPayment['id']]['previous_contribution'] = $previousContribution;
        // Mark the recurring contribution in progress
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recurringPayment['id'],
          'contribution_status_id' => 'In Progress',
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
          'contribution_status_id' => 'Completed',
          // FIXME: set this to 1 instead of 0 for initial insert
          'installments' => $recurringPayment['installments'] + 1,
          'next_sched_contribution_date' => CRM_Core_Payment_Scheduler::getNextDateForMonth(
            $recurringPayment
          ),
        ]);
        $result['success']['ids'][] = $recurringPayment['id'];
      } catch (CiviCRM_API3_Exception $e) {
        $this->recordFailedPayment($recurringPayment);
        $result[$recurringPayment['id']]['error'] = $e->getMessage();
        $result['failed']['ids'][] = $recurringPayment['id'];
      }
    }
    return $result;
  }

  /**
   * Get all the recurring payments that are due to be charged, in an
   * eligible status, and handled by SmashPig processor types.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getPaymentsToCharge() {
    $smashpigProcessors = civicrm_api3('PaymentProcessor', 'get', ['class_name' => 'Payment_SmashPig']);
    $earliest = "-$this->catchUpDays days";
    $recurringPayments = civicrm_api3('ContributionRecur', 'get', [
      'next_sched_contribution_date' => [
        'BETWEEN' => [
          UtcDate::getUtcDatabaseString($earliest),
          UtcDate::getUtcDatabaseString(),
        ],
      ],
      'payment_processor_id' => ['IN' => array_keys($smashpigProcessors['values'])],
      'contribution_status_id' => [
        'IN' => [
          'Pending',
          'Overdue',
          'Completed',
          'Failed',
        ],
      ],
      // FIXME: we need this token not null clause because we've been
      // misusing the payment_processor_id for years :(
      'payment_token_id' => ['IS NOT NULL' => TRUE],
      'options' => ['limit' => $this->batchSize],
    ]);
    return $recurringPayments['values'];
  }

  /**
   * Given an invoice ID for a recurring payment, get the invoice ID for the
   * next payment in the series.
   *
   * TODO: hook? this logic is specific to the WMF's invoice ID format
   *
   * @param string $previousInvoiceId
   *
   * @return string
   */
  protected static function getNextInvoiceId($previousInvoiceId) {
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
    $currentSequenceNum = $previousSequenceNum + 1;
    return "$ctId.$currentSequenceNum";
  }

  protected function recordPayment(
    $payment, $recurringPayment, $previousPayment
  ) {
    $invoiceId = $payment['invoice_id'];
    if ($this->useQueue) {
      $ctId = explode('.', $invoiceId)[0];
      $queueMessage = [
        'contact_id' => $recurringPayment['contact_id'],
        'effort_id' => $recurringPayment['installments'] + 1,
        'financial_type_id' => $previousPayment['financial_type_id'],
        // Setting both until we are sure contribution_type_id is not being
        // used anywhere.
        'contribution_type_id' => $previousPayment['financial_type_id'],
        'payment_instrument_id' => $previousPayment['payment_instrument_id'],
        'invoice_id' => $invoiceId,
        'gateway' => 'ingenico', // TODO: generalize
        'gross' => $recurringPayment['amount'],
        'currency' => $recurringPayment['currency'],
        'gateway_txn_id' => $payment['trxn_id'],
        'payment_method' => 'cc',
        'date' => UtcDate::getUtcTimestamp(),
        'contribution_recur_id' => $recurringPayment['id'],
        'contribution_tracking_id' => $ctId,
        'recurring' => TRUE,
      ];

      QueueWrapper::push('donations', $queueMessage);
    }
    else {
      // Create the contribution
      civicrm_api3('Contribution', 'create', [
        'financial_type_id' => $previousPayment['financial_type_id'],
        'total_amount' => $recurringPayment['amount'],
        'currency' => $recurringPayment['currency'],
        'contribution_recur_id' => $recurringPayment['id'],
        'contribution_status_id' => 'Completed',
        'invoice_id' => $invoiceId,
        'contact_id' => $recurringPayment['contact_id'],
        'trxn_id' => $payment['trxn_id'],
      ]);
    }
  }

  protected function recordFailedPayment($recurringPayment) {
    $newFailureCount = $recurringPayment['failure_count'] + 1;
    $extraParams = [];
    if ($newFailureCount >= $this->maxFailures) {
      $status = 'Cancelled';
      $extraParams['cancel_date'] = UtcDate::getUtcDatabaseString();
    }
    else {
      $status = 'Failed';
      $retryDate = UtcDate::getUtcDatabaseString(
        "+$this->retryDelayDays days"
      );
      $extraParams['next_sched_contribution_date'] = $retryDate;
    }
    civicrm_api3('ContributionRecur', 'create', [
        'id' => $recurringPayment['id'],
        'contribution_status_id' => $status,
        'failure_count' => $newFailureCount,
      ] + $extraParams);
  }

  /**
   * Given a recurring contribution record, get the most recent contribution
   * in the series.
   *
   * TODO: use ContributionRecur::getTemplateContribution ?
   * @param array $recurringPayment
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getPreviousContribution($recurringPayment) {
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
  }

  /**
   * Get a description for a recurring payment, maybe even localized (if you
   * create a custom ts function to use the extra params).
   *
   * @param string $contactLang The language ISO code
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getDescription($contactLang) {
    $domain = CRM_Core_BAO_Domain::getDomain();
    // FIXME: localize this for the donor!
    $description = E::ts(
      'Monthly donation to %1',
      [
        $domain->name,
        // Extra parameters for use in custom translate functions
        'key' => 'donate_interface-monthly-donation-description',
        'language' => $contactLang,
      ]
    );
    return $description;
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
      'return' => ['first_name', 'last_name', 'email', 'preferred_language']
    ]);
    $currentInvoiceId = self::getNextInvoiceId(
      $previousContribution['invoice_id']
    );
    $description = $this->getDescription($donor['preferred_language']);
    $tokenData = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $recurringPayment['payment_token_id'],
      'return' => ['token', 'ip_address'],
    ]);

    return [
      'amount' => $previousContribution['total_amount'],
      'currency' => $previousContribution['currency'],
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
      'ip_address' => $tokenData['ip_address'],
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
    switch ($exception->getErrorCode()) {
      case '300620':
        // FIXME: this is currently dealing with an Ingenico-specific code.
        // SmashPig should eventually normalize these error codes.
        // If we get an error that means the merchant reference has already
        // been used, increment it and try again.
        $currentInvoiceId = $paymentParams['invoice_id'];
        $nextInvoiceId = self::getNextInvoiceId($currentInvoiceId);
        $paymentParams['invoice_id'] = $nextInvoiceId;
        return TRUE;
      default:
        return FALSE;
    }
  }

}
