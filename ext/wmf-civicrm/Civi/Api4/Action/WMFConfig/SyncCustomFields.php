<?php
namespace Civi\Api4\Action\WMFConfig;


use Civi\Api4\CustomGroup;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\CustomField;
use Civi\Api4\OptionValue;
use Civi\Core\Exception\DBQueryException;

/**
 * Class SyncCustomFields
 *
 * This class creates any custom fields declared in our metadata that
 * are not in the database. This is used in our development to set up
 * our WMF fields, and on production to add any missing ones.
 *
 * @package Civi\Api4
 */
class SyncCustomFields extends AbstractAction {

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    try {
      $env = \Civi::settings()->get('environment');
      $customGroupSpecs = require __DIR__ . '/../../../../managed/CustomGroups.php';
      foreach ($customGroupSpecs as $groupName => $customGroupSpec) {
        $customGroup = CustomGroup::get(FALSE)->addWhere('name', '=',$groupName)->execute()->first();
        if (!$customGroup) {
          $customGroup = CustomGroup::create(FALSE)->setValues($customGroupSpec['group'])->execute()->first();
        }
        // We mostly are trying to ensure a unique weight since weighting can be re-ordered in the UI but it gets messy
        // if they are all set to 1.
        $weight = \CRM_Core_DAO::singleValueQuery('SELECT MAX(weight) FROM civicrm_custom_field WHERE custom_group_id = %1',
          [1 => [$customGroup['id'], 'Integer']]
        );
        $existingFields = CustomField::get(FALSE)
          ->addWhere('custom_group_id', '=', $customGroup['id'])
          ->execute()->indexBy('name');

        foreach ($customGroupSpec['fields'] as $customFieldName => $field) {
          $existingField = $existingFields[$customFieldName] ?? NULL;
          if ($existingField) {
            if ($env === 'Development' && isset($field['option_values'])) {
              // If we are on a developer site then sync up the option values. Don't do this on live
              // because we could get into trouble if we are not up-to-date with the options - which
              // we don't really aspire to be - or not enough to let this code run on prod.
              $existingOptions = OptionValue::get(FALSE)
                ->addWhere('option_group_id', '=', $existingField['option_group_id'])
                ->execute()->indexBy('name');
              foreach ($field['option_values'] as $index => $optionValue) {
                if (isset($optionValue['name'])) {
                  if (!isset($existingOptions[$optionValue['name']])) {
                    OptionValue::create(FALSE)
                      ->setValues($optionValue + ['option_group_id' => $existingField['option_group_id']])->execute();
                  }
                }
                elseif (!isset($existingOptions[$index])) {
                  OptionValue::create(FALSE)
                    ->setValues(['name' => $index, 'value' => $optionValue, 'label' => $index, 'option_group_id' => $existingField['option_group_id']])->execute();
                }
              }
            }
            unset($customGroupSpec['fields'][$customFieldName]);
          }
          else {
            $weight++;
            $customGroupSpec['fields'][$customFieldName]['weight'] = $weight;
            // Hopefully this is only required temporarily
            // see https://github.com/civicrm/civicrm-core/pull/20743
            // it will be ignored if 'option_values' is empty.
            $customGroupSpec['fields'][$customFieldName]['option_type'] = (int) !empty($field['option_values']);
            foreach ($field['option_values'] ?? [] as $key => $value) {
              if (is_array($value) && empty($value['id'])) {
                // The name in the option_value table is value - but Coleman has mapped to id.
                // https://github.com/civicrm/civicrm-core/pull/17167
                // The option values are handled in
                // https://github.com/civicrm/civicrm-core/blob/ed3f5877550c524765812d86c2feff0c4363484e/Civi/Api4/Action/CustomField/CustomFieldSaveTrait.php#L37
                $customGroupSpec['fields'][$customFieldName]['option_values'][$key]['id'] = $value['value'];
              }
            }
          }
        }
        if ($customGroupSpec['fields']) {
          CustomField::save(FALSE)
            ->setRecords($customGroupSpec['fields'])
            ->setDefaults(['custom_group_id' => $customGroup['id']])
            ->execute();
        }
      }
      civicrm_api3('System', 'flush', ['triggers' => 0, 'session' => 0]);
    }
    catch (DBQueryException $e) {
      \Civi::log('wmf')->error('Failed to create fields {error} {error_code} {debug}', [
        'error' => $e->getMessage(),
        'error_code' => $e->getSQLErrorCode(),
        'debug' => $e->getDebugInfo(),
      ]);
    }
    $result[] = [];

  }

}
