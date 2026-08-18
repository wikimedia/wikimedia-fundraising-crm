<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHook;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\CustomField;
use Civi\Api4\GatewayAccount;
use Civi\Api4\Relationship;
use Civi\Core\Event\PreEvent;
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
   * Implements hook_civicrm_pre::Contribution.
   *
   * @throws \CRM_Core_Exception
   */
  public static function contributionPre(PreEvent $event): void {
    if ($event->action === 'delete') {
      return;
    }
    $relatedContactID = (int) $event->getValue('donor_advised_fund.owns_donor_advised_for');
    if (empty($relatedContactID)) {
      return;
    }
    // contact_id is submitted alongside the custom field on create (when
    // the contribution doesn't have an id yet); on edit it may not be
    // resubmitted, in which case createDonorAdvisedRelationshipFromCustomField()
    // falls back to looking it up via contributionID.
    $contactID = $event->getValue('contact_id');
    self::createDonorAdvisedRelationshipFromCustomField(
      $relatedContactID,
      $contactID ? (int) $contactID : NULL,
      (int) $event->id
    );
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected static function createDonorAdvisedRelationshipFromCustomField(int $relatedContactID, ?int $contactID = NULL, ?int $contributionID = NULL): void {
    if (!$contactID && !$contributionID) {
      return;
    }
    $contactID = $contactID ?? Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionID)
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

  /**
   * Implements hook_civicrm_pre::Batch.
   *
   * Keeps batch_data.settlement_gateway (a plain name string, kept for
   * backward compatibility with existing consumers/analytics) in sync with
   * batch_data.settlement_gateway_account_id (a proper EntityReference to
   * GatewayAccount, storing GatewayAccount.id).
   *
   * If both are submitted in the same save with conflicting values, the
   * id field wins, since it's the more precise reference. If only one is
   * submitted, the other is derived from it. If the referenced
   * GatewayAccount can't be found (e.g. a stale/typo'd name, or a deleted
   * account id), neither field is touched.
   *
   * @throws \CRM_Core_Exception
   */
  public static function batchPre(PreEvent $event): void {
    if ($event->action === 'delete') {
      return;
    }
    $accountID = $event->getValue('batch_data.settlement_gateway_account_id');
    $gatewayName = $event->getValue('batch_data.settlement_gateway');

    if (!empty($accountID)) {
      $name = GatewayAccount::get(FALSE)
        ->addWhere('id', '=', $accountID)
        ->addSelect('name')
        ->execute()->first()['name'] ?? NULL;
      if ($name !== NULL) {
        $event->setValue('batch_data.settlement_gateway', $name);
      }
    }
    elseif (!empty($gatewayName)) {
      $id = GatewayAccount::get(FALSE)
        ->addWhere('name', '=', $gatewayName)
        ->addSelect('id')
        ->execute()->first()['id'] ?? NULL;
      if ($id !== NULL) {
        $event->setValue('batch_data.settlement_gateway_account_id', $id);
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
