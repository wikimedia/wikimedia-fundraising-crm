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

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Batch;
use Civi\Api4\Query\Api4SelectQuery;
use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;
use Civi\Core\Service\AutoService;

/**
 * @service
 * @internal
 */
class FinanceBatchReferenceSpecProvider implements Generic\SpecProviderInterface {

  /**
   * @param \Civi\Api4\Service\Spec\RequestSpec $spec
   *
   * @throws \CRM_Core_Exception
   */
  public function modifySpec(RequestSpec $spec): void {
    // Add calculated batch fields. This allows us to provide a batch and have
    // the settlement values for that batch to be returned - be it in
    // batch_settlement_reference or batch_settlement_reversal reference.
    $field = new FieldSpec('finance_batch', 'Contribution', 'Text');
    $field->setLabel(ts('Finance Settlement Batch'))
      ->setTitle(ts('Finance Settlement Batch'))
      ->setDescription(ts('Finance Settlement Batch'))
      ->setDataType('Text')
      ->setInputType('Text')
      ->setName('finance_batch')
      ->setReadonly(TRUE)
      ->addSqlFilter([__CLASS__, 'getBatchSql'])
      ->setOptionsCallback([__CLASS__, 'getBatchList'])
      ->setSqlRenderer([__CLASS__, 'renderBatchAmountSql'])
      ->setColumnName('id');
    $spec->addFieldSpec($field);

    $field = new FieldSpec('settled_total_amount', $spec->getEntity(), 'Money');
    $field->setTitle('Settled total amount')
      ->setInputType('Text')
      ->setDataType('Money')
      ->setReadonly(TRUE)
      ->setSqlRenderer([__CLASS__, 'renderBatchAmountSql'])
      ->setName('settled_total_amount')
      ->setDescription('Total amount paid by donors in the given batch (donations less reversals)');
    $spec->addFieldSpec($field);

    $field = new FieldSpec('settled_net_amount', $spec->getEntity(), 'Money');
    $field->setTitle('Settled net amount')
      ->setReadonly(TRUE)
      ->setInputType('Text')
      ->setSqlRenderer([__CLASS__, 'renderBatchAmountSql'])
      ->setName('settled_net_amount')
      ->setDescription('Net amount payable to us in the given batch after fees');
    $spec->addFieldSpec($field);

    $field = new FieldSpec('settled_fee_amount', $spec->getEntity(), 'Money');
    $field->setTitle('Settled fee amount')
      ->setReadonly(TRUE)
      ->setInputType('Text')
      ->setSqlRenderer([__CLASS__, 'renderBatchAmountSql'])
      ->setName('settled_fee_amount')
      ->setColumnName('contribution_settlement.settled_fee_amount')
      ->setDescription('Fee amount in the given batch');
    $spec->addFieldSpec($field);
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool
   */
  public function applies(string $entity, string $action): bool {
    return $entity === 'Contribution' && $action === 'get';
  }

  public static function getBatchList($field, $values, $returnFormat, $checkPermissions) {
    $return = [];
    $batches = Batch::get(FALSE)
      ->addSelect('*', 'mode_id:name')
      ->addWhere('mode_id:name', '=', 'Automatic Batch')
      ->execute();
    foreach ($batches as $batch) {
      if ($returnFormat === TRUE) {
        $return[$batch['id']] = $batch['title'];
      }
      else {
        $returnBatch = [
          // Since this is not a real entity reference and the name rather than
          // id is in the field then use name as id.
          'id' => $batch['name'],
          'name' => $batch['name'],
          'label' => $batch['title'],
          'description' => $batch['description'],
        ];
        $return[] = array_intersect_key($returnBatch, array_fill_keys($returnFormat, ''));
      }
    }
    return $return;
  }

  /**
   *
   * @param array $field
   * @param string $fieldAlias
   * @param string $operator
   * @param mixed $value
   * @param \Civi\Api4\Query\Api4SelectQuery $query
   *
   * @return string
   */
  public static function getBatchSql(array $field, string $fieldAlias, string $operator, $value, Api4SelectQuery $query, int $depth): string {
    if ($field['name'] === 'finance_batch') {
      $batchClause = str_replace('xxx', '', self::createSQLClause('xxx', $operator, $value, $field));
      return "{$field['sql_name']}  IN (SELECT entity_id FROM civicrm_value_contribution_settlement WHERE settlement_batch_reference $batchClause OR settlement_batch_reversal_reference $batchClause )";
    }
    return '';
  }

  private static function getClause(string $operator, $value): string {
    if (in_array($operator, ['=', 'LIKE'])) {
      return $operator . " '{$value}'";
    }
    if ($operator === 'IN') {
      return 'IN ("' . implode('", "', $value) . '")';
    }
    return '';
  }

  public static function renderBatchAmountSql(array $field, Api4SelectQuery $query): string {
    $batchField = $query->getFieldSibling($field, 'contribution_settlement.settlement_batch_reference');
    $reversalBatchField = $query->getFieldSibling($field, 'contribution_settlement.settlement_batch_reversal_reference');
    $tableAlias = explode('.', $batchField['sql_name'])[0];
    foreach ($query->getWhere() as $whereClause) {
      $prefix = str_replace($field['name'], '', $field['path']);
      if ($whereClause[0] === 'finance_batch' || $whereClause[0] === ($prefix . 'finance_batch')) {
        $batchClause = str_replace('xxx', '', self::createSQLClause('xxx', $whereClause[1], $whereClause[2], $field));
      }
    }
    if (empty($batchClause)) {
      switch ($field['name']) {
        case 'settled_fee_amount' :
          return "COALESCE({$tableAlias}.settled_fee_amount, 0)
          + COALESCE({$tableAlias}.settled_fee_reversal_amount, 0)";

        case 'settled_net_amount' :
          return "COALESCE({$tableAlias}.settled_fee_amount, 0)
            + COALESCE({$tableAlias}.settled_fee_reversal_amount, 0)
            + COALESCE({$tableAlias}.settled_donation_amount, 0)
            + COALESCE({$tableAlias}.settled_reversal_amount, 0)";

        case 'settled_total_amount' :
          return "COALESCE({$tableAlias}.settled_donation_amount, 0)
           + COALESCE({$tableAlias}.settled_reversal_amount, 0)";

        case 'finance_batch' :
          return "
            CASE
              WHEN NULLIF({$batchField['sql_name']}, '') IS NULL AND NULLIF({$reversalBatchField['sql_name']}, '') IS NULL THEN ''
              WHEN NULLIF({$batchField['sql_name']}, '') IS NULL THEN CONCAT({$reversalBatchField['sql_name']}, '(R)')
              WHEN NULLIF({$reversalBatchField['sql_name']}, '') IS NULL THEN {$batchField['sql_name']}
              WHEN {$batchField['sql_name']} = {$reversalBatchField['sql_name']} THEN CONCAT({$batchField['sql_name']}, '(*2)')
              ELSE CONCAT_WS(', ', {$batchField['sql_name']}, CONCAT({$reversalBatchField['sql_name']}, '(R)'))
            END
            ";

        default:
          throw new \CRM_Core_Exception('not reachable');
      }
    }

    switch ($field['name']) {
      case 'settled_fee_amount' :
        return "COALESCE(IF({$batchField['sql_name']} $batchClause, {$tableAlias}.settled_fee_amount, 0), 0)
      + COALESCE(IF({$reversalBatchField['sql_name']} $batchClause, {$tableAlias}.settled_fee_reversal_amount, 0), 0)";

      case 'settled_net_amount' :
        return "COALESCE(IF({$batchField['sql_name']} $batchClause, {$tableAlias}.settled_fee_amount, 0), 0)
      + COALESCE(IF({$reversalBatchField['sql_name']} $batchClause, {$tableAlias}.settled_fee_reversal_amount, 0), 0)
      + COALESCE(IF({$batchField['sql_name']} $batchClause, {$tableAlias}.settled_donation_amount, 0), 0)
      + COALESCE(IF({$reversalBatchField['sql_name']} $batchClause, {$tableAlias}.settled_reversal_amount, 0), 0)";

      case 'settled_total_amount' :
        return "COALESCE(IF({$batchField['sql_name']} $batchClause, {$tableAlias}.settled_donation_amount, 0), 0)
      + COALESCE(IF({$reversalBatchField['sql_name']} $batchClause, {$tableAlias}.settled_reversal_amount, 0), 0)";

      case 'finance_batch' :
        return "CASE
          WHEN {$batchField['sql_name']} {$batchClause}
           AND {$reversalBatchField['sql_name']} {$batchClause}
           AND {$batchField['sql_name']} = {$reversalBatchField['sql_name']}
            THEN CONCAT({$batchField['sql_name']}, '(*2)')

          ELSE CONCAT_WS(
            ', ',
            IF({$batchField['sql_name']} {$batchClause} AND {$batchField['sql_name']} <> '', {$batchField['sql_name']}, NULL),
            IF({$reversalBatchField['sql_name']} {$batchClause} AND {$reversalBatchField['sql_name']} <> '', CONCAT({$reversalBatchField['sql_name']}, '(R)'), NULL)
          )
        END";

      default:
        throw new \CRM_Core_Exception('not reachable');
    }
  }

  /**
   * @param string $fieldAlias
   * @param string $operator
   * @param mixed $value
   * @param array|null $field
   * @return array|string|NULL
   * @throws \Exception
   */
  protected static function createSQLClause($fieldAlias, $operator, $value, $field) {
    $original_operator = $operator;

    // The CONTAINS and NOT CONTAINS operators match a substring for strings.
    // For arrays & serialized fields, they only match a complete (not partial) string within the array.
    if ($operator === 'CONTAINS' || $operator === 'NOT CONTAINS') {
      $sep = \CRM_Core_DAO::VALUE_SEPARATOR;
      switch ($field['serialize'] ?? NULL) {

        case \CRM_Core_DAO::SERIALIZE_JSON:
          $operator = ($operator === 'CONTAINS') ? 'LIKE' : 'NOT LIKE';
          $value = '%"' . $value . '"%';
          // FIXME: Use this instead of the above hack once MIN_INSTALL_MYSQL_VER is bumped to 5.7.
          // return sprintf('JSON_SEARCH(%s, "one", "%s") IS NOT NULL', $fieldAlias, \CRM_Core_DAO::escapeString($value));
          break;

        case \CRM_Core_DAO::SERIALIZE_SEPARATOR_BOOKEND:
          $operator = ($operator === 'CONTAINS') ? 'LIKE' : 'NOT LIKE';
          // This is easy to query because the string is always bookended by separators.
          $value = '%' . $sep . $value . $sep . '%';
          break;

        case \CRM_Core_DAO::SERIALIZE_SEPARATOR_TRIMMED:
          $operator = ($operator === 'CONTAINS') ? 'REGEXP' : 'NOT REGEXP';
          // This is harder to query because there's no bookend.
          // Use regex to match string within separators or content boundary
          // Escaping regex per https://stackoverflow.com/questions/3782379/whats-the-best-way-to-escape-user-input-for-regular-expressions-in-mysql
          $value = "(^|$sep)" . preg_quote($value, '&') . "($sep|$)";
          break;

        case \CRM_Core_DAO::SERIALIZE_COMMA:
          $operator = ($operator === 'CONTAINS') ? 'REGEXP' : 'NOT REGEXP';
          // Match string within commas or content boundary
          // Escaping regex per https://stackoverflow.com/questions/3782379/whats-the-best-way-to-escape-user-input-for-regular-expressions-in-mysql
          $value = '(^|,)' . preg_quote($value, '&') . '(,|$)';
          break;

        default:
          $operator = ($operator === 'CONTAINS') ? 'LIKE' : 'NOT LIKE';
          $value = '%' . $value . '%';
          break;
      }
    }

    if ($operator === 'IS EMPTY' || $operator === 'IS NOT EMPTY') {
      // If field is not a string or number, this will pass through and use IS NULL/IS NOT NULL
      $operator = str_replace('EMPTY', 'NULL', $operator);
      // For strings & numbers, create an OR grouping of empty value OR null
      if (in_array($field['data_type'] ?? NULL, ['String', 'Integer', 'Float', 'Boolean'], TRUE)) {
        $emptyVal = ($field['data_type'] === 'String' || $field['serialize']) ? '""' : '0';
        $isEmptyClause = $operator === 'IS NULL' ? "= $emptyVal OR" : "<> $emptyVal AND";
        return "($fieldAlias $isEmptyClause $fieldAlias $operator)";
      }
    }

    if (!$value && ($operator === 'IN' || $operator === 'NOT IN')) {
      $value[] = FALSE;
    }

    if (is_bool($value)) {
      $value = (int) $value;
    }

    if ($operator == 'REGEXP' || $operator == 'NOT REGEXP' || $operator == 'REGEXP BINARY' || $operator == 'NOT REGEXP BINARY') {
      $sqlClause = sprintf('%s %s "%s"', (str_ends_with($operator, 'BINARY') ? 'CAST(' . $fieldAlias . ' AS BINARY)' : $fieldAlias), $operator, \CRM_Core_DAO::escapeString($value));
    }
    else {
      $sqlClause = \CRM_Core_DAO::createSQLFilter($fieldAlias, [$operator => $value]);
    }

    if ($original_operator === "NOT CONTAINS") {
      // For a "NOT CONTAINS", this adds an "OR IS NULL" clause - we want to know that a particular value is not present and don't care whether it has any other value
      return "(($sqlClause) OR $fieldAlias IS NULL)";
    }

    return $sqlClause;
  }


}
