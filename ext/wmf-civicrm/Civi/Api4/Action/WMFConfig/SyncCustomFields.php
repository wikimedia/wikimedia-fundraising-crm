<?php
namespace Civi\Api4\Action\WMFConfig;


use Civi\Api4\CustomGroup;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\CustomField;
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
            if ($env === 'Development' && isset($field['option_values']) && empty($existingField['option_group_id'])) {
              // If we are on a developer site then sync up the option values. Don't do this on live
              // because we could get into trouble if we are not up-to-date with the options - which
              // we don't really aspire to be - or not enough to let this code run on prod.

              $field['id'] = $existingField['id'];
              // This is a hack because they made a change to the BAO to restrict editing
              // custom field options based on a form value - when they probably should
              // have made the change in the form. Without this existing fields don't
              // get option group updates. See https://issues.civicrm.org/jira/browse/CRM-16659 for
              // original sin.
              $field['option_type'] = 1;
              // The reasons for not using the apiv4 save function lower down are now resolved
              // from a technical pov so switching over here is now a @todo
              // Also this shouldn't really ever affect prod fields - or not at the moment.
              civicrm_api3('CustomField', 'create', $field);
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
