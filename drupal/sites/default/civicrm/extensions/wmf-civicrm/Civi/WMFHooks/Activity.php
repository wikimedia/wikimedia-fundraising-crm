<?php

namespace Civi\WMFHooks;

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
}
