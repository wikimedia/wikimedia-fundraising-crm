<?php


namespace Civi\Api4\Action\EOYEmail;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_Contribute_PseudoConstant;
use CRM_Core_DAO;
use CRM_Core_PseudoConstant;
use CRM_Utils_SQL_TempTable;

/**
 * Class MakeJob.
 *
 * The creates the job entry for the end of year email.
 *
 * The job consists of
 * 1) a row in wmf_donor_job to identify the job and
 * 2) rows in wmf_eoy_receipt_donor for all the emails in the job with the
 *  status of 'queued'. The job mechanism appears to be an idea at being able
 *  to track multiple jobs but in practice it is pretty clunky.
 *
 *
 *
 * @method $this setYear(int $year) Set the year
 * @method int getContactID Get the contact id to limit it to (optional).
 * @method $this setContactID(int $contactID) Set contact id to limit to (optional).
 */
class MakeJob extends AbstractAction {

  /**
   * Year.
   *
   * Year defaults to last year if not set.
   *
   * @var int
   */
  protected $year;

  /**
   * Get the year, defaulting to last year.
   *
   * @return int
   */
  protected function getYear(): int {
    return $this->year ?? (date('Y') - 1);
  }

  /**
   * Contact ID.
   *
   * Optional filter to limit to 1 contact id.
   *
   * @var int
   */
  protected $contactID;

  /**
   * ID of the created job.
   *
   * @var int
   */
  protected $jobID;

  /**
   * @var array
   */
  protected $temporaryTables = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   */
  public function _run(Result $result): void {
    // Do the date calculations in Hawaii time, so that people trying to
    // get in under the wire are credited on the earlier date, following
    // similar logic in our standard thank you mailer.
    $year_start = $this->getYear() . '-01-01 10:00:00';
    $year_end = ($this->getYear() + 1) . '-01-01 09:59:59';

    $this->create_tmp_email_recipients_table();
    $num_emails = $this->populate_tmp_email_recipients_table($year_start, $year_end);

    // if no email addresses exist for the period lets jump out here
    if($num_emails === 0 ) {
      Civi::log('wmf')->info('eoy_receipt - No summaries calculated for giving during {year}', [
        'year' => $this->year,
      ]);
      return;
    }

    $this->create_tmp_contact_contributions_table();
    $this->populate_tmp_contact_contributions_table($year_start, $year_end);

    $this->populate_donor_recipients_table();

    Civi::log('wmf')->info('eoy_receipt - {count} summaries calculated for giving during {year}', [
      'year' => $this->year,
      'count' => $num_emails,
    ]);

    $result[] = ['job_id' => $this->jobID];
  }

  /**
   * Create the table to store the emails of contacts to receive summaries.
   */
  protected function create_tmp_email_recipients_table(): void {
    $this->temporaryTables['email_recipients'] = CRM_Utils_SQL_TempTable::build()->setAutodrop()->createWithColumns(
      'email VARCHAR(254) PRIMARY KEY'
    );
  }

  /**
   * Create a temporary details for more detailed contact information.
   */
  protected function create_tmp_contact_contributions_table(): void {
    $this->temporaryTables['contact_details'] = CRM_Utils_SQL_TempTable::build()->setAutodrop()->createWithColumns(
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
  protected function populate_tmp_email_recipients_table($year_start, $year_end): int {
    $endowmentFinancialType = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift'
    );
    $completedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );

    $contact_filter_sql = '';
    $recur_filter_sql = '';
    if ($this->getContactID()) {
      // add filter for single contact_id if passed and remove recurring-only filter
      $contact_filter_sql = "AND contribution.contact_id = " . $this->getContactID();
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
  AND contribution_status_id = $completedStatusId
  AND email <> 'nobody@wikimedia.org'
  $recur_filter_sql
  $contact_filter_sql
ON DUPLICATE KEY UPDATE email = email.email
EOS;
    CRM_Core_DAO::executeQuery($email_insert_sql);
    $num_emails = CRM_Core_DAO::singleValueQuery("SELECT count(*) FROM $emailTableName");

    Civi::log('wmf')->info('wmf_eoy_receipt Found {count} distinct emails with donations during {year}',
      [
        'count' => $num_emails,
        'year' => $this->year,
      ]
    );

    return (int) $num_emails;
  }

  /**
   * Populate data into the temporary table to prepare for mailings.
   *
   * @param string $year_start
   * @param string $year_end
   */
  protected function populate_tmp_contact_contributions_table(string $year_start, $year_end): void {
    $completedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );

    $contactSummaryTable = $this->getTemporaryTableNameForContactSummary();
    $emailTableName = $this->getTemporaryTableNameForEmailRecipients();

    CRM_Core_DAO::executeQuery('SET session group_concat_max_len = 5000');
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
    ) ORDER BY receive_date)
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
    AND contribution_status_id = $completedStatusId
    AND contact.is_deleted = 0
GROUP BY email.email, contact.id, contact.preferred_language, contact.first_name
EOS;

    CRM_Core_DAO::executeQuery($contact_insert_sql);
    $num_contacts = CRM_Core_DAO::singleValueQuery("SELECT count(*) FROM $contactSummaryTable");

    Civi::log('wmf')->info('wmf_eoy_receipt - Found {count} contact records for emails with donations during {year}', [
      'count' => $num_contacts,
      'year' => $this->year,
    ]);
  }

  /**
   * Insert data into the persistent table.
   *
   * We start inserting from the most recent contact ID so in case of two records sharing an email address, we
   * use the more recent name and preferred language.
   */
  protected function populate_donor_recipients_table(): void {
    $contactSummaryTable = $this->getTemporaryTableNameForContactSummary();
    $donor_recipients_insert_sql = "
INSERT INTO wmf_eoy_receipt_donor
  (year, email, status)
SELECT DISTINCT
    " . $this->getYear() . " as year,
    email,
    'queued'
FROM $contactSummaryTable
ORDER BY contact_id DESC
";
    CRM_Core_DAO::executeQuery($donor_recipients_insert_sql);
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

}
