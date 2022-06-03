<?php
namespace Civi\Api4\Action\WMFConfig;


use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\CustomField;

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
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function _run(Result $result): void {
    $customGroupSpecs = require __DIR__ . '/../../../../Managed/CustomGroups.php';
    foreach ($customGroupSpecs as $groupName => $customGroupSpec) {
      $customGroup = civicrm_api3('CustomGroup', 'get', ['name' => $groupName]);
      if (!$customGroup['count']) {
        $customGroup = civicrm_api3('CustomGroup', 'create', $customGroupSpec['group']);
      }
      // We mostly are trying to ensure a unique weight since weighting can be re-ordered in the UI but it gets messy
      // if they are all set to 1.
      $weight = \CRM_Core_DAO::singleValueQuery('SELECT max(weight) FROM civicrm_custom_field WHERE custom_group_id = %1',
        [1 => [$customGroup['id'], 'Integer']]
      );

      foreach ($customGroupSpec['fields'] as $index => $field) {
        $existingField = civicrm_api3('CustomField', 'get', [
          'custom_group_id' => $customGroup['id'],
          'name' => $field['name'],
        ]);

        if ($existingField['count']) {
          if (isset($field['option_values'])) {
            // If we are on a developer site then sync up the option values. Don't do this on live
            // because we could get into trouble if we are not up-to-date with the options - which
            // we don't really aspire to be - or not enough to let this code run on prod.
            $env = civicrm_api3('Setting', 'getvalue', ['name' => 'environment']);
            if ($env === 'Development' && empty($existingField['option_group_id'])) {
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
          }
          unset($customGroupSpec['fields'][$index]);
        }
        else {
          $weight++;
          $customGroupSpec['fields'][$index]['weight'] = $weight;
          // Hopefully this is only required temporarily
          // see https://github.com/civicrm/civicrm-core/pull/20743
          // it will be ignored if 'option_values' is empty.
          $customGroupSpec['fields'][$index]['option_type'] = (int) !empty($field['option_values']);
          foreach ($field['option_values'] ?? [] as $key => $value) {
            // Translate simple key/value pairs into full-blown option values
            // Copied from v3 api code since we are using v4 in some places.
            // but BAO still needs this.
            if (!is_array($value)) {
              $value = [
                'label' => $value,
                'value' => $key,
                'is_active' => 1,
                'weight' => $weight,
              ];
              $key = $weight++;
            }
            $customGroupSpec['fields'][$index]['option_label'][$key] = $value['label'];
            $customGroupSpec['fields'][$index]['option_value'][$key] = $value['value'];
            $customGroupSpec['fields'][$index]['option_status'][$key] = $value['is_active'];
            $customGroupSpec['fields'][$index]['option_weight'][$key] = $value['weight'];
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

    $result[] = [];

  }

}
