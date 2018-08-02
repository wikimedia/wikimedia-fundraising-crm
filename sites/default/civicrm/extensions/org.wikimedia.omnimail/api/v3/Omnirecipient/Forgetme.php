<?php
use CRM_Forgetme_ExtensionUtil as E;
require_once 'api/v3/ShowmeUtils.php';

/**
 * Omnirecipient.forgetme API specification
 *
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_omnirecipient_forget_spec(&$spec) {
  $spec['contact_id']['title'] = E::ts('Contact ID');
  $spec['contact_id']['api.required'] = TRUE;
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * Omnirecipient.forgetme API
 *
 * The point of this api is to forget all data about one or more contacts.
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_omnirecipient_forgetme($params) {
  $whereClause = CRM_Core_DAO::createSQLFilter('contact_id', $params['contact_id'], CRM_Utils_Type::T_INT);
  $emails = civicrm_api3('MailingProviderData', 'get', ['contact_id' => $params['contact_id'], 'return' => 'email'])['values'];

  $eraseEmails = [];
  foreach ($emails as $email) {
    $eraseEmails[] = $email['email'];
  }
  if (!empty($eraseEmails)) {
    civicrm_api3('Omnirecipient', 'erase', [
      'email' => $eraseEmails,
      'mail_provider' => 'Silverpop'
    ]);
  }
  CRM_Core_DAO::executeQuery(
    'DELETE FROM civicrm_mailing_provider_data WHERE ' . $whereClause
  );
  return civicrm_api3_create_success(1);
}
