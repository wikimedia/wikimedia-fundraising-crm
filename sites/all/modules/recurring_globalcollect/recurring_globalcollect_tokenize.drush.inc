<?php

use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\Core\UtcDate;

function recurring_globalcollect_tokenize_drush_command() {
  $items['recurring-globalcollect-tokenize'] = [
    'description' => 'Convert WebCollect recurring transactions to Connect tokenized recurring transactions.',
    'arguments' => [
      'batch' => 'Batch size'
    ],
  ];
  return $items;
}

function drush_recurring_globalcollect_tokenize($batchSize) {
  watchdog(
    'recurring_globalcollect',
    "Tokenizing {$batchSize} recurring GlobalCollect donations",
    NULL,
    WATCHDOG_INFO
  );
  civicrm_initialize();

  $oldRecurrings = _recurring_globalcollect_tokenize_get_batch($batchSize);
  foreach ($oldRecurrings['values'] as $oldRecurring) {
    _recurring_globalcollect_tokenize($oldRecurring);
  }

  watchdog(
    'recurring_globalcollect',
    'Finished tokenize job.',
    NULL,
    WATCHDOG_INFO
  );
}

function _recurring_globalcollect_tokenize_get_batch($batchSize) {
  // select count(id) FROM civicrm_contribution_recur WHERE end_date IS NULL
  // AND contribution_status_id IN (1,5) AND trxn_id LIKE 'RECURRING GLOBALCOLLECT%'
  // AND DATE(next_sched_contribution_date) = CURDATE()
  $params = [
    'end_date' => ['IS NULL' => 1],
    'next_sched_contribution_date' => [
      'BETWEEN' => [
        UtcDate::getUtcDatabaseString('-1 days'),
        UtcDate::getUtcDatabaseString(),
      ],
    ],
    'contribution_status_id' => [
      'IN' => [
				'Pending',
				'Overdue',
				'In Progress',
				'Failing',
				'Completed',
				'Failed',
      ],
    ],
    'trxn_id' => [
      'LIKE' => 'RECURRING GLOBALCOLLECT%',
    ],
    'options' => ['limit' => $batchSize],
  ];

  return civicrm_api3('ContributionRecur', 'get', $params);
}

function _recurring_globalcollect_tokenize($oldRecurring) {
  // All information from old contribution_recur is in oldRecurring
  watchdog(
    'recurring_globalcollect',
    "Starting tokenizing ContributionRecur ".$oldRecurring['id'].", trxn id ".$oldRecurring['trxn_id'],
    NULL,
    WATCHDOG_INFO
  );

	// Setup SmashPig credentials
	wmf_common_create_smashpig_context( 'tokenize', 'ingenico' );

  // Set the contribution_recur to processing
  _recurring_globalcollect_update_record_to_processing($oldRecurring['id']);

  // Create new contribution tracking
  $transaction = WmfTransaction::from_unique_id($oldRecurring['trxn_id']);
  $contribution_tracking_id = recurring_get_contribution_tracking_id([
    'txn_type' => 'subscr_payment',
    'subscr_id' => $oldRecurring['trxn_id'],
    'payment_date' => strtotime("now"),
  ]);
  $values = [
    'amount' => $oldRecurring['amount'],
    'order_id' => $transaction->gateway_txn_id,
    'currency' => $oldRecurring['currency'],
    'language' => 'en',
    'contribution_tracking_id' => $contribution_tracking_id,
    // Avoiding some more code.
    'referrer' => 'oldGlobalCollectReucrringToIngenicoRecurring',
  ];

  // Create an ingenico token
  $adapter = DonationInterfaceFactory::createAdapter('globalcollect', $values);
  $adapter->do_transaction('CONVERT_PAYMENTTOPROFILE');
  $token = $adapter->getData_Unstaged_Escaped('recurring_payment_token');

  // Check for token failure
  if (empty($token)) {
    watchdog(
      'recurring_globalcollect',
      "Failed at creating token for ".$oldRecurring['id'],
      NULL,
      WATCHDOG_INFO
    );

    $errors = [];
    _recurring_globalcollect_update_record_failure($oldRecurring['id'],$errors);

    return;
  }


  watchdog(
    'recurring_globalcollect',
    "Successfully created token ".$token." for ".$oldRecurring['id'],
    NULL,
    WATCHDOG_INFO
  );

    // Create new token
    // The last param is user ip
    $payment_token_result = wmf_civicrm_recur_payment_token_create(
      $oldRecurring['contact_id'],
      'ingenico',
      $token,
      NULL
    );

    // Make first payment with new token
    try{

      // Get the previous payment
      $previousPayment = civicrm_api3('Contribution', 'getsingle', [
        'contribution_recur_id' => $oldRecurring['id'],
        'options' => [
          'limit' => 1,
          'sort' => 'receive_date DESC',
        ]
      ]);

      // Get donor information
      $donor = civicrm_api3('Contact', 'getsingle', [
        'id' => $oldRecurring['contact_id'],
        'return' => ['first_name', 'last_name', 'email', 'preferred_language'],
      ]);

      // Get the description
      $domain = CRM_Core_BAO_Domain::getDomain();
      $description = ts(
        'Monthly donation to %1',
        [
          $domain->name,
          // Extra parameters for use in custom translate functions
          'key' => 'donate_interface-monthly-donation-description',
          'language' => $donor['preferred_language'],
        ]
      );

      // Create the invoice_id
			$invoice_id = $contribution_tracking_id . '.1';

      // Make the new payment
      $paymentParams = [
        'amount' => $oldRecurring['amount'],
        'currency' => $oldRecurring['currency'],
        'first_name' => $donor['first_name'],
        'last_name' => $donor['last_name'],
        'email' => $donor['email'],
        'invoice_id' => $invoice_id,
        'payment_processor_id' => $payment_token_result['payment_processor_id'],
        'contactID' => $oldRecurring['contact_id'],
        'is_recur' => TRUE,
        'description' => $description,
        'token' => $payment_token_result['token'],
        'ip_address' => NULL,
        'installment' => 'recurring',
        // Per https://github.com/civicrm/civicrm-core/pull/15639
        // contribution_id is a required id but it's required in order to
        // force people to create the contribution first. Ahem, we don't do that.
        // Adding a dummy contribution_id allows us to get past the check (I
        // even said that in the PR comments) until we become well behaved enough
        // to create the contribution first.
        // The value is not validated or used but I made it large enough that if it ever
        // were to be validated in core it would fail tests like a dying canary.
        'contribution_id' => 9999999999999999,
      ];

      $payment = civicrm_api3('PaymentProcessor', 'pay', $paymentParams);

      // Create new recurring contribution
      $createrecurring = [
        'contact_id' => $oldRecurring['contact_id'],
        'amount' => $oldRecurring['amount'],
        'currency' => $oldRecurring['currency'],
        'frequency_unit' => $oldRecurring['frequency_unit'],
        'frequency_interval' => $oldRecurring['frequency_interval'],
        'installments' => $oldRecurring['installments'],
        'start_date' => $oldRecurring['start_date'],
        'create_date' => $oldRecurring['create_date'],
        'cancel_date' => NULL,
        'cycle_day' => $oldRecurring['cycle_day'],
        'next_sched_contribution_date' => $oldRecurring['next_sched_contribution_date'],
        'payment_token_id' => $payment_token_result['id'],
        'payment_processor_id' => $payment_token_result['payment_processor_id'],
        'processor_id' => $payment['values'][0]['processor_id'],
        'trxn_id' => $payment['values'][0]['processor_id'],
        'contribution_status_id' => 'Processing',
      ];

      $newRecurring = civicrm_api3('ContributionRecur', 'Create', $createrecurring);

      // Cancel old recurring contribution
      $cancel = [
        'id' => $oldRecurring['id'],
        'contribution_status_id' => 'Cancelled',
        'cancel_reason' => '(auto) Moved from old GlobalCollect to new Ingenico',
        'cancel_date' => UtcDate::getUtcDatabaseString(),
        'end_date' => UtcDate::getUtcDatabaseString(),
      ];

      civicrm_api3('ContributionRecur', 'create', $cancel);


      // Build a message
      $message = [
        'contact_id' => $oldRecurring['contact_id'],
        'financial_type_id' => $previousPayment['financial_type_id'],
        'contribution_type_id' => $previousPayment['financial_type_id'],
        'payment_instrument_id' => $previousPayment['payment_instrument_id'],
        'invoice_id' => $invoice_id,
        'gateway' => 'ingenico',
        'gross' => $oldRecurring['amount'],
        'currency' => $oldRecurring['currency'],
        'gateway_txn_id' => $payment['values'][0]['processor_id'],
        'payment_method' => 'cc',
        'date' => UtcDate::getUtcTimestamp(),
        'contribution_recur_id' => $newRecurring['id'],
        'contribution_tracking_id' => $contribution_tracking_id,
        'recurring' => true,
        'no_thank_you' => 'recurring',
      ];

      // Add source fields
      SourceFields::addToMessage($message);

      wmf_civicrm_contribution_message_import($message);

      // Update the contribution_status_id
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $newRecurring['id'],
        'failure_count' => 0,
        'failure_retry_date' => NULL,
        'contribution_status_id' => 'In Progress',
        'next_sched_contribution_date' => CRM_Core_Payment_Scheduler::getNextDateForMonth(
          $createrecurring
        ),
      ]);

      watchdog(
        'recurring_globalcollect',
        "Created new recurring ".$newRecurring['id']." for old recurring ".$oldRecurring['id'],
        NULL,
        WATCHDOG_INFO
      );

    } catch (CiviCRM_API3_Exception $exception) {
      // Update the next charge date with failure count logic, from the old globalcollect
      watchdog(
        'recurring_globalcollect',
        "Failed at creating new payment for ".$oldRecurring['id'],
        NULL,
        WATCHDOG_INFO
      );

      $errors = [];
      _recurring_globalcollect_update_record_failure($oldRecurring['id'],$errors);
    }
}
