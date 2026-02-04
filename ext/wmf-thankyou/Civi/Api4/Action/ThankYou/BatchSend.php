<?php

namespace Civi\Api4\Action\ThankYou;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\ThankYou;
use Civi\Api4\WorkflowMessage;
use Civi\Omnimail\MailFactory;
use Civi\WMFException\WMFException;
use Civi\WMFStatistic\PrometheusReporter;
use Civi\WMFStatistic\Queue2civicrmTrxnCounter;
use Civi\WMFThankYou\From;
use Civi\WMFTransaction;
use CRM_Core_Exception;

/**
 * Class Render.
 *
 * Get the content of the thank you.
 * @method $this setMessageLimit(int $messageLimit) Set consumer batch limit
 * @method $this setTimeLimit(int $timeLimit) Set consumer time limit (seconds)
 * @method $this setNumberOfDays(int $numberOfDays) Set the number of days to look back for contributions.
 */
class BatchSend extends AbstractAction {
  protected int $messageLimit = 0;

  /**
   * Result of update.
   *
   * @var array
   */
  private array $result;

  /**
   * Time limit permitted for the script to run.
   *
   * @var int $timeLimit
   */
  public int $timeLimit;

  /**
   * Number of days to look back for contributions.
   *
   * @var int
   */
  public int $numberOfDays;

  /**
   * Time the script started.
   *
   * @var int $startTime
   */
  private int $startTime;

  /**
   * @var array
   */
  protected array $doubleOptInCountries = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    \Civi::log('wmf')->info('thank_you: Attempting to send {message_limit} thank you mails for contributions from the last {number_of_days} days.', [
      'number_of_days' => $this->getNumberOfDays(),
      'message_limit' => $this->getMessageLimit() ? $this->getMessageLimit() : 'all',
      'time_limit' => $this->getTimeLimit(),
      'end_time' => date('Y-m-d H-i-s', $this->getEndTime()),
      'start_time' => date('Y-m-d H-i-s', $this->getStartTime()),
    ]);

    $this->updateContributionsWithoutEmail();
    $this->doubleOptInCountries = Civi::settings()->get('thank_you_double_opt_in_countries');

    $ty_query = <<<EOT
		SELECT civicrm_contribution.id, trxn_id, civicrm_contribution.contact_id
		FROM civicrm_contribution
		STRAIGHT_JOIN wmf_contribution_extra
			ON wmf_contribution_extra.entity_id = civicrm_contribution.id
		STRAIGHT_JOIN civicrm_email e ON e.contact_id = civicrm_contribution.contact_id AND e.is_primary = 1
		WHERE
			receive_date > %1 AND
			thankyou_date IS NULL AND
			(
			  no_thank_you IS NULL OR
			  no_thank_you IN ('', '0')
			)
		  AND e.email <> ''
		ORDER BY receive_date ASC
EOT;
    $params = [
      1 => [$this->getEarliestContributionDate(), 'String'],
    ];

    if ($this->getMessageLimit() > 0) {
      $ty_query .= ' LIMIT %2';
      $params[2] = [$this->getMessageLimit(), 'Int'];
    }

    $contribution = \CRM_Core_DAO::executeQuery($ty_query, $params);

    $consecutiveFailures = 0;
    $failureThreshold = \Civi::settings()->get('thank_you_failure_threshold');
    $this->result = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0];
    \Civi::log('wmf')->info('thank_you: Found {count} contributions to thank.', ['count' => $contribution->N]);

    while ($contribution->fetch()) {
      if (time() >= $this->getEndTime()) {
        \Civi::log('wmf')->info('thank_you: Batch time limit ({time_limit} s) elapsed', ['time_limit' => $this->getTimeLimit()]);
        break;
      }
      \Civi::log('wmf')->info(
        'thank_you: Attempting to send thank you mail for contribution ID [{contribution_id}], trxn_id [{trxn_id}], contact_id [{contact_id}]', [
        'contribution_id' => $contribution->id,
        'trxn_id' => $contribution->trxn_id,
        'contact_id' => $contribution->contact_id,

      ]);
      $this->result['attempted']++;
      try {
        if ($this->sendThankYou($contribution->id)) {
          $consecutiveFailures = 0;
          $this->result['succeeded']++;
        }
        else {
          $consecutiveFailures++;
          $this->result['failed']++;
        }
      }
      catch (CRM_Core_Exception $e) {
        $errName = $e->getErrorCode();
        $noThankYou = "failed: $errName";
        $this->result['failed']++;

        $logMessage = $e->getMessage()
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

        // Always email if we're disabling the job
        if (($e->getErrorCode() === 'BAD_EMAIL') && $consecutiveFailures <= $failureThreshold) {
          \Civi::log('wmf')->error('thank_you: {log_message}', ['log_message' => $logMessage]);
        }
        else {
          \Civi::log('wmf')->alert(
            'Thank you mail failed for contribution {contribution_id}', [
              'url' => \CRM_Utils_System::url('civicrm/contact/view/contribution', [
                'reset' => 1,
                'id' => $contribution->id,
                'action' => 'view',
              ], TRUE),
              'message' => $logMessage,
              'contribution_id' => $contribution->id,
              'subject' => 'Thank you mail failed for contribution ' . $contribution->id . ' ' . gethostname(),
              'consecutive_failures' => $consecutiveFailures,
            ]
          );
        }
      }
    }

    $counter = Queue2civicrmTrxnCounter::instance();
    $metrics = [];
    foreach ($counter->getTrxnCounts() as $gateway => $count) {
      $metrics["{$gateway}_thank_you_emails"] = (int)$count;
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
    $result[] = $this->result;
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
  private function sendThankYou(int $contribution_id): bool {
    // get contact mailing data from records
    // We do this cos we always have... However, if we simply call ThankYou::send
    // without retrieving this data is will retrieve it itself, presumably
    // equally efficiently as in both cases we do it record by record.
    $mailingData = $this->getMailingData($contribution_id);
    // don't send a Thank You email if one has already been sent
    if (!empty($mailingData['thankyou_date'])) {
      \Civi::log('wmf')->info('thank_you: Thank you email already sent for this transaction.');
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
        $ageInSeconds = time() - strtotime($mailingData['receive_date']);
        $counter->addAgeMeasurement($mailingData['gateway'], $ageInSeconds);
      }
      try {
        $this->sendDoubleOptInIfRequired($mailingData);
      } catch (\Exception $e) {
        Civi::log('wmf')->notice('thank_you: could not send double opt-in. {message}', ['message' => $e->getMessage()]);
      }
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Retrieve full contribution and contact record for mailing
   *
   * @param int $contribution_id
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \Civi\WMFException\WMFException
   */
  private function getMailingData(int $contribution_id): array {
    if (!isset(Civi::$statics['thank_you']['giftTableName'])) {
      Civi::$statics['thank_you']['giftTableName'] = civicrm_api3('CustomGroup', 'getvalue', [
        'name' => 'Gift_Data',
        'return' => 'table_name',
      ]);
    }
    $giftTable = Civi::$statics['thank_you']['giftTableName'];
    if (!isset(Civi::$statics['thank_you']['StockTableName'])) {
      Civi::$statics['thank_you']['StockTableName'] = civicrm_api3('CustomGroup', 'getvalue', [
        'name' => 'Stock_Information',
        'return' => 'table_name',
      ]);
    }
    $stockTable = Civi::$statics['thank_you']['StockTableName'];
    \Civi::log('wmf')->info(
      'thank_you: Selecting data for TY mail'
    );

    $mailingData = \CRM_Core_DAO::executeQuery("
    SELECT
      cntr.id AS contribution_id,
      cntr.currency,
      cntr.receive_date,
      cntr.thankyou_date,
      cntr.total_amount,
      cntr.trxn_id,
      cntr.payment_instrument_id,
      cntc.id AS contact_id,
      cntc.display_name,
      cntc.first_name,
      cntc.last_name,
      cntc.organization_name,
      cntc.contact_type,
      cntc.email_greeting_display,
      cntc.preferred_language,
      a.country_id,
      f.name AS financial_type,
      e.email,
      e.id as email_id,
      x.gateway,
      x.no_thank_you,
      x.original_amount,
      x.original_currency,
      x.source_type,
      g.campaign AS gift_source,
      s.stock_value,
      s.description_of_stock,
      s.stock_ticker,
      s.stock_qty,
      eci.venmo_user_name,
      recur.frequency_unit
    FROM civicrm_contribution cntr
    INNER JOIN civicrm_contact cntc ON cntr.contact_id = cntc.id
    LEFT JOIN civicrm_financial_type f ON f.id = cntr.financial_type_id
    LEFT JOIN civicrm_email e ON e.contact_id = cntc.id AND e.is_primary = 1
    LEFT JOIN civicrm_address a ON a.contact_id = cntc.id AND a.is_primary = 1
    LEFT JOIN civicrm_contribution_recur recur ON cntr.contribution_recur_id = recur.id
    INNER JOIN wmf_contribution_extra x ON cntr.id = x.entity_id
    LEFT JOIN $giftTable g ON cntr.id = g.entity_id
    LEFT JOIN $stockTable s ON cntr.id = s.entity_id
    LEFT JOIN wmf_external_contact_identifiers eci ON cntr.contact_id = eci.entity_id
    WHERE cntr.id = %1
  ", [
      1 => [
        $contribution_id,
        'Int',
      ],
    ]);
    $found = $mailingData->fetch();
    \Civi::log('wmf')->info('thank_you: Got data');
    // check that the API result is a valid contribution result
    if (!$found || !$mailingData->contact_id) {
      // the API result is bad
      $msg = 'Could not retrieve contribution record for: ' . $contribution_id . '<pre>' . print_r($mailingData, TRUE) . '</pre>';
      throw new WMFException(WMFException::GET_CONTRIBUTION, $msg);
    }
    return $mailingData->toArray();
  }

  /**
   * @return int
   */
  protected function getStartTime(): int {
    if (!isset($this->startTime)) {
      // If available, use the time the script started as the start time
      // This way we're less likely to run past the start of the next run.
      if (isset($_SERVER['REQUEST_TIME'])) {
        \Civi::log('wmf')->info('thank_you: Using REQUEST_TIME as start time');
        $this->startTime = $_SERVER['REQUEST_TIME'];
      }
      else {
        $this->startTime = time();
      }
    }
    return $this->startTime;
  }

  public function getTimeLimit(): int {
    if (!isset($this->timeLimit)) {
      $this->timeLimit = (int) Civi::settings()->get('thank_you_batch_time');
    }
    return $this->timeLimit;
  }

  protected function getEndTime(): int {
    return $this->getStartTime() + $this->getTimeLimit();
  }

  /**
   * Get the limit of messages to parse per run.
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  public function getMessageLimit(): int {
    if (!isset($this->messageLimit)) {
      $this->messageLimit = (int) \Civi::settings()->get('thank_you_batch');
      if (!$this->messageLimit) {
        throw new CRM_Core_Exception('thank_you: Thank you mail message limit not configured');
      }
    }
    return $this->messageLimit;
  }

  /**
   * Get the number of days to look back for contributions.
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  public function getNumberOfDays(): int {
    if (!isset($this->numberOfDays)) {
      $days = \Civi::settings()->get('thank_you_days');
      if (!$days) {
        throw new CRM_Core_Exception('thank_you_days is not configured');
      }
      $this->numberOfDays = $days;
    }
    return $this->numberOfDays;
  }

  /**
   * Get the earliest contribution date to look for.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getEarliestContributionDate(): string {
    $days = $this->getNumberOfDays();
    return date('Y-m-d H:i:s', strtotime("-$days days"));
  }

  /**
   * Update the no_thank_you field for contributions that have no email address to 'no_email'.
   *
   * This suppresses them from the thank you query.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function updateContributionsWithoutEmail(): void {
    \Civi::log('wmf')->info('checking for contributions without email address since {date}.', ['date' => $this->getEarliestContributionDate()]);;
    $noEmailContributions = (array) Contribution::get(FALSE)
      ->addJoin('Email AS email', 'LEFT', ['contact_id', '=', 'email.contact_id'], ['email.is_primary', '=', 1])
      ->addWhere('receive_date', '>', $this->getEarliestContributionDate())
      ->addWhere('receive_date', '<', '30 seconds ago')
      ->addWhere('thankyou_date', 'IS NULL')
      ->addWhere('email.email', 'IS EMPTY')
      ->addWhere('contribution_extra.no_thank_you', 'IS EMPTY')
      ->execute()->indexBy('id');
    \Civi::log('wmf')->info('thank_you: Found {count} contributions without email addresses.', ['count' => count($noEmailContributions)]);
    if ($noEmailContributions) {
      Contribution::update(FALSE)
        ->addValue('contribution_extra.no_thank_you', 'no_email')
        ->addWhere('id', 'IN', array_keys($noEmailContributions))
        ->execute();
      \Civi::log('wmf')->info('thank_you: Updated no thank you for {count} contributions without email addresses.', ['count' => count($noEmailContributions)]);
    }
  }

  protected function sendDoubleOptInIfRequired(array $mailingData): void {
    if (!in_array($mailingData['country_id'], $this->doubleOptInCountries)) {
      return;
    }

    $optIn = Contact::get(FALSE)
      ->addWhere('id', '=', $mailingData['contact_id'])
      ->setSelect(['Communication.opt_in'])
      ->execute()->first()['Communication.opt_in'];
    if (!$optIn) {
      return;
    }

    $activity = Activity::get(FALSE)
      ->addWhere('target_contact_id', '=', $mailingData['contact_id'])
      ->addWhere('activity_type_id:name', '=', 'Double Opt-In')
      ->setLimit(1)
      ->execute()->count();
    if ($activity) {
      return;
    }

    $message = WorkflowMessage::render(FALSE)
      ->setLanguage($mailingData['preferred_language'] ?: 'en_US')
      ->setValues(['contactID' => $mailingData['contact_id']])
      ->setWorkflow('double_opt_in')->execute()->first();

    $email = [
      'from_name' => From::getFromName('double_opt_in'),
      'from_address' => From::getFromAddress('double_opt_in'),
      'to_name' => $mailingData['display_name'],
      'to_address' => $mailingData['email'],
      'locale' => $mailingData['preferred_language'] ?: 'en_US',
      'html' => $message['html'],
      'subject' => $message['subject'],
    ];

    \Civi::log('wmf')->info(
      'thank_you: Sending double opt-in email to: {to_address}',
      ['to_address' => $email['to_address']]
    );
    if (MailFactory::singleton()->send($email, [])) {
      Activity::create(FALSE)->setValues([
        'source_contact_id' => $mailingData['contact_id'],
        'target_contact_id' => $mailingData['contact_id'],
        'activity_type_id:name' => 'Email',
        'activity_date_time' => 'now',
        'subject' => $message['subject'],
        'details' => $message['html'],
        'status_id:name' => 'Completed',
      ])->execute();
    }
  }

}
