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

    foreach ($recipients as $recipient) {
      if ($count === $limit) {
        civicrm_api3('Setting', 'create', array(
          'omnimail_omnirecipient_load' => array(
            $params['mail_provider'] => array(
              'last_timestamp' => $jobSettings['last_timestamp'],
              'retrieval_parameters' => $omnimail->getRetrievalParameters(),
              'progress_end_date' => $omnimail->endTimeStamp,
              'offset' => $omnimail->getOffset() + $count,
            ),
          ),
        ));
        // Do this here - ie. before processing a new row rather than at the end of the last row
        // to avoid thinking a job is incomplete if the limit co-incides with available rows.
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
      CRM_Core_DAO::executeQuery("
         INSERT IGNORE INTO civicrm_mailing_provider_data
         (`contact_identifier`, `mailing_identifier`, `email`, `event_type`, `recipient_action_datetime`, `contact_id`)
         values(%1, %2, %3, %4, %5, %6 )",
        $insertValues);

      $rowsLeftBeforeThrottle--;
      $count++;
      if ($throttleStagePoint && (strtotime('now') > $throttleStagePoint)) {
        $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . 'seconds');
        $rowsLeftBeforeThrottle = $throttleCount;
      }
      if ($throttleSeconds && $rowsLeftBeforeThrottle <= 0) {
        sleep(ceil($throttleStagePoint - strtotime('now')));
      }
    }
    civicrm_api3('Setting', 'create', array(
      'omnimail_omnirecipient_load' => array(
        $params['mail_provider'] => array('last_timestamp' => $omnimail->endTimeStamp),
      ),
    ));
    return civicrm_api3_create_success(1);
  }
  catch (CRM_Omnimail_IncompleteDownloadException $e) {
    civicrm_api3('Setting', 'create', array(
      'omnimail_omnirecipient_load' => array(
        $params['mail_provider'] => array(
          'last_timestamp' => $omnimail->getStartTimestamp($params),
          'retrieval_parameters' => $e->getRetrievalParameters(),
          'progress_end_date' => $e->getEndTimestamp(),
        ),
      ),
    ));
    return civicrm_api3_create_success(1);
  }

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

}
