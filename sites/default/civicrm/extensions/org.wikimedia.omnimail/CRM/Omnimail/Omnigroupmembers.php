<?php

use Omnimail\Silverpop\Responses\RecipientsResponse;
use Omnimail\Omnimail;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton@wikimedia.org
 * Date: 5/16/17
 * Time: 5:53 PM
 */

class CRM_Omnimail_Omnigroupmembers extends CRM_Omnimail_Omnimail{

  /**
   * @var 
   */
  protected $request;

  /**
   * @var string
   */
  protected $job = 'omnigroupmembers';

  /**
   * @param array $params
   *
   * @return \Omnimail\Silverpop\Responses\GroupMembersResponse
   *
   * @throws \CRM_Omnimail_IncompleteDownloadException
   * @throws \API_Exception
   */
  public function getResult($params) {
    $jobSettings = self::getJobSettings($params);
    $settings = CRM_Omnimail_Helper::getSettings();

    $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);
    $jobParameters = array();
    if ($params['is_opt_in_only']) {
      $jobParameters['exportType'] = 'OPT_IN';
    }

    $request = Omnimail::create($params['mail_provider'], $mailerCredentials)->getGroupMembers($jobParameters);
    $offset = CRM_Utils_Array::value('offset', $jobSettings, 0);
    if (isset($params['options']['offset'])) {
      $offset = $params['options']['offset'];
    }
    if ($offset) {
      $request->setOffset((int) $offset);
    }

    $startTimestamp = self::getStartTimestamp($params, $jobSettings);
    $this->endTimeStamp = self::getEndTimestamp(CRM_Utils_Array::value('end_date', $params), $settings, $startTimestamp);

    if (isset($jobSettings['retrieval_parameters'])) {
      if (!empty($params['end_date']) || !empty($params['start_date'])) {
        throw new API_Exception('A prior retrieval is in progress. Do not pass in dates to complete a retrieval');
      }
      $request->setRetrievalParameters($jobSettings['retrieval_parameters']);
    }
    elseif ($startTimestamp) {
      $request->setStartTimeStamp($startTimestamp);
      $request->setEndTimeStamp($this->endTimeStamp);
    }
    $request->setGroupIdentifier($params['group_identifier']);

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
   * Format the result into the fields we with to return.
   *
   * @param array $params
   * @param \Omnimail\Silverpop\Responses\Contact $result
   *
   * @return array
   */
  public function formatResult($params, $result) {
    $options = _civicrm_api3_get_options_from_params($params);
    $values = array();
    foreach ($result as $groupMember) {
      $value = $this->formatRow($groupMember, $params['custom_data_map']);
      $values[] = $value;
      if ($options['limit'] > 0 && count($values) === (int) $options['limit']) {
        break;
      }
    }
    return $values;
  }

  /**
   * Format a single row of the result.
   *
   * @param $groupMember
   * @param array $customDataMap
   *   - Mapping of provider fields to desired output fields.
   *
   * @return array
   */
  public function formatRow($groupMember, $customDataMap) {
    $value = array(
      'email' => (string) $groupMember->getEmail(),
      'is_opt_out' => (string) $groupMember->isOptOut(),
      'opt_in_date' => (string) $groupMember->getOptInIsoDateTime(),
      'opt_in_source' => (string) $groupMember->getOptInSource(),
      'opt_out_source' => (string) $groupMember->getOptOutSource(),
      'opt_out_date' => (string) $groupMember->getOptOutIsoDateTime(),
      'contact_id' => (string) $groupMember->getContactReference(),
    );
    foreach ($customDataMap as $fieldName => $dataKey) {
      $value[$fieldName] = (string) $groupMember->getCustomData($dataKey);
    }
    return $value;
  }


}
