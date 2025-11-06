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
  protected string $job = 'omnirecipient';

  /**
   * @param array $params
   * @return \Omnimail\Silverpop\Responses\RecipientsResponse
   *
   * @throws \CRM_Core_Exception
   * @throws \CRM_Omnimail_IncompleteDownloadException
   * @throws \CRM_Core_Exception
   */
  public function getResult($params) {
    $settings = CRM_Omnimail_Helper::getSettings();

    $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);

    /** @var Omnimail\Silverpop\Requests\RawRecipientDataExportRequest $request */
    $request = Omnimail::create($params['mail_provider'], $mailerCredentials)->getRecipients();
    $request->setOffset($this->offset);

    $startTimestamp = $this->getStartTimestamp($params);
    $this->endTimeStamp = $this->getEndTimestamp($params['end_date'] ?? NULL, $settings, $startTimestamp);

    if ($this->getRetrievalParameters()) {
      $request->setRetrievalParameters($this->getRetrievalParameters());
    }
    elseif ($startTimestamp) {
      if ($this->endTimeStamp < $startTimestamp) {
        throw new CRM_Core_Exception(ts("End timestamp: " . date('Y-m-d H:i:s', $this->endTimeStamp) . " is before " . "Start timestamp: " . date('Y-m-d H:i:s', $startTimestamp)));
      }
      $request->setStartTimeStamp($startTimestamp);
      $request->setEndTimeStamp($this->endTimeStamp);
    }

    $result = $request->getResponse();
    $this->setRetrievalParameters($result->getRetrievalParameters());
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
      'retrieval_parameters' => $this->getRetrievalParameters(),
      'mail_provider' => $params['mail_provider'],
      'end_date' => $this->endTimeStamp,
    ));

  }

}
