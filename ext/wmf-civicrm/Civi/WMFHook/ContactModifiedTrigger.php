<?php

namespace Civi\WMFHook;

class ContactModifiedTrigger extends TriggerHook {

  /**
   * Add a trigger to contribution_recur
   *
   * Whenever a recurring contribution is modified
   * we want to update the last modified date on the linked contact record.
   */
  public function triggerInfo(): array {
    $info = [];
    if (
      $this->getTableName() === NULL ||
      $this->getTableName() === 'civicrm_contribution_recur'
    ) {
      $info[] = [
        'table' => 'civicrm_contribution_recur',
        'when' => 'AFTER',
        'event' => 'UPDATE',
        'sql' => 'UPDATE civicrm_contact SET modified_date = CURRENT_TIMESTAMP WHERE id = OLD.contact_id;',
      ];
    }
    if (
      $this->getTableName() === NULL ||
      $this->getTableName() === 'civicrm_entity_tag'
    ) {
      $info[] = [
        'table' => 'civicrm_entity_tag',
        'when' => 'AFTER',
        'event' => 'INSERT',
        'sql' => 'UPDATE civicrm_contact SET modified_date = CURRENT_TIMESTAMP WHERE id = NEW.entity_id ' .
          "AND NEW.entity_table='civicrm_contact';",
      ];
      $info[] = [
        'table' => 'civicrm_entity_tag',
        'when' => 'AFTER',
        'event' => 'DELETE',
        'sql' => 'UPDATE civicrm_contact SET modified_date = CURRENT_TIMESTAMP WHERE id = OLD.entity_id ' .
          "AND OLD.entity_table='civicrm_contact';",
      ];
    }
    if (
      $this->getTableName() === NULL ||
      $this->getTableName() === 'civicrm_relationship'
    ) {
      $info[] = [
        'table' => 'civicrm_relationship',
        'when' => 'AFTER',
        'event' => 'INSERT',
        'sql' => 'UPDATE civicrm_contact SET modified_date = CURRENT_TIMESTAMP WHERE id IN (NEW.contact_id_a, NEW.contact_id_b);',
      ];
      $info[] = [
        'table' => 'civicrm_relationship',
        'when' => 'AFTER',
        'event' => 'UPDATE',
        'sql' => 'UPDATE civicrm_contact SET modified_date = CURRENT_TIMESTAMP WHERE id IN (OLD.contact_id_a, OLD.contact_id_b, NEW.contact_id_a, NEW.contact_id_b);',
      ];
      $info[] = [
        'table' => 'civicrm_relationship',
        'when' => 'AFTER',
        'event' => 'DELETE',
        'sql' => 'UPDATE civicrm_contact SET modified_date = CURRENT_TIMESTAMP WHERE id IN (OLD.contact_id_a, OLD.contact_id_b);',
      ];
    }
    return $info;
  }

}
