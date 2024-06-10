<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/3/17
 * Time: 12:46 PM
 */

use Civi\Api4\Address;
use Civi\Api4\Email;
use Civi\Api4\GroupContact;
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
  $values = array();

  $throttleSeconds = CRM_Utils_Array::value('throttle_seconds', $params);
  $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . ' seconds');
  $throttleCount = (int) CRM_Utils_Array::value('throttle_number', $params);
  $rowsLeftBeforeThrottle = $throttleCount;

  $job = new CRM_Omnimail_Omnigroupmembers($params);
  $jobSettings = $job->getJobSettings($params);
  try {
    $contacts = $job->getResult($params);
  }
  catch (CRM_Omnimail_IncompleteDownloadException $e) {
    $job->saveJobSetting(array(
      'retrieval_parameters' => $e->getRetrievalParameters(),
      'progress_end_timestamp' => $e->getEndTimestamp(),
      'offset' => 0,
    ));
    return civicrm_api3_create_success(1);
  }

  $defaultLocationType = CRM_Core_BAO_LocationType::getDefault();
  $locationTypeID = $defaultLocationType->id;

  $offset = $job->getOffset();
  $limit = (isset($params['options']['limit'])) ? $params['options']['limit'] : NULL;
  $count = 0;

  foreach ($contacts as $row) {
    $contact = new Contact($row);
    if ($count === $limit) {
      $job->saveJobSetting(array(
        'last_timestamp' => $jobSettings['last_timestamp'],
        'retrieval_parameters' => $job->getRetrievalParameters(),
        'progress_end_timestamp' => $job->endTimeStamp,
        'offset' => $offset + $count,
      ));
      // Do this here - ie. before processing a new row rather than at the end of the last row
      // to avoid thinking a job is incomplete if the limit co-incides with available rows.
      return civicrm_api3_create_success($values);
    }
    $groupMember = $job->formatRow($contact, $params['custom_data_map']);
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

      $contactCreateCall = \Civi\Api4\Contact::create(FALSE)
        ->setValues($contactParams)
        ->addChain(
          'emailCreate',
          Email::create(FALSE)->setValues([
            'contact_id' => '$id',
            'email' => $groupMember['email']
          ])
        );

      if (!empty($groupMember['country']) && _civicrm_api3_omnigroupmember_is_country_valid($groupMember['country'])) {
        $contactCreateCall->addChain(
          'addressCreate',
          Address::create(FALSE)->setValues([
            'contact_id' => '$id',
            'country_id' => array_search($groupMember['country'], CRM_Core_PseudoConstant::countryIsoCode()),
            'location_type_id' => $locationTypeID,
          ])
        );
      }

      if (!empty($params['group_id'])) {
        $contactCreateCall->addChain(
          'groupContact',
          GroupContact::create(FALSE)->setValues([
            'contact_id' => '$id',
            'group_id' => $params['group_id']
          ])
        );
      }
      $contact = $contactCreateCall->execute()->first();
      $values[$contact['id']] = $contact;
    }

    $count++;
    // Every row seems extreme but perhaps not in this performance monitoring phase.
    $job->saveJobSetting(array_merge($jobSettings, array('offset' => $offset + $count)));

    $rowsLeftBeforeThrottle--;
    if ($throttleStagePoint && (strtotime('now') > $throttleStagePoint)) {
      $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . 'seconds');
      $rowsLeftBeforeThrottle = $throttleCount;
    }

    if ($throttleSeconds && $rowsLeftBeforeThrottle <= 0) {
      sleep(ceil($throttleStagePoint - strtotime('now')));
    }
  }

  $job->saveJobSetting(array(
    'last_timestamp' => $job->endTimeStamp,
    'progress_end_timestamp' => 'null',
    'retrieval_parameters' => 'null',
    'offset' => 'null',
  ));
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
