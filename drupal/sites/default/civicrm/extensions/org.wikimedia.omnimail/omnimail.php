<?php

require_once 'omnimail.civix.php';

use Civi\Api4\CustomGroup;
use Civi\Api4\Email;
use Civi\Api4\Omnicontact;
use CRM_Omnimail_ExtensionUtil as E;

// checking if the file exists allows compilation elsewhere if desired.
if (file_exists( __DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function omnimail_civicrm_config(&$config) {
  _omnimail_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function omnimail_civicrm_install() {
  _omnimail_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function omnimail_civicrm_enable() {
  _omnimail_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_entityTypes.
 *
 * @param array $entityTypes
 *   Registered entity types.
 */
function omnimail_civicrm_entityTypes(&$entityTypes) {
  $entityTypes['CRM_Omnimail_DAO_MailingProviderData'] = array(
    'name' => 'MailingProviderData',
    'class' => 'CRM_Omnimail_DAO_MailingProviderData',
    'table' => 'civicrm_mailing_provider_data',
  );
  $entityTypes['CRM_Omnimail_DAO_OmnimailJobProgress'] = array (
    'name' => 'OmnimailJobProgress',
    'class' => 'CRM_Omnimail_DAO_OmnimailJobProgress',
    'table' => 'civicrm_omnimail_job_progress',
  );
}

/**
 * Add mailing event tab to contact summary screen
 * @param string $tabsetName
 * @param array $tabs
 * @param array $context
 */
function omnimail_civicrm_tabset($tabsetName, &$tabs, $context) {
  if ($tabsetName == 'civicrm/contact/view') {
    $contactID = $context['contact_id'];
    $url = CRM_Utils_System::url('civicrm/contact/mailings/view', "reset=1&snippet=json&force=1&cid=$contactID");
    $tabs[] = [
      'title' => ts('Mailing Events'),
      'id' => 'omnimail',
      'icon' => 'crm-i fa-envelope-open-o',
      'url' => $url,
      'weight' => 51, // Somewhere near the activities tab
      'class' => 'livePage',
      'count' => civicrm_api3('MailingProviderData', 'getcount', ['contact_id' => $contactID])
    ];
  }
}

/**
 * Keep mailing provider data out of log tables.
 *
 * @param array $logTableSpec
 */
function omnimail_civicrm_alterLogTables(&$logTableSpec) {
  unset($logTableSpec['civicrm_mailing_provider_data'], $logTableSpec['civicrm_omnimail_job_progress']);
}

/**
 * Ensure any missed omnimail dedupes are sorted before a contact is permanently deleted.
 *
 * Note this is required because we were not updating the contact id when merging contacts in the past.
 *
 * Doing it via a pre-hook on delete does not fix all the missed moves of this data - but it ensures we don't lose
 * our last chance to do so & leaves the data just a bit better.
 *
 * Later we might have fixed it all & not need this.
 *
 * @param \CRM_Core_DAO $op
 * @param string $objectName
 * @param int|null $id
 * @param array $params
 *
 * @throws \CiviCRM_API3_Exception
 * @throws \API_Exception
 */
function omnimail_civicrm_pre($op, $objectName, $id, &$params) {
  if ($op === 'delete' && in_array($objectName, ['Individual', 'Organization', 'Household', 'Contact'])) {
    // Argh on prod contact_id is a varchar - put in quotes - aim to change to an int.
    if (CRM_Core_DAO::singleValueQuery('SELECT 1 FROM civicrm_mailing_provider_data WHERE contact_id = "' . (int) $id . '"')) {
      $mergedTo = civicrm_api3('Contact', 'getmergedto', ['contact_id' => $id])['values'];
      if (!empty($mergedTo)) {
        CRM_Core_DAO::executeQuery('
          UPDATE civicrm_mailing_provider_data SET contact_id = "'  . (int) key($mergedTo)
          . '" WHERE contact_id = "' . (int) $id . '"'
        );
      }
    }
  }
}

function omnimail_civicrm_custom($op, $groupID, $entityID, &$params) {
  // Early return if not the relevant group.
  if ((int) $groupID !== _omnimail_civicrm_get_snooze_group_id()
    || !in_array($op, ['edit', 'create'])
    // This static is a placeholder for later functionality where we want to
    // update from Acoustic & we don't want this hook to fire to tell Acoustic about
    // it's own data.
    || !empty(\Civi::$statics['omnimail']['is_batch_snooze_update'])
  ) {
    return;
  }
  foreach ($params as $customFieldValue) {
    if ($customFieldValue['column_name'] === 'snooze_date') {
      $snoozeDate = $customFieldValue['value'];
      if (!empty($snoozeDate)) {
        $fields = Email::get(FALSE)
          ->addWhere('id', '=', $customFieldValue['entity_id'])
          ->addWhere('is_primary', '=', TRUE)
          ->addSelect('email', 'contact_id')
          ->execute()->first();
        $email = $fields['email'];
        $contact_id = $fields['contact_id'];
        if ($email) {
          Omnicontact::snooze(FALSE)
            ->setEmail($email)
            ->setContactID($contact_id)
            ->setSnoozeDate($snoozeDate)->execute();
        }
      }
    }
  }

}

function _omnimail_civicrm_get_snooze_group_id(): int {
  if (!isset(\Civi::$statics[__FUNCTION__])) {
    \Civi::$statics[__FUNCTION__] = CustomGroup::get(FALSE)
      ->addWhere('name', '=', 'email_settings')
      ->execute()->first()['id'];
  }
  return (int) \Civi::$statics[__FUNCTION__];
}
