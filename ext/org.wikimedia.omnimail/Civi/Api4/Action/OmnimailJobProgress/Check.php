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
    // Check for jobs that have been running longer than expected
    $outdatedJobs = OmnimailJobProgress::get($this->getCheckPermissions())
      ->addWhere('created_date', '<=', $this->getTimeDescription())
      ->addWhere('job', '=', $this->getJobName())
      ->execute();

    if ($outdatedJobs->count() > 0) {
      $identifiers = [];
      foreach ($outdatedJobs as $job) {
        $identifiers[] = $job['job_identifier'];
      }
      $message = 'Out of date ' . $this->getJobName() . ' request found. Please check the status of job(s) ' .
        implode(', ', $identifiers) . ' at ' .
        'https://cloud.goacoustic.com/campaign-automation/Settings/Activity_reports/All_data_jobs';
      throw new \CRM_Core_Exception($message);
    }
  }

}
