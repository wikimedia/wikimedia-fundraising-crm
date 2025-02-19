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
      $output[] = \CRM_Utils_System::url('civicrm/contact', ['cid' => $recur['contact_id']], TRUE);
    }
    if (!empty($output)) {
      \Civi::log('wmf')->alert(implode("\n", $output));
    }
    return $result;
  }

}

