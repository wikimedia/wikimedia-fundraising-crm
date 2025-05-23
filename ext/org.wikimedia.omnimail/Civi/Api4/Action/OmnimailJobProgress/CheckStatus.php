<?php
namespace Civi\Api4\Action\OmnimailJobProgress;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\OmnimailJobProgress;
use GuzzleHttp\Client;

/**
 * Class Check.
 *
 * Provided by the omnimail extension.
 *
 * @method $this setJobName(string $name)
 * @method string getJobName()
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client $client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class CheckStatus extends AbstractAction {

  /**
   * @var string
   *
   * @required
   */
  protected string $jobName = '';

  /**
   * @var object
   */
  protected $client;

  /**
   * @var string
   */
  protected string $mailProvider = 'Silverpop';

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $activeJobs = OmnimailJobProgress::get(FALSE)
      ->addWhere('job', '=', $this->getJobName())
      ->execute();
    $omniObject = new \CRM_Omnimail_OmnimailJobProgress([
      'mail_provider' => $this->getMailProvider(),
    ]);
    foreach ($activeJobs as $activeJob) {
      $activeJob += $omniObject->checkStatus([
        'job_id' => $activeJob['job_identifier'],
        'client' => $this->getClient(),
        'mail_provider' => $this->getMailProvider(),
      ]);
      $result[] = $activeJob;
      if ($activeJob['is_complete']) {
        OmnimailJobProgress::delete(FALSE)
          ->addWhere('id', '=', $activeJob['id'])
          ->execute();
      }
    }
  }

}
