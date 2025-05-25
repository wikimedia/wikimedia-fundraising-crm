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
function civicrm_api3_omnigroupmember_get($params) {
  $options = _civicrm_api3_get_options_from_params($params);
  $params['limit'] = $options['limit'];
  $job = new CRM_Omnimail_Omnigroupmembers($params);
  $result = $job->getResult($params);
  $values = $job->formatResult($params, $result);
  return civicrm_api3_create_success($values);
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnigroupmember_get_spec(&$params) {
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
  $params['group_identifier'] = array(
    'title' => ts('Identifier for the group'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => TRUE,
  );
  $params['retrieval_parameters'] = array(
    'title' => ts('Additional information for retrieval of pre-stored requests'),
  );
  $params['custom_data_map'] = array(
    'type' => CRM_Utils_Type::T_STRING,
    'title' => ts('Custom fields map'),
    'description' => array('custom mappings pertaining to the mail provider fields'),
    'api.default' => array(
      'language' => 'rml_language',
      'source' => 'rml_source',
      'created_date' => 'rml_submitDate',
      'country' => 'rml_country',
    ),
  );
  $params['is_opt_in_only'] = array(
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'title' => ts('Opted in contacts only'),
    'description' => array('Restrict to opted in contacts'),
    'api.default' => 1,
  );

}
