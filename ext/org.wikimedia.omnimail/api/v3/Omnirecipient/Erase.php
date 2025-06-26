<?php

use Omnimail\Omnimail;
use CRM_Omnimail_ExtensionUtil as E;

/**
 * omnirecipient.eraser API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_omnirecipient_erase_spec(&$spec) {
  $spec['email']['api.required'] = TRUE;
  $spec['mail_provider']['api.required'] = TRUE;
  $spec['database_id']['api.required'] = FALSE;
  $spec['database_id']['title'] = ts('Database ID');
  $spec['retry_delay'] = [
    'title' => E::ts('Delay between attempts to check server response'),
    'api.default' => 1,
  ];
}

/**
 * omnirecipient.erase API
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
function civicrm_api3_omnirecipient_erase($params) {
  $retrievalParameters = isset($params['retrieval_parameters']) ? $params['retrieval_parameters'] : [];
  if (isset($retrievalParameters['database_id'])) {
    // If we are retrying against a specific DB  don't re-spawn against them all.
    $params['database_id'] = $retrievalParameters['database_id'];
  }
  $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);

  /** @var Omnimail\Silverpop\Mailer $factory */
  $factory = Omnimail::create($params['mail_provider'], $mailerCredentials);
  /** @var Omnimail\Silverpop\\Requests\PrivacyDeleteRequest $request */
  $request = $factory->privacyDeleteRequest(['email' => $params['email'], 'retryDelay' => $params['retry_delay']]);

  $request->setRetrievalParameters($retrievalParameters);

  $responses = $request->getResponse();
  foreach ($responses as $response) {
    /* var \Omnimail\Silverpop\Responses\EraseResponse $response */
    if ($response->isCompleted()) {
      // We will delete the erase request. Arguably we should delete it AND
      // add another row to check it's erased. Overkill?
      $response['is_completed'] = TRUE;
    }
    else {
      $response['is_completed'] = FALSE;
      $response['retrieval_parameters'] = $response->getRetrievalParameters();
    }
  }

  return civicrm_api3_create_success($responses);

}

