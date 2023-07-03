<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */
namespace Civi\Api4\Action\WMFDonor;

use Civi\API\Exception\UnauthorizedException;
use Civi\API\Request;
use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\Generic\DAOUpdateAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Query\Api4SelectQuery;
use Civi\Api4\Utils\CoreUtil;
use Civi\WMFHooks\CalculatedData;

/**
 * Update WMF Donor fields based on calculated data.
 *
 * Note that 'values' in this case is an array where the fields are the keys.
 * ie either
 *   ['donor_segment_id' => TRUE, 'donor_status_id' => TRUE....]
 *   or
 *   ['*' => TRUE]
 *
 * Values is required for the action we are inheriting so we are misusing it
 * rather than figuring out how to make it go away & adding a different property.
 */
class Update extends DAOUpdateAction {
  use SelectTrait;

  /**
   * The parent function would return an array of items.
   *
   * We are handling in `updateRecords` instead & don't want to
   * load items into php.
   *
   * @inheritDoc
   */
  public function getBatchRecords(): array {
    return [];
  }

  /**
   * @param array $items
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function updateRecords(array $items): array {
    $calculatedData = new CalculatedData();
    if (count($items) === 1) {
      $item = reset($items);
      $calculatedData->setWhereClause('contact_id = ' . (int) $item['id']);
    }
    else {
      $calculatedData->setWhereClause($this->getTemporaryTableSelectClause());
    }
    if (!array_key_exists('*', $this->values)) {
      $calculatedData->filterDonorFields(array_keys($this->values));
    }
    $calculatedData->updateWMFDonorData();
    return $items;
  }

}
