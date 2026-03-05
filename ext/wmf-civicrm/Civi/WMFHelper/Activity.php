<?php

namespace Civi\WMFHelper;

use Civi\Api4\Generic\Result;

class Activity {
  public static function getDoubleOptInActivities($contactID): Result {
    return \Civi\Api4\Activity::get(FALSE)
      ->addWhere('target_contact_id', '=', $contactID)
      ->addWhere('activity_type_id:name', '=', 'Double Opt-In')
      ->addWhere('status_id:name', '=', 'Completed')
      ->addSelect('subject')
      ->execute()->indexBy('subject');
  }
}
