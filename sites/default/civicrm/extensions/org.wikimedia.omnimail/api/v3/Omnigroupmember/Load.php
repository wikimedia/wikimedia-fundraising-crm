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
function civicrm_api3_omnigroupmember_load($params) {
  $contacts = civicrm_api3('Omnigroupmember', 'get', $params);
  $defaultLocationType = CRM_Core_BAO_LocationType::getDefault();
  $locationTypeID = $defaultLocationType->id;

  foreach ($contacts['values'] as $groupMember) {
    if (!empty($groupMember['email']) && !civicrm_api3('email', 'getcount', array('email' => $groupMember['email']))) {
      // If there is already a contact with this email we will skip for now.
      // It might that we want to create duplicates, update contacts or do other actions later
      // but let's re-assess when we see that happening. Spot checks only found emails not
      // otherwise in the DB.
      $source = (empty($params['mail_provider']) ? ts('Mail Provider') : $params['mail_provider']) . ' ' . (!empty($groupMember['source']) ? $groupMember['source'] : $groupMember['opt_in_source']);
      $source .= ' ' . $groupMember['created_date'];

      $contactParams = array(
        'contact_type' => 'Individual',
        'email' => $groupMember['email'],
        'is_opt_out' => $groupMember['is_opt_out'],
        'source' => $source,
        'preferred_language' => _civicrm_api3_omnigroupmember_get_language($groupMember),
      );

      if (!empty($groupMember['country']) && _civicrm_api3_omnigroupmember_is_country_valid($groupMember['country'])) {
        $contactParams['api.address.create'] = array(
          'country_id' => $groupMember['country'],
          'location_type_id' => $locationTypeID,
        );
      }

      $contact = civicrm_api3('Contact', 'create', $contactParams);
      if (!empty($params['group_id'])) {
        civicrm_api3('GroupContact', 'create', array(
          'group_id' => $params['group_id'],
          'contact_id' => $contact['id'],
        ));
      }
      $values[$contact['id']] = reset($contact['values']);
    }
  }
  return civicrm_api3_create_success($values);
}

/**
 * Get the contact's language.
 *
 * This is a place in the code where I am struggling to keep wmf-specific coding out
 * of a generic extension. The wmf-way would be to call the wmf contact_insert function.
 *
 * That is not so appropriate from an extension, but we have language/country data that
 * needs some wmf specific handling as it might or might not add up to a legit language.
 *
 * At this stage I'm compromising on containing the handling within the extension,
 * ensuring test covering and splitting out & documenting the path taken /issue.
 * Later maybe a more listener/hook type approach is the go.
 *
 * It's worth noting this is probably the least important part of the omnimail work
 * from wmf POV.
 *
 * @param array $params
 *
 * @return string|null
 */
function _civicrm_api3_omnigroupmember_get_language($params) {
  static $languages = NULL;
  if (!$languages) {
    $languages = civicrm_api3('Contact', 'getoptions', array('field' => 'preferred_language', 'limit' => 0));
    $languages = $languages['values'];
  }
  $attempts = array(
    $params['language'] . '_' . strtoupper($params['country']),
    $params['language'],
  );
  foreach ($attempts as $attempt) {
    if (isset($languages[$attempt])) {
      return $attempt;
    }
  }
  return NULL;
}

/**
 * Check if the country is valid.
 *
 * @param string $country
 *
 * @return bool
 */
function _civicrm_api3_omnigroupmember_is_country_valid($country) {
  static $countries = NULL;
  if (!$countries) {
    $countries = CRM_Core_PseudoConstant::countryIsoCode();
  }
  return array_search($country, $countries) ? $country : FALSE;
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

}
