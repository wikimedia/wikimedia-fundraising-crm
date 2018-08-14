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

  public function run() {
    $recurringPayments = $this->getPaymentsToCharge();
    $result = [];
    foreach ($recurringPayments as $recurringPayment) {
      $paymentProcessorID = $recurringPayment['payment_processor_id'];
      try {
        // TODO: use ContributionRecur::getTemplateContribution ?
        $previousContribution = civicrm_api3('Contribution', 'getsingle', [
          'contribution_recur_id' => $recurringPayment['id'],
          'options' => [
            'limit' => 1,
            'sort' => 'receive_date DESC',
          ],
          'is_test' => CRM_Utils_Array::value('is_test', $recurringPayment['is_test']),
        ]);
        $donor = civicrm_api3('Contact', 'getsingle', [
          'id' => $recurringPayment['contact_id'],
          'return' => ['first_name', 'last_name', 'email']
        ]);
        $result[$recurringPayment['id']]['previous_contribution'] = $previousContribution;
        // Mark the recurring contribution in progress
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recurringPayment['id'],
          'contribution_status_id' => 'In Progress',
        ]);
        $installments = $recurringPayment['installments'];

        $currentInvoiceId = self::getNextInvoiceId($previousContribution);
        $description = $this->getDescription($recurringPayment);
        $payment = civicrm_api3('PaymentProcessor', 'pay', [
          'amount' => $previousContribution['total_amount'],
          'currency' => $previousContribution['currency'],
          'first_name' => $donor['first_name'],
          'last_name' => $donor['last_name'],
          'email' => $donor['email'],
          'invoice_id' => $currentInvoiceId,
          'payment_processor_id' => $paymentProcessorID,
          'contactID' => $previousContribution['contact_id'],
          'is_recur' => TRUE,
          'contributionRecurID' => $recurringPayment['id'],

          'description' => $description,
          'token' => civicrm_api3('PaymentToken', 'getvalue', [
            'id' => $recurringPayment['payment_token_id'],
            'return' => 'token',
          ]),
          // FIXME: SmashPig should choose 'first' or 'recurring' based on seq #
          'installment' => 'recurring',
        ]);
        $payment = reset($payment['values']);
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
          'installments' => $installments + 1,
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
   * TODO: hook? this logic is specific to the WMF's invoice ID format
   * @param array $previousContribution
   *
   * @return string
   */
  protected static function getNextInvoiceId($previousContribution) {
    $invoiceParts = explode('|', $previousContribution['invoice_id']);
    $previousInvoiceId = $invoiceParts[0];
    $invoiceParts = explode('.', $previousInvoiceId);
    $ctId = $invoiceParts[0];
    if (count($invoiceParts) && intval($invoiceParts[1])) {
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
        // Setting both until we are sure contribution_type_id is not being used anywhere.
        'contribution_type_id' => $previousPayment['financial_type_id'],
        'payment_instrument_id' => $previousPayment['payment_instrument_id'],
        'invoice_id' => $invoiceId,
        'gateway' => 'ingenico',
        // TODO: generalize
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
   * @param $recurringPayment
   *
   * @return string
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function getDescription($recurringPayment) {
    $domain = CRM_Core_BAO_Domain::getDomain();
    $contactLang = civicrm_api3('Contact', 'getvalue', [
      'return' => 'preferred_language',
      'id' => $recurringPayment['contact_id'],
    ]);
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
}
