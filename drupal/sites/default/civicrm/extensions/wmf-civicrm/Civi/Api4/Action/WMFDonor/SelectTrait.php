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
   * Our where criteria relate to the contact entity so this we load that to create the where
   * to build our temp table.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getTemporaryTableSelectClause(): string {
    //
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
    $tempTable = \CRM_Utils_SQL_TempTable::build()->createWithQuery($sql);
    return 'contact_id IN (SELECT id FROM ' . $tempTable->getName() . ')';
  }

}
