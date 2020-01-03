<?php

namespace wmf_eoy_receipt;

use CRM_Contribute_PseudoConstant;
use CRM_Core_PseudoConstant;
use wmf_communication\Mailer;
use wmf_communication\Templating;
use wmf_communication\Translation;

class EoySummary {

  static protected $templates_dir;

  static protected $template_name;

  static protected $option_keys = [
    'year',
    'contact_id',
    'batch',
    'job_id',
  ];

  protected $batch = 100;

  protected $year;

  protected $contact_id;

  protected $from_address;

  protected $from_name;

  /**
   * Temporary tables.
   *
   * These are instances of CRM_Utils_SQL_TempTable and are dropped when the object deconstructs.
   *
   * @var \CRM_Utils_SQL_TempTable[]
   */
  protected $temporaryTables = [];

  /**
   * The name of the CMS database.
   *
   * @var string
   */
  protected $cms_prefix = '';

  protected $job_id;

  /**
   * EoySummary constructor.
   *
   * @param array $options
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($options = []) {
    $this->year = variable_get('wmf_eoy_target_year', NULL);
    $this->batch = variable_get('wmf_eoy_batch', 100);

    foreach (self::$option_keys as $key) {
      if (array_key_exists($key, $options)) {
        $this->$key = $options[$key];
      }
    }

    $this->from_address = variable_get('wmf_eoy_from_address', NULL);
    $this->from_name = variable_get('wmf_eoy_from_name', NULL);

    $this->cms_prefix = $this->getCMSDatabaseName();

    self::$templates_dir = __DIR__ . '/templates';
    self::$template_name = 'eoy_thank_you';
  }

  /**
   * Get the name of the CMS Database.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getCMSDatabaseName(): string {
    $url = str_replace('?new_link=true', '', CIVICRM_UF_DSN);
    if (!preg_match('/^([a-z]+):\/\/([^:]+):([^@]+)@([^\/:]+)(:([0-9]+))?\/(.+)$/', $url, $matches)) {
      throw new \CRM_Core_Exception("Failed to parse dbi url: $url");
    }
    return $matches[7];
  }

  /**
   * FIXME rename
   *
   * @return int|false the job ID for use in scheduling email sends
   * @throws \Exception
   */
  public function calculate_year_totals() {
    // Do the date calculations in Hawaii time, so that people trying to
    // get in under the wire are credited on the earlier date, following
    // similar logic in our standard thank you mailer.
    $next_year = $this->year + 1;
    $year_start = "{$this->year}-01-01 10:00:00";
    $year_end = "{$next_year}-01-01 09:59:59";

    $this->create_tmp_email_recipients_table();
    $num_emails = $this->populate_tmp_email_recipients_table($year_start, $year_end);

    // if no email addresses exist for the period lets jump out here
    if($num_emails === 0 ) {
      watchdog('wmf_eoy_receipt',
        t('Calculated summaries for !num donors giving during !year',
          [
            '!num' => 0,
            '!year' => $this->year,
          ]
        )
      );

      return false;
    }

    $this->create_tmp_contact_contributions_table();
    $this->populate_tmp_contact_contributions_table($year_start, $year_end);


    $job_timestamp = date("YmdHis");
    $this->create_send_letters_job($job_timestamp);

    $this->populate_donor_recipients_table();

    watchdog('wmf_eoy_receipt',
      t('Calculated summaries for !num donors giving during !year',
        [
          '!num' => $num_emails,
          '!year' => $this->year,
        ]
      )
    );

    return $this->job_id;
  }

  public function send_letters() {
    $mailer = Mailer::getDefault();

    $sql = <<<EOS
SELECT *
FROM {wmf_eoy_receipt_donor}
WHERE
    status = 'queued'
    AND job_id = :id
LIMIT {$this->batch}
EOS;
    $result = db_query($sql, [':id' => $this->job_id]);
    $succeeded = 0;
    $failed = 0;

    foreach ($result as $row) {
      $contactIds = $this->getContactIdsForEmail($row->email);
      $hasActiveRecurring = $this->doContactsHaveActiveRecurring($contactIds);
      $email = $this->render_letter($row, $hasActiveRecurring);

      try {
        $success = $mailer->send($email);
      } catch (\phpmailerException $e) {
        // Invalid email address or something
        watchdog('wmf_eoy_receipt', $e->getMessage(), [], WATCHDOG_INFO);
        $success = FALSE;
      }

      if ($success) {
        $this->record_activities($email, $contactIds);
        $status = 'sent';
        $succeeded += 1;
      }
      else {
        $status = 'failed';
        $failed += 1;
      }

      db_update('wmf_eoy_receipt_donor')->fields([
        'status' => $status,
      ])->condition('email', $row->email)->execute();
    }

    watchdog('wmf_eoy_receipt',
      t('Successfully sent !succeeded messages, failed to send !failed messages.',
        [
          '!succeeded' => $succeeded,
          '!failed' => $failed,
        ]
      )
    );
  }

  public function render_letter($row, $activeRecurring) {
    if (!$this->from_address || !$this->from_name) {
      throw new \Exception('Must configure a valid return address in the Thank-you module');
    }
    $language = Translation::normalize_language_code($row->preferred_language);
    $totals = [];
    $contributions = [];
    foreach (explode(',', $row->contributions_rollup) as $contribution_string) {
      $terms = explode(' ', $contribution_string);
      $contribution = [
        'date' => $terms[0],
        // FIXME not every currency uses 2 sig digs
        'amount' => round($terms[1], 2),
        'currency' => $terms[2],
      ];
      $contributions[] = $contribution;
      if (!isset($totals[$contribution['currency']])) {
        $totals[$contribution['currency']] = [
          'amount' => 0.0,
          'currency' => $contribution['currency'],
        ];
      }
      $totals[$contribution['currency']]['amount'] += $contribution['amount'];
    }
    // Sort contributions by date
    usort($contributions, function ($c1, $c2) {
      return $c1['date'] <=> $c2['date'];
    });
    foreach ($contributions as $index => $contribution) {
      $contributions[$index]['index'] = $index + 1;
    }

    $template_params = [
      'name' => $row->name,
      'contributions' => $contributions,
      'totals' => $totals,
      'year' => $this->year,
      'active_recurring' => $activeRecurring
    ];
    $template = $this->get_template($language, $template_params);
    $email = [
      'from_name' => $this->from_name,
      'from_address' => $this->from_address,
      'to_name' => $row->name,
      'to_address' => $row->email,
      'subject' => trim($template->render('subject')),
      'html' => str_replace('<p></p>', '', $template->render('html')),
    ];

    return $email;
  }

  protected function create_send_letters_job($timestamp) {
    db_insert('wmf_eoy_receipt_job')->fields([
      'start_time' => $timestamp,
      'year' => $this->year,
    ])->execute();

    $sql = <<<EOS
SELECT job_id FROM {wmf_eoy_receipt_job}
    WHERE start_time = :start
EOS;
    $result = db_query($sql, [':start' => $timestamp]);
    $row = $result->fetch();
    $this->job_id = $row->job_id;
  }

  /**
   * Create the table to store the emails of contacts to receive summaries.
   */
  protected function create_tmp_email_recipients_table() {
    $this->temporaryTables['email_recipients'] = \CRM_Utils_SQL_TempTable::build()->setAutodrop()->createWithColumns(
      'email VARCHAR(254) PRIMARY KEY'
    );
  }

  /**
   * Create a temporary details for more detailed contact information.
   */
  protected function create_tmp_contact_contributions_table() {
    $this->temporaryTables['contact_details'] = \CRM_Utils_SQL_TempTable::build()->setAutodrop()->createWithColumns(
      '    contact_id INT(10) unsigned PRIMARY KEY,
      email VARCHAR(254),
      preferred_language VARCHAR(16),
      name VARCHAR(255),
      contact_contributions TEXT'
    );
  }

  /**
   * Identify the list of emails we want to send a receipt out to.
   * If a contact id is set we will use the email for that contact only
   * @param $year_start
   * @param $year_end
   *
   * @return int
   */
  protected function populate_tmp_email_recipients_table($year_start, $year_end) {
    $endowmentFinancialType = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift'
    );
    $completedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );

    $contact_filter_sql = '';
    $recur_filter_sql = '';
    if ($this->contact_id != NULL) {
      // add filter for single contact_id if passed and remove recurring-only filter
      $contact_filter_sql = "AND contribution.contact_id =  $this->contact_id";
    }
    else {
      // we want to pull in _ALL_ recurring donors for the period
      $recur_filter_sql = "AND contribution_recur_id IS NOT NULL";
    }

    $emailTableName = $this->getTemporaryTableNameForEmailRecipients();

    $email_insert_sql = <<<EOS
INSERT INTO $emailTableName
SELECT email
FROM civicrm_contribution contribution
JOIN civicrm_email email
  ON email.contact_id = contribution.contact_id
  AND email.is_primary
WHERE receive_date BETWEEN '{$year_start}' AND '{$year_end}'
  AND financial_type_id <> $endowmentFinancialType
  AND contribution_status_id = $completedStatusId
  AND email <> 'nobody@wikimedia.org'
  $recur_filter_sql
  $contact_filter_sql
ON DUPLICATE KEY UPDATE email = email.email
EOS;
    \CRM_Core_DAO::executeQuery($email_insert_sql);
    $num_emails = \CRM_Core_DAO::singleValueQuery("SELECT count(*) FROM $emailTableName");

    watchdog('wmf_eoy_receipt',
      t('Found !num distinct emails with donations during !year',
        [
          '!num' => $num_emails,
          '!year' => $this->year,
        ]
      )
    );

    return (int) $num_emails;
  }

  /**
   * Populate data into the temporary table to prepare for mailings.
   *
   * @param string $year_start
   * @param string $year_end
   */
  protected function populate_tmp_contact_contributions_table($year_start, $year_end) {
    $endowmentFinancialType = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift'
    );
    $completedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );

    $contactSummaryTable = $this->getTemporaryTableNameForContactSummary();
    $emailTableName = $this->getTemporaryTableNameForEmailRecipients();

    \CRM_Core_DAO::executeQuery('SET session group_concat_max_len = 5000');
    // Build a table of contribution and contact data, grouped by contact
    $contact_insert_sql = <<<EOS
INSERT INTO $contactSummaryTable
SELECT
    contact.id,
    email.email,
    contact.preferred_language,
    contact.first_name,
    GROUP_CONCAT(CONCAT(
        -- Calculate dates in Hawaii timezone (UTC-10) as per standard TY email logic
        DATE_FORMAT(DATE_SUB(contribution.receive_date, INTERVAL 10 HOUR), '%Y-%m-%d'),
        ' ',
        COALESCE(original_amount, total_amount),
        ' ',
        COALESCE(original_currency, currency)
    ))
FROM $emailTableName eoy_email
JOIN civicrm_email email

    ON email.email = eoy_email.email AND email.is_primary
JOIN civicrm_contact contact
    ON email.contact_id = contact.id
JOIN civicrm_contribution contribution
    ON email.contact_id = contribution.contact_id AND email.is_primary
LEFT JOIN wmf_contribution_extra extra
    ON extra.entity_id = contribution.id
WHERE receive_date BETWEEN '{$year_start}' AND '{$year_end}'
    AND financial_type_id <> $endowmentFinancialType
    AND contribution_status_id = $completedStatusId
    AND contact.is_deleted = 0
GROUP BY email.email, contact.id, contact.preferred_language, contact.first_name
EOS;

    \CRM_Core_DAO::executeQuery($contact_insert_sql);
    $num_contacts = \CRM_Core_DAO::singleValueQuery("SELECT count(*) FROM $contactSummaryTable");

    watchdog('wmf_eoy_receipt',
      t('Found !num contact records for emails with donations during !year',
        [
          '!num' => $num_contacts,
          '!year' => $this->year,
        ]
      )
    );
  }

  /**
   * Insert data into the persistent table.
   *
   * We start inserting from the most recent contact ID so in case of two records sharing an email address, we
   * use the more recent name and preferred language.
   */
  protected function populate_donor_recipients_table() {
    $contactSummaryTable = $this->getTemporaryTableNameForContactSummary();
    $donor_recipients_insert_sql = <<<EOS
INSERT INTO {$this->cms_prefix}.wmf_eoy_receipt_donor
  (job_id, email, preferred_language, name, status, contributions_rollup)
SELECT
    {$this->job_id} AS job_id,
    email,
    preferred_language,
    name,
    'queued',
    contact_contributions
FROM $contactSummaryTable
ORDER BY contact_id DESC
ON DUPLICATE KEY UPDATE contributions_rollup = CONCAT(
    contributions_rollup, ',', contact_contributions
)
EOS;
    \CRM_Core_DAO::executeQuery($donor_recipients_insert_sql);
  }

  protected function get_template($language, $template_params) {
    return new Templating(
      self::$templates_dir,
      self::$template_name,
      $language,
      $template_params + ['language' => $language]
    );
  }

  protected function record_activities(array $email, array $contactIds) {
    foreach ($contactIds as $contactId) {
      civicrm_api3('Activity', 'create', [
        'activity_type_id' => 'wmf_eoy_receipt_sent',
        'source_contact_id' => $contactId,
        'target_contact_id' => $contactId,
        'assignee_contact_id' => $contactId,
        'subject' => "Sent contribution summary receipt for year $this->year to {$email['to_address']}",
        'details' => $email['html'],
      ]);
    }
  }

  /**
   * Get the string for the temporary table with summarised information ready to insert in the mails.
   *
   * The intention is to swap this over to use the CiviCRM query class to take advantage of the temp table helper
   * and in recognition of our intention to end usage of db_switcher & migrate this Civi integration (over time)
   * to the next extension.
   *
   * @return string
   */
  protected function getTemporaryTableNameForContactSummary(): string {
    return $this->temporaryTables['contact_details']->getName();
  }

  /**
   * Get the string for the temporary table with a list of emails to send to.
   *
   * The intention is to swap this over to use the CiviCRM query class to take advantage of the temp table helper
   * and in recognition of our intention to end usage of db_switcher & migrate this Civi integration (over time)
   * to the next extension.
   *
   * @return string
   */
  protected function getTemporaryTableNameForEmailRecipients(): string {
    return $this->temporaryTables['email_recipients']->getName();
  }

  /**
   * Get contact IDs associated with an email address
   *
   * @param string $email
   *
   * @return int[] IDs of non-deleted contacts with that email
   * @throws \CiviCRM_API3_Exception
   */
  protected function getContactIdsForEmail($email): array {
    $contactIds = [];
    $emailRecords = civicrm_api3('Email', 'get', [
      'email' => $email,
      'is_primary' => TRUE,
      'contact_id.is_deleted' => FALSE,
      'return' => 'contact_id',
    ]);
    foreach ($emailRecords['values'] as $emailRecord) {
      $contactIds[] = $emailRecord['contact_id'];
    }
    return $contactIds;
  }

  /**
   * Determine whether any of an array of contact IDs have an active recurring
   * donation associated.
   *
   * @param array $contactIds
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function doContactsHaveActiveRecurring(array $contactIds) {
    if (empty($contactIds)) {
      return FALSE;
    }
    $recurringCount = civicrm_api3('ContributionRecur', 'getCount', [
      'contact_id' => [
        'IN' => $contactIds
      ],
      'contribution_status_id' => [
        'IN' => ['Completed', 'Pending']
      ],
    ]);
    return $recurringCount > 0;
  }
}
