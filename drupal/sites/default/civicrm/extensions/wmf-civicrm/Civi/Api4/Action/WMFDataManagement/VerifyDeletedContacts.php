<?php
namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

class VerifyDeletedContacts extends AbstractAction {

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return mixed
   */
  public function _run(Result $result) {
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
    if (!empty($output)) {
      \Civi::log('wmf')->alert(implode("\n", $output), [
        'subject' => count($output) > 1 ? 'Deleted contacts with data found' : 'Deleted contact with data found',
      ]);
    }
    return $result;
  }

}

