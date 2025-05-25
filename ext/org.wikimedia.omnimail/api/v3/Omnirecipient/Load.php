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
 *
 * @param $params
 *
 * @return array
 *
 * @throws \CRM_Core_Exception
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
    $limit = $params['options']['limit'] ?? NULL;
    $count = 0;
    $insertBatchSize = CRM_Utils_Array::value('insert_batch_size', $params, 1);
    $valueStrings = [];
    $progressSettings = [
      'last_timestamp' => CRM_Utils_Array::value('last_timestamp', $jobSettings),
      'retrieval_parameters' => $omnimail->getRetrievalParameters(),
      'progress_end_timestamp' => $omnimail->endTimeStamp,
    ];
    $omnimail->debug('omnirecipient_retrieval_initiated', $progressSettings);

    foreach ($recipients as $row) {
      $recipient = new  \Omnimail\Silverpop\Responses\Recipient($row);
      if ($count === $limit) {
        // Do this here - ie. before processing a new row rather than at the end of the last row
        // to avoid thinking a job is incomplete if the limit co-incides with available rows.
        // Also write any remaining rows to the DB before exiting.
        _civicrm_api3_omnirecipient_load_write_remainder_rows($valueStrings, $omnimail, $progressSettings, $omnimail->getOffset() + $count, 'omnirecipient_batch_limit_reached');
        return civicrm_api3_create_success(1);
      }
      $insertValues = [
        1 => [(string) $recipient->getContactIdentifier(), 'String'],
        2 => [
          (string) CRM_Utils_Array::value('mailing_prefix', $params, '') . $recipient->getMailingIdentifier(),
          'String',
        ],
        3 => [(string) $recipient->getEmail(), 'String'],
        4 => [(string) $recipient->getRecipientAction(), 'String'],
        5 => [
          (string) $recipient->getRecipientActionIsoDateTime(),
          'String',
        ],
        6 => [(string) $recipient->getContactReference() ?: 'NULL', 'String'],
      ];
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
    $omnimail->saveJobSetting([
      'last_timestamp' => $omnimail->endTimeStamp,
      'progress_end_timestamp' => 'null',
      'offset' => 'null',
      'retrieval_parameters' => 'null',
    ], 'omnirecipient_file_fully_processed');
    return civicrm_api3_create_success(1);
  }
  catch (CRM_Omnimail_IncompleteDownloadException $e) {
    $omnimail->saveJobSetting([
      'last_timestamp' => $omnimail->getStartTimestamp($params),
      'retrieval_parameters' => $e->getRetrievalParameters(),
      'progress_end_timestamp' => $e->getEndTimestamp(),
    ], 'omnirecipient_incomplete_download');
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
 * @param string $loggingContext
 *
 * @throws \CRM_Core_Exception
 */
function _civicrm_api3_omnirecipient_load_write_remainder_rows($valueStrings, $job, $jobSettings, $newOffSet, string $loggingContext = '') {
  if (count($valueStrings)) {
    _civicrm_api3_omnirecipient_load_batch_write_to_db($valueStrings, count($valueStrings), $job, $jobSettings, $newOffSet, $loggingContext);
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
 * @param string $loggingContext
 *
 * @return array
 * @throws \CRM_Core_Exception
 */
function _civicrm_api3_omnirecipient_load_batch_write_to_db($valueStrings, $insertBatchSize, $job, $jobSettings, $newOffSet, string $loggingContext = '') {
  if (count($valueStrings) === $insertBatchSize) {
    $values = implode(',', $valueStrings);
    $values = str_replace("'NULL'", 'NULL', $values);
    CRM_Core_DAO::executeQuery('
         INSERT IGNORE INTO civicrm_mailing_provider_data
         (`contact_identifier`, `mailing_identifier`, `email`, `event_type`, `recipient_action_datetime`, `contact_id`)
         values' . $values
    );
    $job->saveJobSetting(array_merge($jobSettings, ['offset' => $newOffSet]), $loggingContext);
    $valueStrings = [];
  }
  return $valueStrings;
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnirecipient_load_spec(&$params) {
  $params['username'] = [
    'title' => ts('User name'),
  ];
  $params['password'] = [
    'title' => ts('Password'),
  ];
  $params['mail_provider'] = [
    'title' => ts('Name of Mailer'),
    'api.required' => TRUE,
    'api.default' => 'Silverpop',
  ];
  $params['start_date'] = [
    'title' => ts('Date to fetch from'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  ];
  $params['end_date'] = [
    'title' => ts('Date to fetch to'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  ];
  $params['mailing_prefix'] = [
    'title' => ts('A prefix to prepend to the mailing_identifier when storing'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.default' => 'sp',
  ];
  $params['retrieval_parameters'] = [
    'title' => ts('Additional information for retrieval of pre-stored requests'),
  ];
  $params['table_name'] = [
    'title' => ts('Name of table to store to'),
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $params['throttle_number'] = [
    'title' => ts('Number of inserts to throttle after'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 100000,
  ];
  $params['throttle_seconds'] = [
    'title' => ts('Throttle after the number has been reached in this number of seconds'),
    'description' => ts('If the throttle limit is passed before this number of seconds is reached php will sleep until it hits it.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 300,
  ];
  $params['job_identifier'] = [
    'title' => ts('A string to identify this job.'),
    'description' => ts('The identifier allows for multiple settings to be stored for one job. For example if wishing to run an up-top-date job and a catch-up job'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.default' => '',
  ];
  $params['insert_batch_size'] = [
    'title' => ts('Number of rows to insert in each DB write'),
    'description' => ts('Set this to increase row batching.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 1,
  ];
  $params['php_only_offset'] = [
    'title' => ts('Force the php timezone'),
    'description' => ts('Permit forcing of the php offset timezone separately from the mysql offset'),
    'type' => CRM_Utils_Type::T_INT,
  ];

}
