<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/3/17
 * Time: 12:46 PM
 */

use Civi\Api4\GroupContact;
use Civi\Api4\Omnigroupmember;
use Omnimail\Silverpop\Responses\Contact;

/**
 * Get details about Omnimails.
 *
 * @param $params
 *
 * @return array
 *
 * @throws \CRM_Core_Exception
 * @throws \League\Csv\Exception
 */
function civicrm_api3_omnigroupmember_load($params) {
  $options = _civicrm_api3_get_options_from_params($params);
  $values = (array) Omnigroupmember::load(FALSE)
    ->setThrottleSeconds($params['throttle_seconds'])
    ->setThrottleNumber($params['throttle_number'])
    ->setGroupIdentifier($params['group_identifier'])
    ->setGroupID($params['group_id'])
    ->setLimit((int) $options['limit'])
    ->setOffset($options['offset'] ?: NULL)
    ->setClient($params['client'] ?? NULL)

    ->setJobIdentifier($params['job_identifier'] ?? NULL)
    ->execute();
  return civicrm_api3_create_success($values);
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnigroupmember_load_spec(&$params) {
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
  $params['group_identifier'] = array(
    'title' => ts('Identifier for the group'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => TRUE,
  );
  $params['retrieval_parameters'] = array(
    'title' => ts('Additional information for retrieval of pre-stored requests'),
  );
  $params['is_opt_in_only'] = array(
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'title' => ts('Opted in contacts only'),
    'description' => array('Restrict to opted in contacts'),
    'api.default' => 1,
  );

  $params['throttle_number'] = array(
    'title' => ts('Number of inserts to throttle after'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 5000,
  );

  $params['throttle_seconds'] = array(
    'title' => ts('Throttle after the number has been reached in this number of seconds'),
    'description' => ts('If the throttle limit is passed before this number of seconds is reached php will sleep until it hits it.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 60,
  );
  $params['job_identifier'] = array(
    'title' => ts('An identifier string to add to job-specific settings.'),
    'description' => ts('The identifier allows for multiple settings to be stored for one job. For example if wishing to run an up-top-date job and a catch-up job'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.default' => '',
  );

}
