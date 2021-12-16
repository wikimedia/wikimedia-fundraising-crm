<?php


namespace Civi\Api4\Action\EOYEmail;

use Civi;
use Civi\Api4\EOYEmail;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use wmf_communication\Translation;
use Civi\EoySummary;

/**
 * Class Render.
 *
 * Get the content of the failure email for the specified contributionRecur ID.
 *
 * @method int getContactID() Get the contact id.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method $this setYear(int $year) Set the year
 * @method int getLimit() Get the limit
 * @method $this setLimit(int $limit) Set the limit
 */
class Send extends AbstractAction {

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
   * If provided then only this contact ID will be emailed.
   *
   * @var int
   */
  protected $contactID;

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
   *
   * @throws \API_Exception
   */
  public function _run(Result $result): void {
    if (!$this->getContactID() && $this->isJobEmpty()) {
      throw new \API_Exception('All emails for year ' . $this->getYear() . ' have been sent');
    }
    $eoyClass = new EoySummary(['year' => $this->getYear(), 'contact_id' => $this->getContactID(), 'batch' => $this->getLimit()]);
    $eoyClass->send_letters();
  }

  /**
   * Is the planned job empty of emails to send to.
   *
   * @return bool
   */
  protected function isJobEmpty(): bool {
    return !\CRM_Core_DAO::singleValueQuery(
      'SELECT count(*) FROM wmf_eoy_receipt_donor WHERE year = ' . $this->getYear()
    );
  }

}
