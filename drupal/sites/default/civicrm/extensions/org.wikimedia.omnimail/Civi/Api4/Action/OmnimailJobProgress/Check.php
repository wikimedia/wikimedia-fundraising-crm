<?php
namespace Civi\Api4\Action\OmnimailJobProgress;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\OmnimailJobProgress;

/**
 * Class Check.
 *
 * Provided by the omnimail extension.
 *
 * @method $this setJobName(string $name)
 * @method string getJobName()
 * @method $this setTimeDescription(string $timeDescription)
 * @method string getTimeDescription()
 *
 * @package Civi\Api4
 */
class Check extends AbstractAction {

  protected string $jobName = 'omnimail_privacy_erase';
  protected string $timeDescription = '1 hour ago';

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
      ->addWhere('created_date', '<=', $this->getTimeDescription())
      ->addWhere('job', '=', $this->getJobName())
      ->execute()->count()) {
      throw new \CRM_Core_Exception('Out of date ' . $this->getJobName() . ' request found');
    }
  }

}
