<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/3/17
 * Time: 12:46 PM
 */

/**
 * Get details about Omnimails.
 *
 * @param $params
 *
 * @return array
 */
function civicrm_api3_omnimailing_load($params) {
  $values = array();
  $getParams = array(
    'mail_provider' => $params['mail_provider'],
    'start_date' => $params['start_date'],
    'end_date' => $params['end_date'],
    'return' => array(
      'external_identifier',
      'subject',
      'scheduled_date',
      'start_date',
      'number_sent',
      'body_html',
      'body_text',
      'number_abuse_complaints',
      'number_unsuppressed',
      'number_subscribed',
      'number_unsubscribed',
      'number_opened_unique',
      'number_opened_total',
      'number_bounced',
      'number_sent',
    ),
  );
  if (isset($params['username']) && isset($params['password'])) {
    $getParams['username'] = $params['username'];
    $getParams['password'] = $params['password'];
  }
  if (isset($params['client'])) {
    $getParams['client'] = $params['client'];
  }
  $mailings = civicrm_api3('Omnimailing', 'get', $getParams);

  foreach ($mailings['values']  as $mailing) {
    $campaign = _civicrm_api3_omnimailing_load_api_replace(
      'Campaign',
      array('name' => 'sp' . $mailing['external_identifier']),
      array(
        'title' => 'sp' . $mailing['external_identifier'],
        'description' => $mailing['subject'],
        'campaign_type_id' => 'Email',
        'start_date' => date('Y-m-d H:i:s', $mailing['start_date']),
        'status_id' => 'Completed',
    ));

    CRM_Core_PseudoConstant::flush();

    $result =  _civicrm_api3_omnimailing_load_api_replace(
      'Mailing',
      array('hash' => 'sp' . $mailing['external_identifier']),
      array(
        'body_html' => !empty($mailing['body_html']) ? $mailing['body_html'] : '',
        'body_text' => !empty($mailing['body_text']) ? $mailing['body_text'] : '',
        'name' => !empty($mailing['name']) ? $mailing['name'] : 'sp' . $mailing['external_identifier'],
        'subject' => substr($mailing['subject'], 0, 128),
        'created_date' => date('Y-m-d H:i:s', $mailing['scheduled_date']),
        'hash' => 'sp' . $mailing['external_identifier'],
        'scheduled_date' => date('Y-m-d H:i:s', $mailing['scheduled_date']),
        'campaign_id' => $campaign['id'],
      ),
      array(
        'is_completed' => 1,
        '_skip_evil_bao_auto_recipients_' => 1,
        '_skip_evil_bao_auto_schedule_' => 1,
      )
    );
    $values[] = $result;

    _civicrm_api3_omnimailing_load_api_replace(
      'MailingStats',
      array('mailing_id' => $result['id']),
      array(
        'mailing_id' => $result['id'],
        'mailing_name' => !empty($mailing['name']) ? $mailing['name'] : 'sp' . $mailing['external_identifier'],
        'is_completed' => TRUE,
        'created_date' => date('Y-m-d H:i:s', $mailing['scheduled_date']),
        'start' => date('Y-m-d H:i:s', $mailing['start_date']),
        //'finish' =>
        'recipients' => $mailing['number_sent'],
        'delivered' => $mailing['number_sent'] - $mailing['number_bounced'],
        // 'send_rate'
        'bounced' => $mailing['number_bounced'],
        'opened_total' => $mailing['number_opened_total'],
        'opened_unique' => $mailing['number_opened_unique'],
        'unsubscribed' => $mailing['number_unsubscribed'],
        'suppressed' => $mailing['number_suppressed'],
        // 'forwarded'
        'blocked' => $mailing['number_blocked'],
        // 'clicked_total' => $stats['NumGrossClick'],
        'abuse_complaints' => $mailing['number_abuse_complaints'],
        // 'clicked_contribution_page'
        // 'contribution_count'
        // 'contribution_total'
      )
    );
  }
  return civicrm_api3_create_success($values);
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnimailing_load_spec(&$params) {
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
    'api.default' => '1 week ago',
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  );
  $params['end_date'] = array(
    'title' => ts('Date to fetch to'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
    'api.default' => 'now',
  );

}

/**
 * Replace entity with updated version as appropriate.
 *
 * This does what I thought 'replace' already did & does a retrieve + an insert if needed.
 *
 * @todo centralise this somewhere to be available for other calls.
 *
 * @param string $entity
 * @param array $retrieveParams
 * @param array $updateParams
 * @param array $extraParams
 *
 * @return array
 *   Entity created or retrieved.
 */
function _civicrm_api3_omnimailing_load_api_replace($entity, $retrieveParams, $updateParams, $extraParams = array()) {
  $retrieveParams['return'] = array_keys($updateParams);
  $preExisting = civicrm_api3($entity, 'get', $retrieveParams);
  if (isset($preExisting['id'])) {
    $preExisting = $preExisting['values'][$preExisting['id']];
    foreach ($updateParams as $key => $updateParam) {
      if (CRM_Utils_Array::value($key, $preExisting) === $updateParam) {
        unset($updateParams[$key]);
      }
    }
    if (empty($updateParams)) {
      return $preExisting;
    }
    $updateParams['id'] = $preExisting['id'];
  }
  $created = civicrm_api3($entity, 'create', array_merge($updateParams, $extraParams));
  return $created['values'][$created['id']];
}
