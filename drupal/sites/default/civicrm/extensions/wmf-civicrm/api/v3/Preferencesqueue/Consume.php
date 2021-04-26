<?php

/**
 * PreferencesQueue.consume API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_preferencesqueue_consume_spec(&$spec) {
  $spec['time_limit'] = [
    'name' => 'time_limit',
    'title' => 'Job time limit (in seconds)',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['max_batch_size'] = [
    'name' => 'max_batch_size',
    'title' => 'Maximum number of items to process',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_INT,
  ];
}

/**
 * PreferencesQueue.consume API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \API_Exception
 * @see civicrm_api3_create_success
 */
function civicrm_api3_preferencesqueue_consume(array $params): array {
  CRM_SmashPig_ContextWrapper::createContext('civicrm');

  Civi::log('wmf')->info('Executing: Preferencesqueue.consume');

  // FIXME Settings in UI for default values for max_batch_size and time_limit.

  $qConsumer = new CRM_Queue_PreferencesQueueConsumer(
    'email-preferences',
    (int) $params['time_limit'],
    (int) $params['max_batch_size']
  );

  $processed = $qConsumer->dequeueMessages();

  if ($processed > 0) {
    Civi::log('wmf')->info("Processed $processed e-mail preferences messages.");
  }
  else {
    Civi::log('wmf')->info('No e-mail preferences messages processed.');
  }

  return civicrm_api3_create_success($processed, $params, 'Preferencesqueue', 'consume');
}
