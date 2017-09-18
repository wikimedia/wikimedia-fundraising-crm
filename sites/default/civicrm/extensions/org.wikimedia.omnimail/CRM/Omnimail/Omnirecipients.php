<?php

use Omnimail\Silverpop\Responses\RecipientsResponse;
use Omnimail\Omnimail;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/16/17
 * Time: 5:53 PM
 */

class CRM_Omnimail_Omnirecipients extends CRM_Omnimail_Omnimail{

  /**
   * @var 
   */
  protected $request;

  /**
   * @var string
   */
  protected $job = 'omnirecipient';

  /**
   * @param array $params
   *
   * @return \Omnimail\Silverpop\Responses\RecipientsResponse
   *
   * @throws \CRM_Omnimail_IncompleteDownloadException
   * @throws \API_Exception
   */
  public function getResult($params) {
    $jobSettings = $this->getJobSettings($params);
    $settings = CRM_Omnimail_Helper::getSettings();

    $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);

    $request = Omnimail::create($params['mail_provider'], $mailerCredentials)->getRecipients();

    $startTimestamp = self::getStartTimestamp($params, $jobSettings);
    $this->endTimeStamp = self::getEndTimestamp(CRM_Utils_Array::value('end_date', $params), $settings, $startTimestamp);

    if (isset($jobSettings['retrieval_parameters'])) {
      if (!empty($params['end_date']) || !empty($params['start_date'])) {
        throw new API_Exception('A prior retrieval is in progress. Do not pass in dates to complete a retrieval');
      }
      $request->setRetrievalParameters($jobSettings['retrieval_parameters']);
    }
    elseif ($startTimestamp) {
      if ($this->endTimeStamp < $startTimestamp) {
        throw new CiviCRM_API3_Exception(ts("End timestamp: " . date('Y-m-d H:i:s', $this->endTimeStamp) . " is before " . "Start timestamp: " . date('Y-m-d H:i:s', $startTimestamp)));
      }
      $request->setStartTimeStamp($startTimestamp);
      $request->setEndTimeStamp($this->endTimeStamp);
    }

    $result = $request->getResponse();
    for ($i = 0; $i < $settings['omnimail_job_retry_number']; $i++) {
      if ($result->isCompleted()) {
        $data = $result->getData();
        return $data;
      }
      else {
        sleep($settings['omnimail_job_retry_interval']);
      }
    }
    throw new CRM_Omnimail_IncompleteDownloadException('Download incomplete', 0, array(
      'retrieval_parameters' => $result->getRetrievalParameters(),
      'mail_provider' => $params['mail_provider'],
      'end_date' => $this->endTimeStamp,
    ));

  }

}
