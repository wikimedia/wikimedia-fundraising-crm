<?php

namespace Civi\Api4\Action\WMFDonor;

use Civi\Api4\Contact;
use Civi\Api4\WMFDonor;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Update recurring donor status for donors whose recurring donations are
 * no longer paused.
 *
 * Our definition of paused is that the donor's next recurring is further in
 * the future than one frequency unit plus a day (so a month and a day or a year
 * and a day). The donor pauses their recurring contribution for a set number of
 * days (30, 60 or 90) via Donor Portal or DR, but we don't consider it paused for
 * that number of days and we don't have a real paused status in CiviCRM;
 * we consider it paused until one period (plus a day) before it will be charged
 * again (when it is back on the regular schedule). So we need this action scheduled
 * daily to update the recurring statuses from paused to active, after they are
 * "back on the schedule" as there is no update to the ContributionRecur to trigger
 * the update.
 *
 * We check the monthly and yearly statuses separately so a donor paused on only
 * one frequency is still caught. An overall paused donor is always paused on
 * their monthly or yearly status too, so is covered without checking it directly.
 */
class UnPauseRecurring extends AbstractAction {

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $contacts = [];
    foreach (['month', 'year'] as $unit) {
      // Using the SQL matches exactly how we actually calculate in Update.
      $pausedUntil = \CRM_Core_DAO::singleValueQuery("SELECT NOW() + INTERVAL 1 $unit + INTERVAL 1 DAY");
      $contacts += (array) Contact::get(FALSE)
        ->addSelect('id')
        ->addJoin('ContributionRecur AS recur', 'EXCLUDE',
          ['id', '=', 'recur.contact_id'],
          ['recur.contribution_status_id:name', '=', '"In Progress"'],
          ['recur.next_sched_contribution_date', '>', '"' . $pausedUntil . '"'],
          ['recur.frequency_unit', '=', '"' . $unit . '"'],
        )
        ->addWhere("wmf_donor.donor_status_recur_$unit:name", '=', 'paused')
        ->execute()->indexBy('id');
    }

    if ($contacts) {
      WMFDonor::update(FALSE)
        ->addValue('donor_status_recur_month', TRUE)
        ->addValue('donor_status_recur_year', TRUE)
        ->addValue('donor_status_recur_overall', TRUE)
        ->addWhere('id', 'IN', array_keys($contacts))
        ->execute();
    }

    $result[] = ['status' => 'success', 'count' => count($contacts), 'message' => 'Recurring paused statuses updated.'];
  }

}
