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
  $result = CRM_Core_DAO::executeQuery('
    SELECT * FROM civicrm_mailing_provider_data md
    LEFT JOIN civicrm_contact c ON c.id = md.contact_id
    LEFT JOIN civicrm_campaign ca ON ca.name = md.mailing_identifier
    WHERE event_type = "Opt Out"
    AND is_civicrm_updated = 0
    AND c.id IS NOT NULL
    AND ca.id IS NOT NULL
  ');

  while ($result->fetch()) {
    CRM_Core_DAO::executeQuery('SET @uniqueID = %1', array(1 => array(uniqid() . CRM_Utils_String::createRandom(CRM_Utils_String::ALPHANUMERIC, 4), 'String')));
    civicrm_api3('Activity', 'create', array(
      'activity_type_id' => 'Unsubscribe',
      'campaign_id' => $result->mailing_identifier,
      'target_contact_id' => $result->contact_id,
      'source_contact_id' => $result->contact_id,
      'activity_date_time' => $result->recipient_action_datetime,
    ));
    civicrm_api3('Contact', 'create', array('is_opt_out' => 1, 'id' => $result->contact_id));
    CRM_Core_DAO::executeQuery('
      UPDATE civicrm_mailing_provider_data SET is_civicrm_updated = 1 WHERE contact_identifier = %1 AND recipient_action_datetime = %2 AND event_type = %3', array(
      1 => array($result->contact_identifier, 'String'),
      2 => array($result->recipient_action_datetime, 'String'),
      3 => array($result->event_type, 'String'),
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

}
