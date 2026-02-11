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
use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Query\Api4SelectQuery;
use Civi\WMFHook\CalculatedData;

/**
 * The WMF Donor class gets calculated WMF Donor fields.
 *
 * This is useful for checking the calculations used and running updates
 * when the calculated data changes.
 *
 * It does not query the actual WMF Donor fields.
 */
class Get extends DAOGetAction {

  use SelectTrait;

  /**
   * Is this api call in the select phase of processing.
   *
   * Used to alter entityFields() behaviour.
   *
   * @var bool
   */
  private $isSelectPhase = FALSE;

  /**
   * Adds all standard fields matched by the * wildcard
   *
   * We override this to filter the select to wmf_donor fields (since
   * we are extending contact it would otherwise grab fields from the contact
   * entity. The reason we are overriding contact is it hopefully allows us
   * to piggy-back off it's where options. But to do that for select but not
   * where means we need some intervention as to what fields are available here.
   *
   * @throws \CRM_Core_Exception
   */
  public function expandSelectClauseWildcards(): void {
    $this->isSelectPhase = TRUE;
    parent::expandSelectClauseWildcards();
    $this->isSelectPhase = FALSE;
  }

  /**
   * @param string|null $entityName
   * @param string|null $actionName
   */
  public function entityFields(?string $entityName = null, ?string $actionName = null): array {
    if (!$this->isSelectPhase) {
      return parent::entityFields($entityName, $actionName);
    }
    $getFields = Request::create('WMFDonor', 'getFields', [
      'version' => 4,
      'checkPermissions' => FALSE,
      'action' => $this->getActionName(),
      // Hmm maybe I could leverage the field type instead of overriding the function.
      // But, if this works I may lose the will to dig deeper & if it doesn't
      // I may lose the will to live.
      'where' => [['type', 'IN', ['Field', 'Filter', 'Extra']]],
    ]);
    $result = new Result();
    // Pass TRUE for the private $isInternal param
    $getFields->_run($result, TRUE);
    return (array) $result->indexBy('name');
  }

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Civi\Core\Exception\DBQueryException|\CRM_Core_Exception
   */
  protected function getObjects(Result $result): void {
    $sqlQuery = \CRM_Core_DAO::executeQuery($this->getSQL());
    $rows = [];
    $calculatedData = new CalculatedData();
    while ($sqlQuery->fetch()) {
      /** @noinspection PhpPossiblePolymorphicInvocationInspection */
      $row = ['id' => $sqlQuery->entity_id];
      foreach ($this->select as $selectedField) {
        $fieldSplit = explode(':', $selectedField);
        $donorField = $fieldSplit[0];
        if (isset($sqlQuery->$donorField)) {
          $row[$donorField] = $sqlQuery->$donorField;
          // Translating the :label & :description & :name honors the civicrm style
          // but it's kinda just hacked in. Given it is just a couple of fields for
          // a narrow use case that feels OK.
          if (($fieldSplit[1] ?? NULL) === 'label') {
            $row[$selectedField] = $calculatedData->getFieldLabel($donorField, $row[$donorField]);
          }
          if (($fieldSplit[1] ?? NULL) === 'description') {
            $row[$selectedField] = $calculatedData->getFieldDescription($donorField, $row[$donorField]);
          }
          if (($fieldSplit[1] ?? NULL) === 'name') {
            $row[$selectedField] = $calculatedData->getFieldName($donorField, $row[$donorField]);
          }
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
    $calculatedData = new CalculatedData();
    $calculatedData->setTriggerContext(FALSE)
      ->setWhereClause($this->getTemporaryTableSelectClause());

    if (!empty($this->select)) {
      $selectFields = [];
      foreach ($this->select as $selectField) {
        $fieldSplit = explode(':', $selectField);
        // Handle donor_segment_id:label
        $selectFields[$fieldSplit[0]] = TRUE;
      }
      // If we have specified the fields then filter to only select those fields.
      $calculatedData->filterDonorFields(array_keys($selectFields));
    }
    $sql = $calculatedData->getSelectSQL();
    $this->_debugOutput['sql'] = $sql;
    \Civi::log('wmf')->info($sql);
    return $sql;
  }

}
