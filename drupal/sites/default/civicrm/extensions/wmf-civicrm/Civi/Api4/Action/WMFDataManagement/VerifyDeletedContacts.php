<?php
namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

class VerifyDeletedContacts extends AbstractAction {

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return \Civi\Api4\Generic\Result
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): Result {
    $recurrings = ContributionRecur::get(FALSE)
      ->addWhere('contact_id.is_deleted', '=', TRUE)
      ->execute();
    $output = [];
    foreach ($recurrings as $recur) {
      $result[] = $recur;
      $output[] = 'Recurring contribution: started on ' . $recur['start_date'] . ' trxn: ' . $recur['trxn_id'] . ' '
        . \CRM_Utils_System::url('civicrm/contact/view', [
          'cid' => $recur['contact_id'],
          'reset' => 1,
        ],
      TRUE);
    }
    $contributions = Contribution::get(FALSE)
      ->addWhere('contact_id.is_deleted', '=', TRUE)
      ->execute();
    foreach ($contributions as $contribution) {
      $result[] = $contribution;
      $output[] = 'Contribution: received on ' . $contribution['receive_date'] . ' amount: ' . $contribution['total_amount'] . ' trxn: ' . $contribution['trxn_id'] . ' '
        . \CRM_Utils_System::url('civicrm/contact/view', [
          'cid' => $contribution['contact_id'],
          'reset' => 1,
        ],
          TRUE);
    }
    if (!empty($output)) {
      \Civi::log('wmf')->alert(implode("\n", $output), [
        'subject' => count($output) > 1 ? 'Deleted contacts with data found' : 'Deleted contact with data found',
      ]);
    }
    return $result;
  }

}
