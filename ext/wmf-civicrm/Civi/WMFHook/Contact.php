<?php

namespace Civi\WMFHook;

use Civi\Api4\Contact as ContactAPI;
use Civi\Api4\Activity;
use Civi\Core\Event\GenericHookEvent;

class Contact {

  /**
   * Prospect contact reference columns handled by merge().
   */
  private const PROSPECT_CONTACT_COLUMNS = ['relationship_manager', 'followed_by'];

  /**
   * Implements hook_civicrm_pre
   *
   * @param \Civi\Core\Event\PreEvent $event
   *
   * @throws \CRM_Core_Exception
   */
  public static function pre($event): void {
    // When changing MG Stage, create MG Stage Change activity
    if ($event->action === 'edit') {
      if ($event->hasValue('Prospect.Stage')) {
        $stage = $event->getValue('Prospect.Stage') ?? '';
        $currentStage = ContactAPI::get(FALSE)
          ->addSelect('Prospect.Stage')
          ->addWhere('id', '=', $event->id)
          ->execute()->first()['Prospect.Stage'] ?? '';
        if ($stage !== $currentStage) {
          // Include disabled values
          $options = \CRM_Core_OptionGroup::values('stage_20080616181942', FALSE, FALSE, FALSE, NULL, 'label', FALSE);
          Activity::create(FALSE)
            ->addValue('target_contact_id', $event->id)
            ->addValue('source_contact_id', \CRM_Core_Session::getLoggedInContactID() ?? \CRM_Core_BAO_UFMatch::getContactId(1))
            ->addValue('activity_type_id:name', 'MG Stage Change')
            ->addValue('status_id:name', 'Completed')
            ->addValue('MG_Stage.Changed_to', $stage ?: NULL)
            ->addValue('subject', 'From ' . ($options[$currentStage] ?? 'None') . ' to ' . ($options[$stage] ?? 'None'))
            ->addValue('details', $event->getValue('Prospect.Stage_Change_Reason'))
            ->execute();
        }
      }
    }
  }

  /**
   * Implements hook_civicrm_merge.
   *
   * Only update Prospect contact reference fields on merge when another
   * contact references the merged contact, instead of core's update on every
   * merge. Under REPEATABLE READ
   * that update gap-locks the index even when nothing matches, and for low
   * contact IDs that gap is where every new contact's Prospect row is
   * inserted. The check is a plain (non-locking) read.
   *
   * The columns must exist, so don't merge between deploying this and
   * running the upgrade that adds them.
   *
   * @param \Civi\Core\Event\GenericHookEvent $event
   */
  public static function merge(GenericHookEvent $event): void {
    if ($event->type === 'cidRefs') {
      $event->data['civicrm_value_1_prospect_5'] = array_values(array_diff($event->data['civicrm_value_1_prospect_5'] ?? [], self::PROSPECT_CONTACT_COLUMNS));
    }
    if ($event->type === 'sqls') {
      foreach (self::PROSPECT_CONTACT_COLUMNS as $column) {
        if (\CRM_Core_DAO::singleValueQuery("SELECT 1 FROM civicrm_value_1_prospect_5 WHERE $column = %1 LIMIT 1", [1 => [$event->otherId, 'Integer']])) {
          $event->data[] = "UPDATE civicrm_value_1_prospect_5 SET $column = " . (int) $event->mainId . " WHERE $column = " . (int) $event->otherId;
        }
      }
    }
  }
}
