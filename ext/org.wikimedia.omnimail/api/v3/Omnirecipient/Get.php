<?php

/**
 * Get details about Omnimails.
 *
 * @param $params
 *
 * @return array
 *
 * @throws \CRM_Core_Exception
 * @throws \CRM_Omnimail_IncompleteDownloadException
 */
function civicrm_api3_omnirecipient_get($params) {
  $omnimail = new CRM_Omnimail_Omnirecipients($params);
  $result = $omnimail->getResult($params);
  $options = _civicrm_api3_get_options_from_params($params);
  $values = array();
  foreach ($result as $row) {
    $recipient = new \Omnimail\Silverpop\Responses\Recipient($row);
    $values[] = array(
      'contact_identifier' => (string) $recipient->getContactIdentifier(),
      'mailing_identifier' => (string) ($params['mailing_prefix'] ?? '') . $recipient->getMailingIdentifier(),
      'email' => (string) $recipient->getEmail(),
      'event_type' => (string) $recipient->getRecipientAction(),
      'recipient_action_datetime' => (string) $recipient->getRecipientActionIsoDateTime(),
      'contact_id' => (string) $recipient->getContactReference(),
    );
    if ($options['limit'] > 0 && count($values) === (int) $options['limit']) {
      break;
    }
  }
  return civicrm_api3_create_success($values);
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnirecipient_get_spec(&$params) {
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
    'api.default' => '3 days ago',
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  );
  $params['end_date'] = array(
    'title' => ts('Date to fetch to'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  );
  $params['mailing_external_identifier'] = array(
    'title' => ts('Identifier for the mailing'),
    'type' => CRM_Utils_Type::T_STRING,
  );
  $params['retrieval_parameters'] = array(
    'title' => ts('Additional information for retrieval of pre-stored requests'),
  );

}
