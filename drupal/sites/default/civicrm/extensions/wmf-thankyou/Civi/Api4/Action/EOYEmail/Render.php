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
 * @method (int) getContactID() Get the contact id.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method (int) getYear() Get the year.
 * @method $this setYear(int $year) Set the year.
 * @method (int) getLimit() Get the limit.
 * @method $this setLimit(int $limit) Set limit.
 *
 */
class Render extends AbstractAction {

  /**
   * Contact ID.
   *
   * Required at this stage. The intent is to migrate
   * all functionality over to this extension / apis
   * at which point it will not be just one.
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
  protected $limit = 1;


  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function _run(Result $result) {
    $donors = new EoySummary([
      'year' => $this->getYear(),
      'batch' => $this->getLimit(),
      'contact_id' => $this->getContactID(),
    ]);
    $job_id = $donors->calculate_year_totals();
    $sql = <<<EOS
SELECT *
FROM {wmf_eoy_receipt_donor}
WHERE
    status = 'queued'
    AND job_id = :id
LIMIT 1
EOS;
    $queryResult = db_query($sql, [':id' => $job_id]);
    foreach ($queryResult as $row) {
      $result[$this->getContactID()] = $donors->render_letter($row, FALSE);
    }
  }

}
