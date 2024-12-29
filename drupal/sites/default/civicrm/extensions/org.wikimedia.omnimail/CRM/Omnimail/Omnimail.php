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
  protected array $jobSettings = [];

  /**
   * @var array
   */
  protected array $settings = [];

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
   * @throws \CRM_Core_Exception
   */
  public function __construct($params) {
    $this->job_identifier = !empty($params['job_identifier']) ? $params['job_identifier'] : NULL;
    $this->mail_provider = $params['mail_provider'];
    $this->forceTimeZones($params);
    $this->settings = CRM_Omnimail_Helper::getSettings();
    $this->setJobSettings($params);
    $this->setOffset($params);
    $this->setRetrievalParameters($this->jobSettings['retrieval_parameters'] ?? NULL);

    if ($this->getRetrievalParameters()) {
      if (!empty($params['end_date']) || !empty($params['start_date'])) {
        throw new CRM_Core_Exception('A prior retrieval is in progress. Do not pass in dates to complete a retrieval');
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
  protected function getEndTimestamp($passedInEndDate, $settings, $startTimestamp) {
    if ($passedInEndDate) {
      $endTimeStamp = strtotime($passedInEndDate);
    }
    elseif (!empty($this->jobSettings['progress_end_timestamp'])) {
      $endTimeStamp = $this->jobSettings['progress_end_timestamp'];
    }
    else {
      $adjustment = CRM_Utils_Array::value('omnimail_job_default_time_interval', $settings, ' + 1 day');
      $endTimeStamp = strtotime($adjustment, $startTimestamp);
    }
    return ($endTimeStamp > time() ? time() : $endTimeStamp);
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
   */
  protected function setOffset(array $params): void {
    $this->offset = $this->jobSettings['offset'] ?? 0;
    if (isset($params['options']['offset'])) {
      $this->offset = $params['options']['offset'];
    }
  }

  /**
   * Setter for job settings.
   *
   * @param array $params
   *   Api input parameters.
   *
   * @throws \CRM_Core_Exception
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
   *
   * @param string $loggingContext
   *   Logging context - if this is not empty a debug row will be logged with the progress details.
   *   (it is good to log at the start & end of jobs, less so when we are just incrementing offset).
   *
   * @throws \CRM_Core_Exception
   */
  public function saveJobSetting($setting, string $loggingContext = '') {
    $setting = array_merge($this->jobSettings, $setting);
    foreach (array('last_timestamp', 'progress_end_timestamp') as $dateField) {
      if (isset($setting[$dateField]) && $setting[$dateField] !== 'null') {
        $setting[$dateField] = date('Y-m-d H:i:s', $setting[$dateField]);
      }
    }
    $this->jobSettings = $setting;
    $progress = civicrm_api3('OmnimailJobProgress', 'create', $setting);
    if (empty($this->jobSettings['id'])) {
      $this->jobSettings['id'] = $progress['id'];
    }
    if ($loggingContext) {
      $this->debug($loggingContext, $this->jobSettings);
    }
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

  /**
   * Debug information about omnimail.
   *
   * I would have preferred to use the Civi::log format but hit limitations - see
   * https://lab.civicrm.org/dev/core/issues/1527 - perhaps in future we can switch to that.
   *
   * @param string $message
   * @param array $variables
   */
  public function debug(string $message, $variables) {
    CRM_Core_Error::debug_log_message($message . "\n" . print_r($variables, TRUE), FALSE, 'omnimail', PEAR_LOG_DEBUG);
  }

}
