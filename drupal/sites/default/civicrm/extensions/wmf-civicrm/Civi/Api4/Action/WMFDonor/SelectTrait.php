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

use Civi\API\Request;
use Civi\Api4\Query\Api4SelectQuery;

trait SelectTrait {

  /**
   * The name of the temporary table crated to track selected contacts.
   *
   * It is important to use a temporary table rather than the contact table
   * for joins to avoid deadlocks and to avoid a self-referencing query
   * when the trigger updates the modified_date.
   *
   * @var string
   */
  protected $temporaryTableName;

  /**
   * This entity name is for the WHERE clause & joins, but not the select.
   *
   * @return string
   */
  public function getEntityName(): string {
    return 'Contact';
  }

  /**
   * Create a temp table from our where criteria and return as an sql subquery.
   *
   * Our where criteria relate to the contact entity so this we load that to
   * create the where to build our temp table.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getTemporaryTableSelectClause(): string {
    return 'c.contact_id IN (SELECT id FROM ' . $this->getTemporaryTableName() . ')';
  }

  /**
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getTemporaryTableName(): string {
    if (!isset($this->temporaryTableName)) {
      /* @var \Civi\Api4\Generic\DAOGetAction $getAction */
      $getAction = Request::create('Contact', 'get', [
        'version' => 4,
        'select' => ['id'],
        'where' => $this->getWhere(),
        'limit' => $this->getLimit(),
        'offset' => $this->getOffset(),
        'orderBy' => $this->getOrderBy(),
        'checkPermissions' => $this->getCheckPermissions(),
      ]);
      $query = new Api4SelectQuery($getAction);
      $sql = $query->getSql();
      $this->temporaryTableName = \CRM_Utils_SQL_TempTable::build()
        ->createWithQuery($sql)
        ->getName();
    }
    return $this->temporaryTableName;
  }

}
