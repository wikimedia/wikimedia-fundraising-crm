<?php

require_once 'wmf_civicrm.civix.php';
// phpcs:disable
use Civi\WMFHooks\CalculatedData;
use Civi\WMFHooks\Permissions;
use Civi\WMFHooks\QuickForm;
use Civi\WMFHooks\Data;
use CRM_WmfCivicrm_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function wmf_civicrm_civicrm_config(&$config) {
  _wmf_civicrm_civix_civicrm_config($config);
  $dispatcher = Civi::dispatcher();
  $dispatcher->addListener('civi.token.list', ['CRM_Wmf_Tokens', 'onListTokens']);
  $dispatcher->addListener('civi.token.eval', ['CRM_Wmf_Tokens', 'onEvalTokens']);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function wmf_civicrm_civicrm_xmlMenu(&$files) {
  _wmf_civicrm_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function wmf_civicrm_civicrm_install() {
  _wmf_civicrm_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function wmf_civicrm_civicrm_postInstall() {
  _wmf_civicrm_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function wmf_civicrm_civicrm_uninstall() {
  _wmf_civicrm_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function wmf_civicrm_civicrm_enable() {
  _wmf_civicrm_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function wmf_civicrm_civicrm_disable() {
  _wmf_civicrm_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function wmf_civicrm_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _wmf_civicrm_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function wmf_civicrm_civicrm_managed(&$entities) {
  // In order to transition existing types to managed types we
  // have a bit of a routine to insert managed rows if
  // they already exist. Hopefully this is temporary and can
  // go once the module installs are transitioned.
  $tempEntities = [];
  _wmf_civicrm_civix_civicrm_managed($tempEntities);
  foreach ($tempEntities as $tempEntity) {
    if ($tempEntity['entity'] === 'Monolog' || $tempEntity['entity'] === 'MessageTemplate') {
      // We are not transitioning monologs or Message Templates & this will fail due to there not being
      // a v3 api.
      $entities[] = $tempEntity;
      continue;
    }
    if ($tempEntity['entity'] === 'RelationshipType') {
      $lookupParams = ['name_a_b' => $tempEntity['params']['name_a_b'], 'sequential' => 1];
    }
    else {
      $lookupParams = ['name' => $tempEntity['params']['name'], 'sequential' => 1];
    }
    $existing = civicrm_api3($tempEntity['entity'], 'get', $lookupParams);
    if ($existing['count'] === 1 && !CRM_Core_DAO::singleValueQuery("
      SELECT count(*) FROM civicrm_managed
      WHERE entity_type = '{$tempEntity['entity']}'
      AND module = 'wmf-civicrm'
      AND name = '{$tempEntity['name']}'
    ")) {
      if (!isset($tempEntity['cleanup'])) {
        $tempEntity['cleanup'] = '';
      }
      CRM_Core_DAO::executeQuery("
        INSERT INTO civicrm_managed (module, name, entity_type, entity_id, cleanup)
        VALUES('wmf-civicrm', '{$tempEntity['name']}', '{$tempEntity['entity']}', {$existing['id']}, '{$tempEntity['cleanup']}')
      ");
    }
    $entities[] = $tempEntity;
  }
  // Once the above is obsolete remove & uncomment this line.
  // _wmf_civicrm_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function wmf_civicrm_civicrm_caseTypes(&$caseTypes) {
  _wmf_civicrm_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function wmf_civicrm_civicrm_angularModules(&$angularModules) {
  _wmf_civicrm_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function wmf_civicrm_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _wmf_civicrm_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_alterSettingsMetaData(().
 *
 * This hook sets the default for each setting to our preferred value.
 * It can still be overridden by specifically setting the setting.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
 */
function wmf_civicrm_civicrm_alterSettingsMetaData(&$settingsMetaData, $domainID, $profile) {
  $configuredSettingsFile = __DIR__ . '/Managed/Settings.php';
  $configuredSettings = include $configuredSettingsFile;
  foreach ($configuredSettings as $name => $value) {
    $settingsMetaData[$name]['default'] = $value;
  }
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function wmf_civicrm_civicrm_entityTypes(&$entityTypes) {
  _wmf_civicrm_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_themes().
 */
function wmf_civicrm_civicrm_themes(&$themes) {
  _wmf_civicrm_civix_civicrm_themes($themes);
}

/**
 * Implements hook_civicrm_buildForm
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 *
 * @throws \CiviCRM_API3_Exception
 * @noinspection PhpUnused
 */
function wmf_civicrm_civicrm_buildForm(string $formName, $form) {
 QuickForm::buildForm($formName, $form);
}

/**
 * Log the dedupe to our log.
 *
 * @param string $type
 * @param array $refs
 * @param int $mainId
 * @param int $otherId
 * @param array $tables
 */
function wmf_civicrm_civicrm_merge($type, &$refs, $mainId, $otherId, $tables) {
  if (in_array($type, ['form', 'batch'])) {
    Civi::log('wmf')->debug(
      'Deduping contacts {contactKeptID} and {contactDeletedID}. Mode = {mode}', [
        'contactKeptID' => $mainId,
        'contactDeletedID' => $otherId,
        'mode' => $type,
      ]);
  }
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function wmf_civicrm_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function wmf_civicrm_civicrm_navigationMenu(&$menu) {
  _wmf_civicrm_civix_insert_navigation_menu(
    $menu,
    'Administer/Customize Data and Screens', [
    'label' => 'WMF configuration',
    'name' => 'wmf_configuration',
    'url' => 'civicrm/admin/setting/wmf-civicrm',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _wmf_civicrm_civix_navigationMenu($menu);
}

function wmf_civicrm_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // Allow any user that has 'view all contacts' to make the
  // civiproxy getpreferences API call.
  $permissions['civiproxy'] = [
    'getpreferences' => ['view all contacts'],
  ];
  // These can be removed if these are merged https://github.com/civicrm/civicrm-core/pulls?q=is%3Apr+author%3Aeileenmcnaughton+2752
  // Bug T279686
  $permissions['financial_type']['get'] = $permissions['contribution']['get'];
  $permissions['financial_trxn']['get'] = $permissions['contribution']['get'];
  $permissions['entity_financial_account']['get'] = $permissions['contribution']['get'];
  $permissions['financial_account']['get'] = $permissions['contribution']['get'];
}

/**
 * Implements hook_alterLogTables().
 *
 * This
 * 1) Alters the table to be INNODB - this should no longer be required
 * 2) Adds indexes to the id, log_conn_id (unique id for the connection)
 * log_conn_date (date of the change) and to all fields that reference the
 * contact.id field.
 * 3) Declares that some tables should be exempt from logging.
 *
 * @param array $logTableSpec
 */
function wmf_civicrm_civicrm_alterLogTables(array &$logTableSpec) {
  $logTableSpec['wmf_contribution_extra'] = [];
  $contactReferences = CRM_Dedupe_Merger::cidRefs();
  foreach (array_keys($logTableSpec) as $tableName) {
    $contactIndexes = [];
    $logTableSpec[$tableName]['engine'] = 'INNODB';
    $logTableSpec[$tableName]['engine_config'] = 'ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=4';
    $contactRefsForTable = CRM_Utils_Array::value($tableName, $contactReferences, []);
    foreach ($contactRefsForTable as $fieldName) {
      $contactIndexes['index_' . $fieldName] = $fieldName;
    }
    $logTableSpec[$tableName]['indexes'] = array_merge([
      'index_id' => 'id',
      'index_log_conn_id' => 'log_conn_id',
      'index_log_date' => 'log_date',
    ], $contactIndexes);
  }

  // Exclude from logging tables to save disk given low value.
  $tablesNotToLog = [
    // financial tables that don't add much to forensics
    'civicrm_entity_financial_trxn',
    'civicrm_financial_item',
    'civicrm_financial_trxn',
    'civicrm_line_item',
    // our queues are not the primary source for these silverpop based tables (and there is a log of data)
    'civicrm_mailing',
    'civicrm_mailing_provider_data',
    // this table is not important because we don't send from civi / need to link back
    // mailing events within civi.
    'civicrm_mailing_job',
    // this table logs group membership & largely repeats log_civicrm_group_contact.
    'civicrm_subscription_history',
    // wmf_donor contains calculated data only.
    'wmf_donor',
  ];
  foreach ($tablesNotToLog  as $noLoggingTable) {
    if (isset($logTableSpec[$noLoggingTable])) {
      unset($logTableSpec[$noLoggingTable]);
    }
  }

}


/**
 * Implements hook_civicrm_triggerInfo().
 *
 * @throws \CiviCRM_API3_Exception
 * @throws \API_Exception
 */
function wmf_civicrm_civicrm_triggerInfo(&$info, $tableName) {
  $processor = new CalculatedData();
  $wmfTriggerInfo = $processor->setTableName($tableName)->triggerInfo();
  $info = array_merge($info, $wmfTriggerInfo);
}

/**
 * Implements hook_civicrm_validateForm().
 *
 * @param string $formName
 * @param array $fields
 * @param array $files
 * @param CRM_Core_Form $form
 * @param array $errors
 */
function wmf_civicrm_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName === 'CRM_Contact_Form_DedupeFind') {
    if (!$fields['limit']) {
      $errors['limit'] = ts('Save the database. Use a limit');
    }
    $ruleGroupID = $form->rgid;
    if ($fields['limit'] > 1 && 'fishing_net' === civicrm_api3('RuleGroup', 'getvalue', ['id' => $ruleGroupID, 'return' => 'name'])) {
      $errors['limit'] = ts('The fishing net rule should only be applied to a single contact');
    }
  }
  if ($formName == 'CRM_Contribute_Form_Contribution') {
    $engageErrors = wmf_civicrm_validate_contribution($fields, $form);
    if (!empty($engageErrors)) {
      $errors = array_merge($errors, $engageErrors);
    }
  }
}

/**
 * Additional validations for the contribution form
 *
 * @param array $fields
 * @param CRM_Core_Form $form
 *
 * @return array of any errors in the form
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\WMFException\WMFException
 */
function wmf_civicrm_validate_contribution($fields, $form): array {
  $errors = [];

  // Only run on add or update
  if (!($form->_action & (CRM_Core_Action::UPDATE | CRM_Core_Action::ADD))) {
    return $errors;
  }
  // Source has to be of the form USD 15.25 so as not to gum up the works,
  // and the currency code on the front should be something we understand
  $source = $fields['source'];
  if (preg_match('/^([a-z]{3}) -?[0-9]+(\.[0-9]+)?$/i', $source, $matches)) {
    $currency = strtoupper($matches[1]);
    if (!wmf_civicrm_is_valid_currency($currency)) {
      $errors['source'] = t('Please set a supported currency code');
    }
  }
  else {
    $errors['source'] = t('Source must be in the format USD 15.25');
  }

  // Only run the following validation for users having the Engage role.
  if (!wmf_civicrm_user_has_role('Engage Direct Mail')) {
    return $errors;
  }

  $engage_contribution_type_id = wmf_civicrm_get_civi_id('financial_type_id', 'Engage');
  if ($fields['financial_type_id'] !== $engage_contribution_type_id) {
    $errors['financial_type_id'] = t("Must use the \"Engage\" contribution type.");
  }

  if (wmf_civicrm_tomorrows_month() === '01') {
    $postmark_field_name = QuickForm::getFormCustomFieldName('postmark_date');
    // If the receive_date is in Dec or Jan, make sure we have a postmark date,
    // to be generous to donors' tax stuff.
    $date = strptime($fields['receive_date'], "%m/%d/%Y");
    // n.b.: 0-based date spoiler.
    if ($date['tm_mon'] == (12 - 1) || $date['tm_mon'] == (1 - 1)) {
      // And the postmark date is missing
      if ($form->elementExists($postmark_field_name) && !$fields[$postmark_field_name]) {
        $errors[$postmark_field_name] = t("You forgot the postmark date!");
      }
    }
  }

  return $errors;
}

/**
 * https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_permission/
 *
 * @param array $permissions
 */
function wmf_civicrm_civicrm_permission(array &$permissions) {
  Permissions::permissions($permissions);
}

/**
 * Implements hook civicrm_customPre().
 *
 * https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_customPre/
 *
 * @param string $op
 * @param int $groupID
 * @param int $entityID
 * @param array $params
 */
function wmf_civicrm_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {
  Data::customPre($op, $groupID, $entityID, $params);
}
