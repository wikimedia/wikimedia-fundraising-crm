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
    $timestamp = time();

    $num_emails = $this->populateDonorEmailsTable($year_start, $year_end);

    // if no email addresses exist for the period lets jump out here
    if($num_emails === 0 ) {
      Civi::log('wmf')->info('eoy_receipt - No summaries calculated for giving during {year}', [
        'year' => $this->year,
      ]);
      return;
    }

    Civi::log('wmf')->info('eoy_receipt - {count} summaries calculated for giving during {year}', [
      'year' => $this->year,
      'count' => $num_emails,
    ]);

    $result[] = ['donor_count' => $num_emails, 'seconds_taken' => (time() - $timestamp)];
  }

  /**
   * Identify the list of emails we want to send a receipt out to.
   * If a contact id is set we will use the email for that contact only
   * @param $year_start
   * @param $year_end
   *
   * @return int
   */
  protected function populateDonorEmailsTable($year_start, $year_end): int {
    $initialEmailCount = (int) CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM wmf_eoy_receipt_donor WHERE year = ' . $this->getYear());
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

    $email_insert_sql = "
INSERT INTO wmf_eoy_receipt_donor (year, email, status)
SELECT DISTINCT " . $this->getYear() . ", email, 'queued'
FROM civicrm_contribution contribution
INNER JOIN civicrm_email email
  ON email.contact_id = contribution.contact_id
  AND email.is_primary
INNER JOIN civicrm_contact contact ON contact.id = email.contact_id
  AND contact.is_deleted = 0
WHERE receive_date BETWEEN '{$year_start}' AND '{$year_end}'
  AND contribution_status_id = $completedStatusId
  AND email <> 'nobody@wikimedia.org'
  $recur_filter_sql
  $contact_filter_sql
";
    CRM_Core_DAO::executeQuery($email_insert_sql);
    $num_emails = (int) CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM wmf_eoy_receipt_donor WHERE year = ' . $this->getYear()) - $initialEmailCount;

    Civi::log('wmf')->info('wmf_eoy_receipt Found {count} distinct emails with donations during {year}',
      [
        'count' => $num_emails,
        'year' => $this->year,
      ]
    );

    return (int) $num_emails;
  }

}
