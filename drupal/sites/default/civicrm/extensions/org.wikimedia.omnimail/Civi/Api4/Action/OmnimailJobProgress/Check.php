<?php
namespace Civi\Api4\Action\OmnimailJobProgress;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\OmnimailJobProgress;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @package Civi\Api4
 */
class Check extends AbstractAction {

 /**
  * @inheritDoc
  *
  * @param \Civi\Api4\Generic\Result $result
  *
  * @throws \CRM_Core_Exception
  */
  public function _run(Result $result) {
    if (OmnimailJobProgress::get($this->getCheckPermissions())
      ->selectRowCount()
      ->addWhere('created_date', '<=', '1 hour ago')
      ->addWhere('job', '=', 'omnimail_privacy_erase')
      ->execute()->count()) {
      throw new \CRM_Core_Exception('Out of date omnimail_privacy_erase request found');
    }
  }

}
