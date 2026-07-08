<?php

namespace Civi\WMFHook;

class DonorHistoryTrigger extends TriggerHook {

  /**
   * Log changes to tracked wmf_donor fields into wmf_donor_history.
   *
   * Fires on every insert and on updates that change a tracked field.
   *
   * @throws \CRM_Core_Exception
   */
  public function triggerInfo(): array {
    if ($this->getTableName() !== NULL && $this->getTableName() !== 'wmf_donor') {
      return [];
    }
    $columns = [];
    $values = [];
    $changeConditions = [];
    $updateValues = [];
    $insertValues = [];
    foreach ((new CalculatedData())->getLoggedFields() as $field => $spec) {
      // The option value for this field, shared with WMFDonorHistoryChangedField.mgd.php.
      $optionValue = $spec['log_changes'];
      $columns[] = $field;
      $values[] = "NEW.$field";
      $changeConditions[] = "IFNULL(OLD.$field,'') <> IFNULL(NEW.$field,'')";
      $updateValues[] = "IF(IFNULL(OLD.$field,'') <> IFNULL(NEW.$field,''), '$optionValue', NULL)";
      $insertValues[] = "IF(IFNULL(NEW.$field,'') <> '', '$optionValue', NULL)";
    }
    if (!$columns) {
      return [];
    }
    // A CRM separator-bookend (\x01value\x01) serialized set of the changed columns' option values.
    $insertChanged = 'CONCAT(CHAR(1), CONCAT_WS(CHAR(1), ' . implode(', ', $insertValues) . '), CHAR(1))';
    $updateChanged = 'CONCAT(CHAR(1), CONCAT_WS(CHAR(1), ' . implode(', ', $updateValues) . '), CHAR(1))';
    $insertPrefix = 'INSERT INTO wmf_donor_history (entity_id, ' . implode(', ', $columns)
      . ', changed_fields) VALUES (NEW.entity_id, ' . implode(', ', $values) . ', ';
    $insertOnInsert = $insertPrefix . $insertChanged . ');';
    $insertOnUpdate = $insertPrefix . $updateChanged . ');';
    return [
      [
        'table' => 'wmf_donor',
        'when' => 'AFTER',
        'event' => 'INSERT',
        'sql' => $insertOnInsert,
      ],
      [
        'table' => 'wmf_donor',
        'when' => 'AFTER',
        'event' => 'UPDATE',
        'sql' => 'IF (' . implode(' OR ', $changeConditions) . ") THEN\n" . $insertOnUpdate . "\nEND IF;",
      ],
    ];
  }

}
