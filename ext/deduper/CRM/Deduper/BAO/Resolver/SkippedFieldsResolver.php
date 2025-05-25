<?php

use Civi\Api4\CustomField;
use Civi\Api4\Generic\Result;


/**
 * Class CRM_Deduper_BAO_Resolver_BooleanYesResolver
 */
class CRM_Deduper_BAO_Resolver_SkippedFieldsResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve any fields that should be skipped by removing them from the merge.
   *
   * This in particular applies to calculated fields.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolveConflicts(): void {
    $fieldsToByPass = $this->getFieldsToByPass();
    foreach ($fieldsToByPass as $field) {
      $this->mergeHandler->setDoNotMoveField('custom_' . $field['id']);
    }
  }

  /**
   * Get fields that should not be touched in the merge context.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function getFieldsToBypass(): array {
    $groupsToSkip = (array) Civi::settings()->get('deduper_resolver_custom_groups_to_skip');
    if (empty($groupsToSkip)) {
      return [];
    }
    return (array)CustomField::get(FALSE)
      ->setSelect(['id'])
      ->addWhere(
      'custom_group_id:name', 'IN',  $groupsToSkip
      )->execute();
  }

}
