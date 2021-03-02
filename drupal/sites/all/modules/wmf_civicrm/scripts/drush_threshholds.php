<?php
/**
 * Check if the queue is backed up.
 *
 * @todo think about using a hook structure here so different modules can interact
 * (and to keep it generic for others to use).
 *
 * @param int $threshold
 *   Contribution threshold - abort if more in the period.
 * @param int $numberOfMinutes
 *   Number of minutes in threshold period.
 *
 * @return int|bool
 *   Number of contributions of FALSE if not more than the threshold.
 */
function _drush_civicrm_queue_is_backed_up($threshold, $numberOfMinutes = 5) {
  $countOfContributionsInTimeFrame = CRM_Core_DAO::singleValueQuery('
      SELECT count(*) FROM civicrm_contact WHERE created_date > DATE_SUB(NOW(), INTERVAL %1 MINUTE);
    ',
    array(1 => array($numberOfMinutes, 'Integer'))
  );
  if ($countOfContributionsInTimeFrame > $threshold) {
    drush_print(
      "Early return as queue is backed up. $countOfContributionsInTimeFrame contributions in the last $numberOfMinutes"
      . " minutes is greater than the threshold of $threshold"
    );
    return TRUE;
  }
  return FALSE;

}
