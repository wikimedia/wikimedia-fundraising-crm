<?php

namespace Civi\WMFHook;

use Civi\Api4\Contact as ContactAPI;
use Civi\Api4\Activity;

class Contact {

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
}
