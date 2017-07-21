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
  $mailings = civicrm_api3('Omnimailing', 'get', array(
    'mail_provider' => 'Silverpop',
    'start_date' => $params['start_date'],
    'end_date' => $params['end_date'],
    'username' => $params['username'],
    'password' => $params['password'],
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
  ));

  foreach ($mailings['values']  as $mailing) {
    $campaign = civicrm_api3('Campaign', 'replace', array(
      'name' => 'sp' . $mailing['external_identifier'],
      'values' => array(
        array(
          'title' => 'sp' . $mailing['external_identifier'],
          'description' => $mailing['subject'],
          'campaign_type_id' => 'Email',
          'start_date' => date('Y-m-d H:i:s', $mailing['start_date']),
          'status_id' => 'Completed',
        )
      )
    ));
    CRM_Core_PseudoConstant::flush();
    $result = civicrm_api3('Mailing', 'replace', array(
      'hash' => 'sp' . $mailing['external_identifier'],
      'debug' => 1,
      'values' => array(
        array(
          'body_html' => !empty($mailing['body_html']) ? $mailing['body_html'] : '',
          'body_text' => !empty($mailing['body_text']) ? $mailing['body_text'] : '',
          'name' => !empty($mailing['name']) ? $mailing['name'] : 'sp' . $mailing['external_identifier'],
          'subject' => $mailing['subject'],
          'created_date' => date('Y-m-d H:i:s', $mailing['scheduled_date']),
          'hash' => 'sp' . $mailing['external_identifier'],
          'scheduled_date' => date('Y-m-d H:i:s', $mailing['scheduled_date']),
          'campaign_id' => $campaign['id'],
          'is_completed' => 1,
        )
      ),
    ));
    $values[] = $result['values'][$result['id']];
    civicrm_api3('MailingStats', 'replace', array(
      'mailing_id' => $result['id'],
      'values' => array(
        array(
          'debug' => 1,
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
          'suppressed' => $mailing['number_unsuppressed'],
          // 'forwarded'
          'blocked' => $mailing['number_blocked'],
          // 'clicked_total' => $stats['NumGrossClick'],
          'abuse_complaints' => $mailing['number_abuse_complaints'],
          // 'clicked_contribution_page'
          // 'contribution_count'
          // 'contribution_total'
        )
      ),
    ));
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
