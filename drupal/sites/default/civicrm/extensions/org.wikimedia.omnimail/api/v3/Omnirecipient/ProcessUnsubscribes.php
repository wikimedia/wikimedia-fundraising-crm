<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/3/17
 * Time: 12:46 PM
 */
// Include the library
require_once 'vendor/autoload.php';

/**
 * Get details about Recipients.
 *
 * @param $params
 *
 * @return array
 */
function civicrm_api3_omnirecipient_process_unsubscribes($params) {
  $params['return'] = array('mailing_identifier.campaign_id.name', 'email', 'contact_identifier', 'contact_id', 'mailing_identifier', 'recipient_action_datetime', 'event_type');
  $params['is_civicrm_updated'] = 0;
  $params['contact_id'] = array('BETWEEN' => [1, 999999999]);
  $result = civicrm_api3('MailingProviderData', 'get', $params);

  \Civi::log('wmf')->info('Unsubscribing {count} emails',[
    'count' => $result['count']
  ]);


  foreach ($result['values'] as $unsubscribes) {
    CRM_Core_DAO::executeQuery('SET @uniqueID = %1', [
      1 => [
        uniqid() . CRM_Utils_String::createRandom(4, CRM_Utils_String::ALPHANUMERIC),
        'String',
      ],
    ]);
    civicrm_api3('Activity', 'create', array(
      'activity_type_id' => 'Unsubscribe',
      'campaign_id' => CRM_Utils_Array::value('mailing_identifier.campaign_id.name', $unsubscribes),
      'target_contact_id' => $unsubscribes['contact_id'],
      'source_contact_id' =>  $unsubscribes['contact_id'],
      'activity_date_time' => $unsubscribes['recipient_action_datetime'],
      'subject' => ts('Unsubscribed via ' . (isset($params['mail_provider']) ? $params['mail_provider'] : ts('Mailing provider')))
    ));
    civicrm_api3('Contact', 'create', array(
      'is_opt_out' => 1,
      'id' => $unsubscribes['contact_id']
    ));
    if (!empty($unsubscribes['email'])) {
      $emails = civicrm_api3('Email', 'get', array(
        'email' => $unsubscribes['email'],
        'is_bulkmail' => 1
      ));
      foreach ($emails['values'] as $email) {
        civicrm_api3('Email', 'create', array(
          'id' => $email['id'],
          'is_bulkmail' => 0
        ));
      }
    }

    CRM_Core_DAO::executeQuery('
      UPDATE civicrm_mailing_provider_data SET is_civicrm_updated = 1 WHERE contact_identifier = %1 AND recipient_action_datetime = %2 AND event_type = %3', array(
      1 => array($unsubscribes['contact_identifier'], 'String'),
      2 => array($unsubscribes['recipient_action_datetime'], 'String'),
      3 => array($unsubscribes['event_type'], 'String'),
    ));
  }

  return civicrm_api3_create_success(1);
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnirecipient_process_unsubscribes_spec(&$params) {
  $params['event_type'] = array(
    'api.default' => array('IN' => array('Opt Out', 'Reply Abuse')),
    'options' => array(
      'Opt Out' => 'Opt Out',
      'Hard Bounce' => 'Hard Bounce',
      'Reply Abuse' => 'Reply Abuse',
      'Reply Change Address' => 'Reply Change Address',
      'Reply Mail Block' => 'Reply Mail Block',
      'Reply Mail Restriction' => 'Reply Mail Restriction',
      'Reply Other' => 'Reply Other',
      'Soft Bounce ' => 'Soft Bounce',
      'Suppressed' => 'Suppressed',
    ),
  );
}
