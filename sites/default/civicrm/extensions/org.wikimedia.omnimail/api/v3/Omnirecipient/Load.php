<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/3/17
 * Time: 12:46 PM
 */

/**
 * Load recipient details to mailing_provider_data table.
 *
 * Note that I originally wanted to use csv load functionality. Currently
 * mysql permissions forbid this but if we wish to revist this was the code that
 * was working:
 *
 * ```
 *  $query = '
 * LOAD DATA INFILE "' . $csvFile . '"
 * INTO TABLE my_table
 * FIELDS TERMINATED BY ","
 * OPTIONALLY ENCLOSED BY \'"\'
 * ESCAPED BY "\\\\"
 * LINES TERMINATED BY "\n"
 * IGNORE 1 LINES
 * (`' . implode('`,`', $columns) . '`)';
 * CRM_Core_DAO::executeQuery($query);
 * ````
 * @param $params
 *
 * @return array
 */
function civicrm_api3_omnirecipient_load($params) {
  try {
    $omnimail = new CRM_Omnimail_Omnirecipients($params);
    $recipients = $omnimail->getResult($params);
    $jobSettings = $omnimail->getJobSettings();

    $throttleSeconds = CRM_Utils_Array::value('throttle_seconds', $params);
    $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . ' seconds');
    $throttleCount = (int) CRM_Utils_Array::value('throttle_number', $params);
    $rowsLeftBeforeThrottle = $throttleCount;
    $limit = (isset($params['options']['limit'])) ? $params['options']['limit'] : NULL;
    $count = 0;
    $insertBatchSize = CRM_Utils_Array::value('insert_batch_size', $params, 1);
    $valueStrings = array();
    $progressSettings = array(
      'last_timestamp' => CRM_Utils_Array::value('last_timestamp', $jobSettings),
      'retrieval_parameters' => $omnimail->getRetrievalParameters(),
      'progress_end_timestamp' => $omnimail->endTimeStamp,
    );

    foreach ($recipients as $recipient) {
      if ($count === $limit) {
        // Do this here - ie. before processing a new row rather than at the end of the last row
        // to avoid thinking a job is incomplete if the limit co-incides with available rows.
        // Also write any remaining rows to the DB before exiting.
        _civicrm_api3_omnirecipient_load_write_remainder_rows($valueStrings, $omnimail, $progressSettings, $omnimail->getOffset() + $count);
        return civicrm_api3_create_success(1);
      }
      $insertValues = array(
        1 => array((string) $recipient->getContactIdentifier(), 'String'),
        2 => array(
          (string) CRM_Utils_Array::value('mailing_prefix', $params, '') . $recipient->getMailingIdentifier(),
          'String'
        ),
        3 => array((string) $recipient->getEmail(), 'String'),
        4 => array((string) $recipient->getRecipientAction(), 'String'),
        5 => array(
          (string) $recipient->getRecipientActionIsoDateTime(),
          'String'
        ),
        6 => array((string) $recipient->getContactReference(), 'String'),
      );
      $rowsLeftBeforeThrottle--;
      $count++;

      $valueStrings[] = CRM_Core_DAO::composeQuery("(%1, %2, %3, %4, %5, %6 )", $insertValues);
      $valueStrings = _civicrm_api3_omnirecipient_load_batch_write_to_db($valueStrings, $insertBatchSize, $omnimail, $progressSettings, $omnimail->getOffset() + $count);

      if ($throttleStagePoint && (strtotime('now') > $throttleStagePoint)) {
        $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . 'seconds');
        $rowsLeftBeforeThrottle = $throttleCount;
      }
      if ($throttleSeconds && $rowsLeftBeforeThrottle <= 0) {
        sleep(ceil($throttleStagePoint - strtotime('now')));
      }
    }
    _civicrm_api3_omnirecipient_load_write_remainder_rows($valueStrings, $omnimail, $progressSettings, $omnimail->getOffset() + $count);
    $omnimail->saveJobSetting(array(
      'last_timestamp' => $omnimail->endTimeStamp,
      'progress_end_timestamp' => 'null',
      'offset' => 'null',
      'retrieval_parameters' => 'null',
    ));
    return civicrm_api3_create_success(1);
  }
  catch (CRM_Omnimail_IncompleteDownloadException $e) {
    $omnimail->saveJobSetting(array(
      'last_timestamp' => $omnimail->getStartTimestamp($params),
      'retrieval_parameters' => $e->getRetrievalParameters(),
      'progress_end_timestamp' => $e->getEndTimestamp(),
    ));
    return civicrm_api3_create_success(1);
  }

}

/**
 * Write remaining rows, if any, to the database.
 *
 * @param array $valueStrings
 * @param \CRM_Omnimail_Omnirecipients $job
 * @param array $jobSettings
 * @param int $newOffSet
 */
function _civicrm_api3_omnirecipient_load_write_remainder_rows($valueStrings, $job, $jobSettings, $newOffSet) {
  if (count($valueStrings)) {
    _civicrm_api3_omnirecipient_load_batch_write_to_db($valueStrings, count($valueStrings), $job, $jobSettings, $newOffSet);
  }
}

/**
 * Write the imported values to the DB, batching per parameter.
 *
 * Save the values to the DB in batch sizes accordant with the insertBatchSize
 * parameter. Also store where we are up to in setting.
 *
 * @param array $valueStrings
 * @param int $insertBatchSize
 * @param \CRM_Omnimail_Omnirecipients $job
 * @param array $jobSettings
 * @param int $newOffSet
 *
 * @return array
 */
function _civicrm_api3_omnirecipient_load_batch_write_to_db($valueStrings, $insertBatchSize, $job, $jobSettings, $newOffSet) {
  if (count($valueStrings) === $insertBatchSize) {
    CRM_Core_DAO::executeQuery("
         INSERT IGNORE INTO civicrm_mailing_provider_data
         (`contact_identifier`, `mailing_identifier`, `email`, `event_type`, `recipient_action_datetime`, `contact_id`)
         values" . implode(',', $valueStrings)
    );
    $job->saveJobSetting(array_merge($jobSettings, array('offset' => $newOffSet)));
    $valueStrings = array();
  }
  return $valueStrings;
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnirecipient_load_spec(&$params) {
  $params['username'] = array(
    'title' => ts('User name'),
  );
  $params['password'] = array(
    'title' => ts('Password'),
  );
  $params['mail_provider'] = array(
    'title' => ts('Name of Mailer'),
    'api.required' => TRUE,
  );
  $params['start_date'] = array(
    'title' => ts('Date to fetch from'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  );
  $params['end_date'] = array(
    'title' => ts('Date to fetch to'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  );
  $params['mailing_prefix'] = array(
    'title' => ts('A prefix to prepend to the mailing_identifier when storing'),
    'type' => CRM_Utils_Type::T_STRING,
  );
  $params['retrieval_parameters'] = array(
    'title' => ts('Additional information for retrieval of pre-stored requests'),
  );
  $params['table_name'] = array(
    'title' => ts('Name of table to store to'),
    'type' => CRM_Utils_Type::T_STRING,
  );
  $params['throttle_number'] = array(
    'title' => ts('Number of inserts to throttle after'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 100000,
  );
  $params['throttle_seconds'] = array(
    'title' => ts('Throttle after the number has been reached in this number of seconds'),
    'description' => ts('If the throttle limit is passed before this number of seconds is reached php will sleep until it hits it.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 300,
  );
  $params['job_suffix'] = array(
    'title' => ts('A suffix string to add to job-specific settings.'),
    'description' => ts('The suffix allows for multiple settings to be stored for one job. For example if wishing to run an up-top-date job and a catch-up job'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.default' => '',
  );
  $params['insert_batch_size'] = array(
    'title' => ts('Number of rows to insert in each DB write'),
    'description' => ts('Set this to increase row batching.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 1,
  );

}
