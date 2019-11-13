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
   * @return \Omnimail\Silverpop\Responses\RecipientsResponse
   *
   * @throws \API_Exception
   * @throws \CRM_Omnimail_IncompleteDownloadException
   * @throws \CiviCRM_API3_Exception
   */
  public function getResult($params) {
    $settings = CRM_Omnimail_Helper::getSettings();
    $this->forceTimeZones($params);

    $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);

    /** @var Omnimail\Silverpop\Requests\RawRecipientDataExportRequest $request */
    $request = Omnimail::create($params['mail_provider'], $mailerCredentials)->getRecipients();
    $request->setOffset($this->offset);

    $startTimestamp = $this->getStartTimestamp($params);
    $this->endTimeStamp = self::getEndTimestamp(CRM_Utils_Array::value('end_date', $params), $settings, $startTimestamp);

    if ($this->getRetrievalParameters()) {
      $request->setRetrievalParameters($this->getRetrievalParameters());
    }
    elseif ($startTimestamp) {
      if ($this->endTimeStamp < $startTimestamp) {
        throw new API_Exception(ts("End timestamp: " . date('Y-m-d H:i:s', $this->endTimeStamp) . " is before " . "Start timestamp: " . date('Y-m-d H:i:s', $startTimestamp)));
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

  /**
   * Force the mysql & php timezones.
   *
   * Normally the way it works is that drupal sets a timezone per user and puts php into that timezone.
   *
   * CiviCRM interrogates the drupal settings to find out the offset & sets the mysql timezone.
   * As long as the 2 are in sync all is good when writing to timestamp fields - php interprets the dates
   * the correct value per it's timezone & passes it to mysql which saves it, converting by it's set timezone
   * to UTC.
   *
   * Normally this is all handled by drupal & Civi & works fine. However, I determined that the timezone for
   * user 1 is set to UTC on our live site. Civi is correctly setting the mysql timezone to 0 offset for that user.
   * However, drupal is not (for unknown reasons) correctly setting the php time to UTC & instead it's being set
   * to the site-wide America/Los_Angeles time.
   *
   * While analysing the above it makes sense to just force both to UTC during this script. However, I've separately
   * identified some backfill gaps I need to do & since they have run with the wrong time stamps and we largely don't care
   * as long as they don't duplicate rows (ie. calculate the same timestamp as happened last time) and the messed up
   * offset varies by time of year (daylight savings), I'm adding the ability to override & manufacture an offset.
   * It's going to take a little nasty tinkering when running the script :-(
   *
   * @param array $params
   */
  protected function forceTimeZones($params) {
    if (isset($params['php_only_offset'])) {
      $timezone = timezone_name_from_abbr('', 60 * 60 * $params['php_only_offset'], 0);
      date_default_timezone_set($timezone);
    }
    else {
      date_default_timezone_set('UTC');
      CRM_Core_DAO::executeQuery("SET TIME_ZONE='+00:00'");
    }
  }

}
