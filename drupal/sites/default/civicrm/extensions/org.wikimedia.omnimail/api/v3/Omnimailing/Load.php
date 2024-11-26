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
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_omnimailing_load($params) {
  $values = [];
  $getParams = [
    'mail_provider' => $params['mail_provider'],
    'start_date' => $params['start_date'],
    'end_date' => $params['end_date'],
    'debug' => !empty($params['debug']),
    'return' => [
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
      'number_clicked_total',
      'number_clicked_unique',
    ],
  ];
  if (isset($params['username']) && isset($params['password'])) {
    $getParams['username'] = $params['username'];
    $getParams['password'] = $params['password'];
  }
  if (isset($params['client'])) {
    $getParams['client'] = $params['client'];
  }
  $mailings = civicrm_api3('Omnimailing', 'get', $getParams);

  $customFields = civicrm_api3('CustomField', 'get', ['name' => ['IN' => ['query_criteria', 'query_string']], 'return' => ['id', 'name']])['values'];
  foreach ($customFields as $field) {
    if ($field['name'] === 'query_criteria') {
      $criteriaField = 'custom_' . $field['id'];
    }
    else {
      $listField = 'custom_' . $field['id'];
    }
  }
  foreach ($mailings['values'] as $mailing) {
    $campaign = _civicrm_api3_omnimailing_load_api_replace(
      'Campaign',
      ['name' => 'sp' . $mailing['external_identifier']],
      [
        'title' => 'sp' . $mailing['external_identifier'],
        'description' => _omnimailing_strip_emojis($mailing['subject']),
        'campaign_type_id' => 'Email',
        'start_date' => date('Y-m-d H:i:s', $mailing['start_date']),
        'status_id' => 'Completed',
      ]);

    CRM_Core_PseudoConstant::flush();

    $mailingParams = [
      'body_html' => !empty($mailing['body_html']) ? _omnimailing_strip_emojis($mailing['body_html']) : '',
      'body_text' => !empty($mailing['body_text']) ? _omnimailing_strip_emojis($mailing['body_text']) : '',
      'name' => !empty($mailing['name']) ? $mailing['name'] : 'sp' . $mailing['external_identifier'],
      'subject' => mb_substr(_omnimailing_strip_emojis($mailing['subject']), 0, 128),
      'created_date' => date('Y-m-d H:i:s', $mailing['scheduled_date']),
      'hash' => 'sp' . $mailing['external_identifier'],
      'scheduled_date' => date('Y-m-d H:i:s', $mailing['scheduled_date']),
      'campaign_id' => $campaign['id'],
    ];
    if (!empty(trim($mailing['list_criteria']))) {
      $mailingParams[$criteriaField] = $mailing['list_criteria'];
    }
    if (isset($mailing['list_string']) && !empty(trim($mailing['list_string']))) {
      $mailingParams[$listField] = $mailing['list_string'];
    }
    $result = _civicrm_api3_omnimailing_load_api_replace(
      'Mailing',
      ['hash' => 'sp' . $mailing['external_identifier']],
      $mailingParams,
      [
        'is_completed' => 1,
        '_skip_evil_bao_auto_recipients_' => 1,
        '_skip_evil_bao_auto_schedule_' => 1,
      ]
    );
    $values[] = $result;

    _civicrm_api3_omnimailing_load_api_replace(
      'MailingStats',
      ['mailing_id' => $result['id'], 'report_id' => $mailing['report_id']],
      [
        'mailing_id' => $result['id'],
        'report_id' => $mailing['report_id'],
        'is_multiple_report' => (int) $mailing['is_multiple_report'],
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
        'clicked_unique' => $mailing['number_clicked_unique'],
        'clicked_total' => $mailing['number_clicked_total'],
        'abuse_complaints' => $mailing['number_abuse_complaints'],
        // 'clicked_contribution_page'
        // 'contribution_count'
        // 'contribution_total'
      ]
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
  $params['username'] = [
    'title' => ts('User name'),
  ];
  $params['password'] = [
    'title' => ts('Password'),
  ];
  $params['mail_provider'] = [
    'title' => ts('Name of Mailer'),
    'api.default' => 'Silverpop',
  ];
  $params['start_date'] = [
    'title' => ts('Date to fetch from'),
    'api.default' => '1 week ago',
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  ];
  $params['end_date'] = [
    'title' => ts('Date to fetch to'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
    'api.default' => 'now',
  ];

}

/**
 * Replace entity with updated version as appropriate.
 *
 * This does what I thought 'replace' already did & does a retrieve + an insert if needed.
 *
 * @param string $entity
 * @param array $retrieveParams
 * @param array $updateParams
 * @param array $extraParams
 *
 * @return array
 *   Entity created or retrieved.
 * @todo centralise this somewhere to be available for other calls.
 *
 */
function _civicrm_api3_omnimailing_load_api_replace($entity, $retrieveParams, $updateParams, $extraParams = []) {
  $retrieveParams['return'] = array_keys($updateParams);
  $retrieveParams['sequential'] = 1;
  $preExisting = civicrm_api3($entity, 'get', $retrieveParams)['values'][0] ?? [];
  if (!$preExisting && !empty($retrieveParams['report_id'])) {
    // We might be repairing an old record from before we saved report_id
    $retrieveParams['report_id'] = ['IS NULL' => TRUE];
    $preExisting = civicrm_api3($entity, 'get', $retrieveParams)['values'][0] ?? [];
  }
  if (isset($preExisting['id'])) {
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


/**
 * Strip emojis from string.
 *
 * Currently our database does not support utfmb8. Our MariaDb level does but we would need
 * to convert all tables or at least the mailing ones - which would include the
 * huge mailing_provider_data table. For now let's hack it here.
 *
 * Main discussion taking place on https://github.com/civicrm/civicrm-core/pull/13633/files
 *
 * This code taken from http://scriptsof.com/php-remove-emojis-or-4-byte-characters-19
 *
 * @param string $string
 *
 * @return string|string[]|null
 */
function _omnimailing_strip_emojis($string) {
  return preg_replace('%(?:
          \xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
        | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
        | \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
    )%xs', '', $string);
}
