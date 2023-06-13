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

use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Query\Api4SelectQuery;
use Civi\WMFHooks\CalculatedData;

/**
 * The WMF Donor class gets calculated WMF Donor fields.
 *
 * This is useful for checking the calculations used and running updates
 * when the calculated data changes.
 *
 * It does not query the actual WMF Donor fields.
 */
class Get extends DAOGetAction {
  /**
   * @return string
   */
  public function getEntityName(): string {
    return 'Contact';
  }


  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Civi\Core\Exception\DBQueryException|\CRM_Core_Exception
   */
  protected function getObjects(Result $result): void {
    $sqlQuery = \CRM_Core_DAO::executeQuery($this->getSQL());
    $rows = [];
    while ($sqlQuery->fetch()) {
      /** @noinspection PhpPossiblePolymorphicInvocationInspection */
      $row = ['id' => $sqlQuery->entity_id];
      foreach ($this->select as $donorField) {
        if (isset($sqlQuery->$donorField)) {
          $row[$donorField] = $sqlQuery->$donorField;
        }
      }
      $rows[] = $row;
    }

    $result->exchangeArray($rows);
  }

  /**
   * Get the sql to retrieve the WMF donor field.
   *
   * We are mimic-ing the Contact entity to get the contact.get
   * sql clause, which we use to inject into the WMFDonor clause.
   *
   * Not too sure if it will extend nicely to more complex stuff or
   * if we want it to - but it is tested so we can tinker if we want to
   * iterate.   $b = 1;
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getSQL(): string {
    // Clear out this limit so it is not used in the subquery cos MariaDB
    // does not support that - we can try later to add it back to the query if we
    // want & it performs
    $this->limit = 0;
    // Alter this select to avoid getting all the fields in the contact selection.
    $select = $this->select;
    $this->select = ['id'];
    $query = new Api4SelectQuery($this);
    $sql = $query->getSql();
    $this->select = $select;
    $calculatedData = new CalculatedData();
    $calculatedData->setTriggerContext(FALSE)
      ->setWhereClause('contact_id IN (' . $sql . ')');
    $sql = $calculatedData->getSelectSQL();
    $this->_debugOutput['sql'] = $sql;
    \Civi::log('wmf')->info($sql);
    return $sql;
  }

}
