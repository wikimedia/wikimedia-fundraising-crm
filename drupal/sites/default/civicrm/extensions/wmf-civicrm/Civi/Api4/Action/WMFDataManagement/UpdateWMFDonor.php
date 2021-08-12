<?php

namespace Civi\Api4\Action\WMFDataManagement;

use API_Exception;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFHooks\CalculatedData;

/**
 * Fill WMF Donor.
 *
 * Fill any missed details from WMF donor.
 *
 * @method setMinimumReceiveDate(string $minimumReceiveDate) Set the earliest date to include contributions for.
 * @method getMinimumReceiveDate(): string Get the earliest date to include contributions for
 * @method setMaximumReceiveDate(string $maximumReceiveDate) Set the latest date to include contributions for.
 * @method getMaximumReceiveDate(): string Get the latest date to include contributions for

 *
 * @package Civi\Api4
 */
class UpdateWMFDonor extends AbstractAction {

  /**
   * @var string
   *
   * @noinspection PhpUnused
   */
  protected $minimumReceiveDate = '2021-07-01';

  /**
   * @var string
   *
   * @noinspection PhpUnused
   */
  protected $maximumReceiveDate = '';

  /**
   * Contact IDs to update.
   *
   * @var array
   */
  protected $ids = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $calculated = new CalculatedData();
    $calculated->setWhereClause($this->getWhere());
    $calculated->updateWMFDonorData();
    $result[] = ['message' => 'Updated rows: ' . count($this->ids)];
  }

  /**
   * Get a where clause to control the updates.
   *
   * For current purposes we only need a very simple where to get contacts with contributions
   * since 1 July 2021. In the past we have used contact ranges when we have needed to iterate
   * through all contacts (in conjunction with a check on the state of processing on the server
   * _drush_civicrm_queue_is_backed_up).
   *
   * For now this is enough and we can re-add the batching later if it becomes needed again.
   *
   * @return string
   * @throws \API_Exception
   */
  public function getWhere(): string {
    $api = Contribution::get(FALSE)
      ->addSelect('contact_id')
      ->addWhere('receive_date', '>', $this->getMinimumReceiveDate());
    if ($this->getMaximumReceiveDate()) {
      $api->addWhere('receive_date', '<', $this->getMaximumReceiveDate());
    }
    $this->ids = array_keys((array) $api->execute()->indexBy('contact_id'));
    if (empty($this->ids)) {
      throw new API_Exception('no contacts meet the criteria');
    }
    return ' contact_id IN (' . implode(',', $this->ids) . ') ';
  }
}
