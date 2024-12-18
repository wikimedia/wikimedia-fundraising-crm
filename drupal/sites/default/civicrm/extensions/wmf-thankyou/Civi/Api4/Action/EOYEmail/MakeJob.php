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
 * The job consists of rows in wmf_eoy_receipt_donor for all the emails in the
 * job with the status of 'queued' and year set to the relevant year.
 * Note that each email & year is a unique entry in the table and if run multiple
 * times it will not add entries that are already present.
 *
 * @method $this setYear(int $year) Set the year
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

    Civi::log('wmf')->info('eoy_receipt - {count} summaries calculated for giving during {year}', [
      'year' => $this->year,
      'count' => $num_emails,
    ]);

    $result[] = ['donor_count' => $num_emails, 'seconds_taken' => (time() - $timestamp)];
  }

  /**
   * Identify the list of emails we want to send a receipt out to for the specified year
   * .
   * @param string $year_start
   * @param string $year_end
   *
   * @return int
   */
  protected function populateDonorEmailsTable(string $year_start, string $year_end): int {
    $initialEmailCount = (int) CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM wmf_eoy_receipt_donor WHERE year = ' . $this->getYear());
    $completedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );

    $email_insert_sql = "
INSERT INTO wmf_eoy_receipt_donor (year, email, status)
SELECT DISTINCT " . $this->getYear() . ", email.email, 'queued'
 FROM civicrm_contribution_recur contribution_recur
   INNER JOIN civicrm_contribution contribution
     ON contribution.contribution_recur_id = contribution_recur.id
INNER JOIN civicrm_email email
  ON email.contact_id = contribution.contact_id
  AND email.is_primary
INNER JOIN civicrm_contact contact ON contact.id = email.contact_id
  AND contact.is_deleted = 0
LEFT JOIN wmf_eoy_receipt_donor eoy ON email.email = eoy.email AND eoy.year = " . $this->getYear() ."
WHERE receive_date BETWEEN '{$year_start}' AND '{$year_end}'
  AND contribution.contribution_status_id = $completedStatusId
  AND eoy.email IS NULL
-- We exclude annual recurring contributions when deciding WHO to email.
-- if they have an annual recurring AND a monthly then both (all) donations
-- will still be included in the WHAT to email.
  AND contribution_recur.frequency_unit != 'year'
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
