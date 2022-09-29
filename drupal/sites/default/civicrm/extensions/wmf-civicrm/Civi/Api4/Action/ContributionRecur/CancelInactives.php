<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Changes status to cancelled for any recurring contributions
 * that haven't gotten a contribution for at least two months
 * @method setDays(int $days) set the number of days
 */
class CancelInactives extends AbstractAction {

  /**
   * @var int number of days past due to consider inactive
   */
  protected $days = 60;

  public function _run( Result $result ) {
    $limitDate = date('Y-m-d', strtotime("-$this->days days"));
    $inactives = ContributionRecur::get(FALSE)
      ->addWhere('contribution_status_id', 'NOT IN', [1,3,4])
      ->addWhere('next_sched_contribution_date', '<', $limitDate)
      ->execute();

    foreach($inactives as $inactive) {
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $inactive['id'])
        ->setValues([
          'contribution_status_id:name' => 'Cancelled',
          'cancel_date' => date('Y-m-d H:i:s', strtotime('now')),
          'cancel_reason' => 'Automatically cancelled for inactivity'
        ])->execute();
      $result[] = [
        'id' => $inactive['id'],
        'start_date' => $inactive['start_date'],
        'next_sched_contribution_date' => $inactive['next_sched_contribution_date'],
        'contact_id' => $inactive['contact_id']
      ];
    }
  }
}
