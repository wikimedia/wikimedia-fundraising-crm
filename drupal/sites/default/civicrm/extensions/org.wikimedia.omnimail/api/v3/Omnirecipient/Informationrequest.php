<?php

use Omnimail\Omnimail;

/**
 * omnirecipient.informationrequest API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_omnirecipient_informationrequest_spec(&$spec) {
  $spec['email']['api.required'] = TRUE;
  $spec['mail_provider']['api.required'] = TRUE;
  $spec['database_id']['api.required'] = FALSE;
  $spec['database_id']['title'] = ts('Database ID');
}

/**
 * omnirecipient.informationrequest API
 *
 * The point of this api is to get all data about a phone with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_omnirecipient_informationrequest($params) {
  $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);

  /** @var Omnimail\Silverpop\\Mailer $request */
  $request = Omnimail::create($params['mail_provider'], $mailerCredentials);
  $response = $request->privacyInformationRequest(['email' => $params['email']])->getResponse();
  return civicrm_api3_create_success($response);

}

