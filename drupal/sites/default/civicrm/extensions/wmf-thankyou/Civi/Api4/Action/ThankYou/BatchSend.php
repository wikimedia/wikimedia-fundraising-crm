<?php

namespace Civi\Api4\Action\ThankYou;

use Civi;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\ThankYou;
use Civi\WMFException\WMFException;
use Civi\WMFStatistic\PrometheusReporter;
use Civi\WMFStatistic\Queue2civicrmTrxnCounter;
use Civi\WMFTransaction;

/**
 * Class Render.
 *
 * Get the content of the thank you.
 * @method $this setMessageLimit(int $messageLimit) Set consumer batch limit
 * @method int getMessageLimit() Get consumer batch limit
 * @method $this setTimeLimit(int $timeLimit) Set consumer time limit (seconds)
 * @method int getTimeLimit() Get consumer time limit (seconds)
 */
class BatchSend extends AbstractAction {
  protected int $messageLimit = 0;
  protected int $timeLimit = 0;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    // If available, use the time the script started as the start time
    // This way we're less likely to run past the start of the next run.
    if (isset($_SERVER['REQUEST_TIME'])) {
      $startTime = $_SERVER['REQUEST_TIME'];
    }
    else {
      $startTime = time();
    }

    $timeLimit = $this->getTimeLimit() ?: \Civi::settings()->get('thank_you_batch_time');
    $days = $days ?? \Civi::settings()->get('thank_you_days');
    $messageLimit = $this->getMessageLimit() ?: \Civi::settings()->get('thank_you_batch');
    $enabled = \Civi::settings()->get('thank_you_enabled');

    if ($enabled === 'false') {
      \Civi::log('wmf')->info('thank_you: Thank You send job is disabled');
      return;
    }
    if (!$days) {
      \Civi::log('wmf')->error('thank_you: Number of days to send thank you mails not configured');
      return;
    }
    if (!is_numeric($messageLimit)) {
      \Civi::log('wmf')->error('thank_you: Thank you mail message limit not configured');
      return;
    }

    // FIXME: refactor this whole module to be more object oriented, so we can
    // just set these properties and override during the test.
    // This code resets the thank_you_days variable in case it's been left in
    // a bad state by an aborted simulation run
    if ($days == DUMB_BIG_TY_DAYS) {
      $days = Civi::settings()->get('old_thank_you_days');
      Civi::settings()->set('thank_you_days', $days);
    }

    \Civi::log('wmf')->info('thank_you: Attempting to send {message_limit} thank you mails for contributions from the last {number_of_days} days.', [
      'number_of_days' => $days,
      'message_limit' => $messageLimit,
    ]);

    $earliest = date('Y-m-d H:i:s', strtotime("-$days days"));
    $ty_query = <<<EOT
		SELECT civicrm_contribution.id, trxn_id, contact_id
		FROM civicrm_contribution
		JOIN wmf_contribution_extra
			ON wmf_contribution_extra.entity_id = civicrm_contribution.id
		WHERE
			receive_date > %1 AND
			thankyou_date IS NULL AND
			(
			  no_thank_you IS NULL OR
			  no_thank_you IN ('', '0')
			)
		ORDER BY receive_date ASC LIMIT {$messageLimit};
EOT;

    $contribution = \CRM_Core_DAO::executeQuery($ty_query, [
      1 => [$earliest, 'String'],
    ]);

    $consecutiveFailures = 0;
    $failureThreshold = \Civi::settings()->get('thank_you_failure_threshold');
    $abort = FALSE;
    $endTime = $startTime + $timeLimit;
    while ($contribution->fetch()) {
      if (time() >= $endTime) {
        \Civi::log('wmf')->info('thank_you: Batch time limit ({time_limit} s) elapsed', ['time_limit' => $timeLimit]);
        break;
      }
      \Civi::log('wmf')->info(
        'thank_you: Attempting to send thank you mail for contribution ID [{contribution_id}], trxn_id [{trxn_id}], contact_id [{contact_id}]', [
        'contribution_id' => $contribution->id,
        'trxn_id' => $contribution->trxn_id,
        'contact_id' => $contribution->contact_id,

      ]);
      try {
        $this->sendThankYou($contribution->id);
        $consecutiveFailures = 0;
      }
      catch (WMFException $ex) {
        // let's get rid of this `gerErrorName()` & move this code towards
        // working with CRM_Core_Exception & leave the WMF_Exception for the queue processors
        $errName = $ex->getErrorName();
        $noThankYou = "failed: $errName";

        $logMessage = $ex->getMessage()
          . "<br/>Setting no_thank_you to '$noThankYou'";
        \Civi::log('wmf')->info('wmf_civicrm: Preventing thank-you for contribution {contribution_id} because: {reason}',
          ['contribution_id' => $contribution->id, 'reason' => $noThankYou]);

        try {
          Contribution::update(FALSE)
            ->addValue('contribution_extra.no_thank_you', $noThankYou)
            ->addWhere('id', '=', $contribution->id)
            ->execute();
        }
        catch (\CRM_Core_Exception $coreEx) {
          \Civi::log('wmf')->error('wmf_civicrm: Updating with no-thank-you failed with details: {message}', ['message' => $coreEx->getMessage()]);
        }

        $consecutiveFailures++;
        $msg = "Disabling thank you job after $consecutiveFailures consecutive failures";
        if ($consecutiveFailures > $failureThreshold) {
          $abort = TRUE;
          \Civi::log('wmf')->alert('thank_you: {message}', ['message' => $msg]);
          $logMessage .= "<br/>$msg";
        }

        // Always email if we're disabling the job
        if ($ex->isNoEmail() && !$abort) {
          \Civi::log('wmf')->error('thank_you: {log_message}', ['log_message' => $logMessage]);
        }
        else {
          try {
            \Civi::log('wmf')->alert(
              'Thank you mail failed for contribution {contribution_id}', [
                'url' => \CRM_Utils_System::url('civicrm/contact/view/contribution', [
                  'reset' => 1,
                  'id' => $contribution->id,
                  'action' => 'view',
                ], TRUE),
                'message' => $logMessage,
                'contribution_id' => $contribution->id,
                'subject' => 'Thank you mail failed for contribution ' . $contribution->id . " " . gethostname(),
              ]
            );
          }
          catch (\Exception $innerEx) {
            \Civi::log('wmf')->alert('thank_you: Can\'t even send failmail, disabling thank you job');
            $abort = TRUE;
          }
        }

        if ($abort) {
          variable_set('thank_you_enabled', 'false');
          break;
        }
      }
    }

    $counter = Queue2civicrmTrxnCounter::instance();
    $metrics = [];
    foreach ($counter->getTrxnCounts() as $gateway => $count) {
      $metrics["{$gateway}_thank_you_emails"] = $count;
    }
    $metrics['total_thank_you_emails'] = $counter->getCountTotal();
    $prometheusPath = \Civi::settings()->get('metrics_reporting_prometheus_path');
    $reporter = new PrometheusReporter($prometheusPath);
    $reporter->reportMetrics('thank_you_emails_sent', $metrics);
    $ageMetrics = [];
    foreach ($counter->getAverageAges() as $gateway => $age) {
      $ageMetrics["{$gateway}_thank_you_donation_age"] = $age;
    }

    $reporter->reportMetrics('thank_you_donation_age', $ageMetrics);
    \Civi::log('wmf')->info(
      'thank_you: Sent {total_thank_you_emails} thank you emails.',
      ['total_thank_you_emails' => $metrics['total_thank_you_emails']]
    );
  }


  /**
   * Send a TY letter, and do bookkeeping on the Civi records
   * TODO: rewrite the civi api stuff to work like other code
   *
   * @param int $contribution_id
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  private function sendThankYou(int $contribution_id) {
    // get contact mailing data from records
    $mailingData = get_mailing_data($contribution_id);
    // don't send a Thank You email if one has already been sent
    if (!empty($mailingData['thankyou_date'])) {
      \Civi::log('wmf')->info('thank_you: Thank you email already sent for this transaction.');
      return FALSE;
    }
    // only send a Thank You email if we are within the specified window
    $ageInSeconds = time() - strtotime($mailingData['receive_date']);
    if ($ageInSeconds > 86400 * Civi::settings()->get('thank_you_days')) {
      \Civi::log('wmf')->info('thank_you: Contribution is older than limit, ignoring.');
      return FALSE;
    }

    // check for contacts without an email address
    if (empty($mailingData['email'])) {
      \Civi::log('wmf')->info('thank_you: No usable email address found');
      Contribution::update(FALSE)
        ->addValue('contribution_extra.no_thank_you', 'no email')
        ->addWhere('id', '=', $contribution_id)
        ->execute();
      return FALSE;
    }

    if ($mailingData['no_thank_you']) {
      \Civi::log('wmf')->info('thank_you: Contribution has been marked no_thank_you={no_thank_you_reason}, skipping.', ['no_thank_you_reason' => $mailingData['no_thank_you']]);
      return FALSE;
    }

    $amount = $mailingData['original_amount'];
    $currency = $mailingData['original_currency'];

    // Use settlement currency if the original currency is virtual, for tax reasons.
    if ($mailingData['original_currency'] === 'BTC') {
      $amount = $mailingData['total_amount'];
      $currency = $mailingData['currency'];
    }

    $is_recurring = FALSE;
    try {
      $transaction = WMFTransaction::from_unique_id($mailingData['trxn_id']);
      $is_recurring = $transaction->is_recurring;
    }
    catch (WMFException $ex) {
      \Civi::log('wmf')->notice('thank_you: {message}', ['message', $ex->getMessage()]);
    }

    $locale = $mailingData['preferred_language'];
    if (!$locale) {
      \Civi::log('wmf')->info('thank_you: Donor language unknown.  Defaulting to English...');
      $locale = 'en';
    }

    // Select the email template
    if ($mailingData['financial_type'] === 'Endowment Gift') {
      $template = 'endowment_thank_you';
    }
    else {
      $template = 'thank_you';
    }

    $params = [
      'amount' => $amount,
      'contact_id' => $mailingData['contact_id'],
      'currency' => $currency,
      'first_name' => $mailingData['first_name'],
      'last_name' => $mailingData['last_name'],
      'contact_type' => $mailingData['contact_type'],
      'organization_name' => $mailingData['organization_name'],
      'email_greeting_display' => $mailingData['email_greeting_display'],
      'frequency_unit' => $mailingData['frequency_unit'],
      'language' => $mailingData['preferred_language'] ?: 'en_US',
      'receive_date' => $mailingData['receive_date'],
      'recipient_address' => $mailingData['email'],
      'recurring' => $is_recurring,
      'transaction_id' => "CNTCT-{$mailingData['contact_id']}",
      // shown in the body of the text
      'gift_source' => $mailingData['gift_source'],
      'stock_value' => $mailingData['stock_value'],
      'stock_ticker' => $mailingData['stock_ticker'],
      'stock_qty' => $mailingData['stock_qty'],
      'description_of_stock' => $mailingData['description_of_stock'],
    ];

    if (!empty($mailingData['venmo_user_name'])) {
      $params['venmo_user_name'] = $mailingData['venmo_user_name'];
    }

    \Civi::log('wmf')->info('thank_you: Calling thank_you_send_mail');

    $require_params = [
      'amount',
      'currency',
      'receive_date',
      'recipient_address',
      'recurring',
      'transaction_id',
    ];

    $missing = [];
    foreach ($require_params as $key) {
      if (!isset($params[$key]) || $params[$key] === '') {
        $missing[] = $key;
      }
    }
    if ($missing) {
      $as_list = implode(', ', $missing);
      \Civi::log('wmf')->error('thank_you: Missing stuff from the TY params: {missing} {params}', ['missing' => $missing, 'params' => $params]);
      $msg = "FAILED TO RENDER HTML EMAIL because of missing parameters {$as_list} in " . __FUNCTION__;
      // Actually using WMFException only actually is better than CRM_Core_Exception in queue processing context (sometimes).
      throw new WMFException(WMFException::MISSING_MANDATORY_DATA, $msg);
    }
    $success = ThankYou::send(FALSE)
      ->setDisplayName($mailingData['display_name'])
      ->setLanguage($params['language'])
      ->setTemplateName($template)
      ->setParameters($params)
      ->setContributionID($contribution_id)
      ->execute()->first()['is_success'];
    $counter = Queue2civicrmTrxnCounter::instance();

    if ($success) {
      $counter->increment($mailingData['gateway']);
      if ($mailingData['source_type'] === 'payments') {
        $counter->addAgeMeasurement($mailingData['gateway'], $ageInSeconds);
      }
      return TRUE;
    }
  }

}
