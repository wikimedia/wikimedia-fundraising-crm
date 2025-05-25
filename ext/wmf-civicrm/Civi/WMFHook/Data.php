<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHook;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\Relationship;
use Civi\WMFHelper\CustomData;
use CRM_Wmf_ExtensionUtil as E;

class Data {

  /**
   * Implements custom pre hook to populate date edited fields.
   *
   * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_customPre/
   *
   * @param string $op
   * @param int $groupID
   * @param int $entityID
   * @param array $params
   *
   * @throws \CRM_Core_Exception
   */
  public static function customPre(string $op, int $groupID, int $entityID, array &$params): void {
    if ($op !== 'delete') {
      self::createDonorAdvisedRelationshipFromCustomField($groupID, $entityID, $params);
    }
    if (empty(self::getDateTrackingFields())) {
      return;
    }
    $trackableGroups = self::getTrackableGroups();
    if (!empty($trackableGroups[$groupID])) {
      $groupName = $trackableGroups[$groupID]['custom_group_id:name'];
      $trackingFields = self::getDateTrackingFields();
      $fieldValuesToTrack = [];
      foreach ($params as $values) {
        if (!empty($trackingFields[$values['custom_field_id']])) {
          $fieldValuesToTrack[$values['custom_field_id']] = $values['value'];
        }
      }

      // Find out if the values we are about to save are different from the saved values.
      // if so, update the tracking field.
      if (!empty($fieldValuesToTrack)) {
        $existingValues = in_array($op, ['create', 'delete']) ? [] : self::getExistingValuesForFields($fieldValuesToTrack, $entityID, $groupName);
        foreach ($fieldValuesToTrack as $key => $value) {
          if (($existingValues[$key] ?? '') !== $value) {
            foreach ($params as &$param) {
              if ((int) $param['custom_field_id'] === $trackingFields[$key]) {
                $param['value'] = date('YmdHis');
              }
            }
          }
        }
      }
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected static function createDonorAdvisedRelationshipFromCustomField(int $groupID, int $entityID, array $params) {
    $groupName = CustomGroup::get(FALSE)
      ->addWhere('id', '=', $groupID)
      ->execute()->single()['name'];
    if ($groupName === 'donor_advised_fund') {
      foreach ($params as $field) {
        if ($field['column_name'] !== 'owns_donor_advised_for' || empty($field['value'])) {
          continue;
        }
        $relatedContactID = (int) $field['value'];
        $contactID = Contribution::get(FALSE)->addWhere('id', '=', $entityID)
          ->addSelect('contact_id')
          ->execute()->single()['contact_id'];
        if (!count(Relationship::get(FALSE)
          ->addWhere('contact_id_b', '=', $contactID)
          ->addWhere('contact_id_a', '=', $relatedContactID)
          ->addWhere('relationship_type_id.name_a_b', '=', 'Holds a Donor Advised Fund of')
          ->addSelect('id')->execute())) {
          // Relationship type is a required field so if not found this would
          // throw an error and the line import would be rolled back. There would be
          // an error line in the csv presented to the user.
          Relationship::create(FALSE)->setValues([
            'contact_id_b' => $contactID,
            'contact_id_a' => $relatedContactID,
            'relationship_type_id.name_a_b' => 'Holds a Donor Advised Fund of',
          ])->execute();
        }
      }
    }
  }

  /**
   * Get the fields configured for date tracking.
   *
   * This will be an array like
   *
   * [23 => 45, 24 => 67]
   *
   * Where field 23 is a custom field we want to track. Field 45 will
   * be updated whenever field 23 is. Ditto 24 will be tracked by 67.
   *
   * @return array
   */
  public static function getDateTrackingFields(): array {
    return (array) \Civi::settings()->get('custom_field_tracking');
  }

  /**
   * Get the ids of any groups that may be tracked.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public static function getTrackableGroups() : array {
    if (!\Civi::cache('metadata')->has('trackable_groups')) {
      $groups = (array) CustomField::get(FALSE)
        ->addWhere('id', 'IN', array_keys(self::getDateTrackingFields()))
        ->addSelect('custom_group_id', 'custom_group_id:name')->execute()->indexBy('custom_group_id');
      \Civi::cache('metadata')->set('trackable_groups', $groups);
    }
    return (array) \Civi::cache('metadata')->get('trackable_groups');
  }

  /**
   * Get the existing values from the database for the fields.
   *
   * This involves a bit of format wrangling but is basically a db lookup.
   *
   * @param array $fieldValues
   * @param int $entityID
   * @param string $customGroupName
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected static function getExistingValuesForFields(array $fieldValues, int $entityID, $customGroupName): array {
    $mapping = [];
    foreach (array_keys($fieldValues) as $fieldValue) {
      $mapping[$fieldValue] = $customGroupName . '.' . CustomData::getCustomFieldNameFromID($fieldValue);
    }
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $entityID)
      ->setSelect($mapping)
      ->execute()->first();
    $existingValues = [];
    foreach ($mapping as $key => $value) {
      $existingValues[$key] = $contact[$value];
    }
    return $existingValues;
  }

}
