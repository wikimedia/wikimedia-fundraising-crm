<?php

use Omnimail\Silverpop\Responses\RecipientsResponse;
use Omnimail\Omnimail;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/16/17
 * Time: 5:53 PM
 */

class CRM_Omnimail_Omnimail {

  /**
   * The job in use.
   *
   * This is used in settings names and should be overriden by child classes.
   *
   * @var string
   */
  protected $job;

  /**
   * @var string
   */
  public $endTimeStamp;

  /**
   * @var string
   */
  public $startTimeStamp;

  /**
   * @var int
   */
  protected $offset;

  /**
   * @var array
   */
  protected $jobSettings = array();

  /**
   * @var array
   */
  protected $settings = array();

  /**
   * @var array
   */
  protected $retrievalParameters;

  /**
   * @var string
   */
  protected $job_identifier;

  /**
   * @var string
   */
  protected $mail_provider;
  /**
   * CRM_Omnimail_Omnimail constructor.
   *
   * @param array $params
   *
   * @throws \API_Exception
   */
  public function __construct($params) {
    $this->job_identifier = !empty($params['job_identifier']) ? $params['job_identifier'] : NULL;
    $this->mail_provider = $params['mail_provider'];
    $this->settings = CRM_Omnimail_Helper::getSettings();
    $this->setJobSettings($params);
    $this->setOffset($params);
    $this->setRetrievalParameters(CRM_Utils_Array::value('retrieval_parameters', $this->jobSettings));

    if ($this->getRetrievalParameters()) {
      if (!empty($params['end_date']) || !empty($params['start_date'])) {
        throw new API_Exception('A prior retrieval is in progress. Do not pass in dates to complete a retrieval');
      }
    }
  }

  /**
   * @return array
   */
  public function getRetrievalParameters() {
    return $this->retrievalParameters;
  }

  /**
   * @param array $retrievalParameters
   */
  public function setRetrievalParameters($retrievalParameters) {
    $this->retrievalParameters = $retrievalParameters;
  }

  /**
   * Get the timestamp to start from.
   *
   * @param array $params
   *
   * @return string
   */
  public function getStartTimestamp($params) {
    if (!$this->startTimeStamp) {
      if (isset($params['start_date'])) {
        $this->startTimeStamp = strtotime($params['start_date']);
      }
      elseif (!empty($this->jobSettings['last_timestamp'])) {
        $this->startTimeStamp = $this->jobSettings['last_timestamp'];
      }
      else {
        $this->startTimeStamp = strtotime('450 days ago');
      }
    }
    return $this->startTimeStamp;
  }

  /**
   * Get the end timestamp, bearing in mind our poor ability to read the future.
   *
   * @param string $passedInEndDate
   * @param array $settings
   * @param string $startTimestamp
   *
   * @return false|int
   */
  protected static function getEndTimestamp($passedInEndDate, $settings, $startTimestamp) {
    if ($passedInEndDate) {
      $endTimeStamp = strtotime($passedInEndDate);
    }
    else {
      $adjustment = CRM_Utils_Array::value('omnimail_job_default_time_interval', $settings, ' + 1 day');
      $endTimeStamp = strtotime($adjustment, $startTimestamp);
    }
    return ($endTimeStamp > strtotime('now') ? strtotime('now') : $endTimeStamp);
  }

  /**
   * Get the settings for the job.
   *
   * This requires the child class to declare $this->job.
   *
   * @return array
   */
  public function getJobSettings() {
    return $this->jobSettings;
  }

  /**
   * @return int
   */
  public function getOffset() {
    return $this->offset;
  }

  /**
   * Set the offset to start loading from.
   *
   * This is the row in the csv file to start from in csv jobs.
   *
   * @param array $params
   *
   * @return mixed
   */
  protected function setOffset($params) {
    $this->offset = CRM_Utils_Array::value('offset', $this->jobSettings, 0);
    if (isset($params['options']['offset'])) {
      $this->offset = $params['options']['offset'];
    }
  }

  /**
   * Setter for job settings.
   *
   * @param array $params
   *   Api input parameters.
   */
  protected function setJobSettings($params) {
    $this->jobSettings = array(
      'mailing_provider' => $params['mail_provider'],
      'job' => 'omnimail_' . $this->job . '_load',
      'job_identifier' => $this->job_identifier ? : NULL,
    );
    $savedSettings = civicrm_api3('OmnimailJobProgress', 'get', $this->jobSettings);

    if ($savedSettings['count']) {
      foreach ($savedSettings['values'] as $savedSetting) {
        // filter for job_identifier since NULL will not have been respected.
        if (CRM_Utils_Array::value('job_identifier', $savedSetting) === $this->job_identifier) {
          foreach (array('last_timestamp', 'progress_end_timestamp') as $dateField) {
            if (isset($savedSetting[$dateField])) {
              $savedSetting[$dateField] = strtotime($savedSetting[$dateField]);
            }
          }
          if (isset($savedSetting['retrieval_parameters'])) {
            $savedSetting['retrieval_parameters'] = json_decode($savedSetting['retrieval_parameters'], TRUE);
          }
          $this->jobSettings = $savedSetting;
        }
      }
    }
  }

  /**
   * Save the job settings.
   *
   * @param array $setting
   */
  function saveJobSetting($setting) {
    $this->jobSettings = $setting = array_merge($this->jobSettings, $setting);
    foreach (array('last_timestamp', 'progress_end_timestamp') as $dateField) {
      if (isset($setting[$dateField]) && $setting[$dateField] !== 'null') {
        $setting[$dateField] = date('Y-m-d H:i:s', $setting[$dateField]);
      }
    }
    civicrm_api3('OmnimailJobProgress', 'create', $setting);
  }

}
