<?php
namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\OptionGroup;

/**
 * Class CleanInvalidLanguageOptions.
 *
 * Delete invalid made up language options.
 *
 * @package Civi\Api4
 */
class CleanInvalidLanguageOptions extends AbstractAction {

  /**
   * Get the values actually used for the option.
   *
   * @param string $field
   *
   * @return array
   */
  private function getInUseOptions(string $field): array {
    $dbResult = \CRM_Core_DAO::executeQuery("
      SELECT distinct $field as option_field FROM civicrm_contact
      WHERE is_deleted = 0 AND
      $field IS NOT NULL
    ");
    $usedOptions = [];
    while ($dbResult->fetch()) {
      $usedOptions[] = $dbResult->option_field;
    }
    return $usedOptions;
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $optionGroups = OptionGroup::get(FALSE)
      ->addWhere('name', 'IN', ['languages'])
      ->addSelect('id', 'name')->execute()->indexBy('name');
    $languages = $this->getInUseOptions('preferred_language');
    \CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_option_value
      WHERE option_group_id = {$optionGroups['languages']['id']}
        -- extra cautionary check - only ones with cruft-for-labels
        AND label = name
        AND name NOT IN ('" . implode("', '", $languages) ."')");
  }
}
