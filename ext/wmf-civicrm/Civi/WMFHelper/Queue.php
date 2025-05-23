<?php

namespace Civi\WMFHelper;

class Queue {

  /**
   *
   * @throws \CRM_Core_Exception
   */
  public static function isSiteBusy($event): void {
    $threshold = (int) \CRM_Utils_Constant::value('busy_threshold', 100);
    if (!$threshold) {
      return;
    }
    $thresholdNumberOfMinutes = (int) \CRM_Utils_Constant::value('busy_threshold_minutes', 1);
    $countOfContributionsInTimeFrame = \CRM_Core_DAO::singleValueQuery(
      '   SELECT COUNT(*) FROM log_civicrm_contribution WHERE log_date > DATE_SUB(NOW(), INTERVAL %1 MINUTE)',
      [1 => [$thresholdNumberOfMinutes, 'Integer']]
    );
    if ($countOfContributionsInTimeFrame > $threshold) {
      \Civi::log('wmf')->info(
        "Early return as queue is backed up. $countOfContributionsInTimeFrame contributions in the last $thresholdNumberOfMinutes"
        . " minutes is greater than the threshold of $threshold"
      );
      $event->status = 'paused';
    }
  }

}
