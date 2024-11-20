<?php

use \Civi\Api4\ContributionTracking;

function wmf_civicrm_get_contribution_tracking($msg) {
  if (array_key_exists('contribution_tracking_id', $msg)) {
    return ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_tracking_id'])
      ->execute()
      ->first();
  }
  else {
    return FALSE;
  }
}
