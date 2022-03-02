<?php
/*
 * This job matches new contacts loaded by the Omnigroupmember.load API call to
 * mailing provider data with missing contact IDs. This situation can arise
 * when the contacts are loaded after having received mailings from the bulk
 * mail provider. This job can be limited by time or by number of contacts
 * processed, and saves the highest contact ID as a Civi setting
 * 'omnigroupmember_match_last_contact_id' to continue from where it left off.
 */

use Civi\Api4\GroupContact;

/**
 * @param array $spec
 */
function _civicrm_api3_omnigroupmember_match_spec(array &$spec) {
  $spec['group_id']['api.required'] = TRUE;
  $spec['group_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['batch']['api.default'] = 1000;
  $spec['batch']['type'] = CRM_Utils_Type::T_INT;
}

function civicrm_api3_omnigroupmember_match(array $params) {
  $values = [];
  $lastId = Civi::settings()->get('omnigroupmember_match_last_contact_id') ?? 0;

  $contacts = GroupContact::get(FALSE)
    ->addSelect('contact_id', 'email.email')
    ->addWhere('group_id', '=', $params['group_id'])
    ->addWhere('contact_id', '>', $lastId)
    ->addJoin('Email AS email', 'LEFT', ['contact_id', '=', 'email.contact_id'])
    ->addOrderBy('contact_id')
    ->setLimit($params['batch'])
    ->execute();

  foreach ($contacts as $contact) {
    // It would be nice to use MailingProviderData::update here but that doesn't work
    // due to the lack of an id column on that table
    $affected = CRM_Core_DAO::executeQuery('UPDATE civicrm_mailing_provider_data
    SET contact_id = %1
    WHERE email = %2
    AND (
        contact_id IS NULL
        OR contact_id = 0
    )', [
      1 => [$contact['contact_id'], 'Integer'],
      2 => [$contact['email.email'], 'String']
    ])->affectedRows();
    $values[$contact['contact_id']] = $affected;
    Civi::settings()->set('omnigroupmember_match_last_contact_id', $contact['contact_id']);
  }

  return civicrm_api3_create_success($values);
}
