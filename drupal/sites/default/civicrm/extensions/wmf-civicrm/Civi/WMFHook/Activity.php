<?php

namespace Civi\WMFHook;

use CRM_Core_PseudoConstant;

class Activity {

  public static function links($activityID, &$links) {
    // Need the activity_type_id which isnt passed through the variables, look it up
    $activity_type_id = \Civi\Api4\Activity::get()->addWhere('id','=',$activityID)->addSelect('activity_type_id:name')->execute()->first()['activity_type_id:name'];

    // Thank you email doesn't need edit or delete
    if ($activity_type_id === 'Thank you email') {
      foreach ($links as $index => $link) {
        if ($link['name'] === 'Edit' || $link['name'] === 'Delete') {
          unset($links[$index]);
        }
        elseif ($link['name'] === 'View') {
          // Changing the view link to the email activity type
          $links[$index]['url'] = 'civicrm/activity/view';
        }
      }
    }
  }

  /**
   * Bug: T332074
   *
   * Do not save details for thank you email
   * In the case it takes up many GB of space & it is not subject to change.
   *
   * @param array $info
   *
   * @return array
   */
  public static function alterTriggerSql(array $info): array {
    $alterInfo = [];
    $activityTypesToSkipDetails = [
      CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Thank you email'),
      CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Bounce'),
    ];
    foreach ($info as $trigger) {
      if ($trigger['table'] === ['civicrm_activity'] && $trigger['event'] === ['INSERT'] && $trigger['when'] === 'AFTER') {
        $trigger['sql'] = str_replace("NEW.`phone_number`, NEW.`details`" , "NEW.`phone_number`,
        IF(NEW.`activity_type_id` NOT IN (". implode(',', array_filter($activityTypesToSkipDetails)) . "), NEW.`details`, NULL)", $trigger['sql']);
      }
      $alterInfo[] = $trigger;
    }
    return $alterInfo;
  }
}
