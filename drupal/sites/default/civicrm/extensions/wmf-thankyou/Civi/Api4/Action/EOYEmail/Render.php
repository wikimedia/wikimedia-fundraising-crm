<?php


namespace Civi\Api4\Action\EOYEmail;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use wmf_eoy_receipt\EoySummary;

/**
 * Class Render.
 *
 * Get the content of the failure email for the specified contributionRecur ID.
 *
 * @method int getContactID() Get the contact id.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method int getYear() Get the year
 * @method $this setYear(int $year) Set the year
 * @method int getLimit() Get the limit
 * @method $this setLimit(int $limit) Set the limit
 * @method int getJobID() Get the job ID.
 * @method $this setJobID(int $limit) Set job ID.
 */
class Render extends AbstractAction {

  /**
   * Contact ID.
   *
   * Optional, if provided not only recurring emails will be included.
   *
   * @var int
   */
  protected $contactID;

  /**
   * Year.
   *
   * Required.
   *
   * @var int
   */
  protected $year;

  /**
   * Limit.
   *
   * Currently 1 is the only possible number as contact
   * id is required.
   *
   * @var int
   */
  protected $limit = 100;

  /**
   * Required if contact ID is not present.
   *
   * @var int
   */
  protected $jobID;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    if ($this->getContactID()) {
      $donors = new EoySummary([
        'year' => $this->getYear(),
        'batch' => $this->getLimit(),
        'contact_id' => $this->getContactID(),
      ]);
      $this->setLimit(1);
      $this->setJobID($donors->calculate_year_totals());
    }
    if (!$this->jobID) {
      throw new \API_Exception('Job ID is required if contact ID not present');
    }

    $row = \CRM_Core_DAO::executeQuery("
      SELECT *
      FROM wmf_eoy_receipt_donor
      WHERE
      status = 'queued'
      AND job_id = %1
      LIMIT %2", [1 => [$this->getJobID(), 'Integer'], 2 => [$this->getLimit(), 'Integer']]);
    while ($row->fetch()) {
      $result[$this->getContactID()] = $donors->render_letter($row, FALSE);
    }
  }

}
