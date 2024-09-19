<?php
/**
 * Process items queued for forget me.
 */

require_once 'vendor/autoload.php';

/**
 * Get details about Recipients.
 *
 * @param $params
 *
 * @return array
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_omnirecipient_process_forgetme($params) {
  $forgets = civicrm_api3('OmnimailJobProgress', 'get', ['job' => 'omnimail_privacy_erase', 'mailing_provider' => $params['mail_provider']]);

  \Civi::log('wmf')->info('Forgetting {count} emails',[
    'count' => $forgets['count']
  ]);

  foreach ($forgets['values'] as $forget) {
    $result = civicrm_api3('Omnirecipient', 'erase', [
      'email' => json_decode($forget['job_identifier'], TRUE),
      'mail_provider' => $params['mail_provider'],
      'retrieval_parameters' => (isset($forget['retrieval_parameters']) ? json_decode($forget['retrieval_parameters'], TRUE) : []),
    ])['values'];

    foreach ($result as $index => $response) {
      if (!$response['is_completed']) {
        // We might have more than one Silverpop DB, and a job per DB. Add a row to track them.
        civicrm_api3('OmnimailJobProgress', 'create', [
          'retrieval_parameters' => $response['retrieval_parameters'],
          'mailing_provider' => $forget['mailing_provider'],
          'job' => $forget['job'],
          'job_identifier' => $forget['job_identifier'],
          // Carry over the original created date.
          'created_date' => $forget['created_date'],
        ]);
      }
    }
    // If the job is still in progress we will have created a new row so we can delete the row we had
    // knowing if it's not finished it will have been replaced by one or more rows.
    civicrm_api3('OmnimailJobProgress', 'delete', ['id' => $forget['id']]);
  }

}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnirecipient_process_forgetme_spec(&$params) {
  $params['mail_provider']['api.default'] = 'Silverpop';
}
