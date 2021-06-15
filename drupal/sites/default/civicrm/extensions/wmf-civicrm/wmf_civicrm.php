<?php

require_once 'wmf_civicrm.civix.php';
// phpcs:disable
use Civi\Api4\CustomGroup;
use Civi\WMFHooks\QuickForm;
use CRM_WmfCivicrm_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function wmf_civicrm_civicrm_config(&$config) {
  _wmf_civicrm_civix_civicrm_config($config);
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
    if ($tempEntity['entity'] === 'Monolog') {
      // We are not transitioning monologs & this will fail due to there not being
      // a v3 api.
      $entities[] = $tempEntity;
      continue;
    }
    $existing = civicrm_api3($tempEntity['entity'], 'get', ['name' => $tempEntity['params']['name'], 'sequential' => 1]);
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
 * Get the name of the custom field as it would be shown on the form.
 *
 * This is basically 'custom_x_-1' for us. The -1 will always be 1
 * except for multi-value custom groups which we don't really use.
 *
 * @param string $fieldName
 *
 * @return string
 * @throws \CiviCRM_API3_Exception
 */
function _wmf_civicrm_get_form_custom_field_name(string $fieldName): string {
  return 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID($fieldName) . '_-1';
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
//function wmf_civicrm_civicrm_navigationMenu(&$menu) {
//  _wmf_civicrm_civix_insert_navigation_menu($menu, 'Mailings', array(
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ));
//  _wmf_civicrm_civix_navigationMenu($menu);
//}

function wmf_civicrm_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // Allow any user that has 'view all contacts' to make the
  // civiproxy getpreferences API call.
  $permissions['civiproxy'] = [
    'getpreferences' => ['view all contacts'],
  ];
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
 * Add triggers for our calculated custom fields.
 *
 * Whenever a contribution is updated the fields are re-calculated provided
 * the change is an update, a delete or an update which alters a relevant field
 * (contribution_status_id, receive_date, total_amount, contact_id, currency).
 *
 * All fields in the dataset are recalculated (the performance gain on a
 * 'normal' contact of being more selective was too little to show in testing.
 * On our anonymous contact it was perhaps 100 ms but we don't have many
 * contact with thousands of donations.)
 *
 * The wmf_contribution_extra record is saved after the contribution is
 * inserted
 * so we need to potentially update the fields from that record at that points,
 * with a separate trigger.
 **
 *
 * @throws \CRM_Core_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \API_Exception
 */
function wmf_civicrm_civicrm_triggerInfo(&$info, $tableName) {

  if (!$tableName || $tableName === 'civicrm_contribution') {
    $fields = $aggregateFieldStrings = [];
    if (!_wmf_civicrm_is_db_ready_for_triggers()) {
      // Setting info to [] will empty out any existing triggers.
      // We are expecting this to run through fully later so it is a minor
      // optimisation to do less now.
      $info = [];
      return;
    }
    $endowmentFinancialType = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    for ($year = WMF_MIN_ROLLUP_YEAR; $year <= WMF_MAX_ROLLUP_YEAR; $year++) {
      $nextYear = $year + 1;
      $fields[] = "total_{$year}_{$nextYear}";
      $aggregateFieldStrings[] = "MAX(total_{$year}_{$nextYear}) as total_{$year}_{$nextYear}";
      $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as total_{$year}_{$nextYear}";
      $updates[] = "total_{$year}_{$nextYear} = VALUES(total_{$year}_{$nextYear})";

      $fields[] = "total_{$year}";
      $aggregateFieldStrings[] = "MAX(total_{$year}) as total_{$year}";
      $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as total_{$year}";
      $updates[] = "total_{$year} = VALUES(total_{$year})";

      if ($year >= 2017) {
        if ($year >= 2018) {
          $fields[] = "endowment_total_{$year}_{$nextYear}";
          $aggregateFieldStrings[] = "MAX(endowment_total_{$year}_{$nextYear}) as endowment_total_{$year}_{$nextYear}";
          $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}_{$nextYear}";
          $updates[] = "endowment_total_{$year}_{$nextYear} = VALUES(endowment_total_{$year}_{$nextYear})";

          $fields[] = "endowment_total_{$year}";
          $aggregateFieldStrings[] = "MAX(endowment_total_{$year}) as endowment_total_{$year}";
          $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}";
          $updates[] = "endowment_total_{$year} = VALUES(endowment_total_{$year})";
        }

        $fields[] = "change_{$year}_{$nextYear}";
        $aggregateFieldStrings[] = "MAX(change_{$year}_{$nextYear}) as change_{$year}_{$nextYear}";
        $fieldSelects[] = "
          SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
           as change_{$year}_{$nextYear}";
        $updates[] = "change_{$year}_{$nextYear} = VALUES(change_{$year}_{$nextYear})";
      }
    }

    $sql = '
    INSERT INTO wmf_donor (
      entity_id, last_donation_currency, last_donation_amount, last_donation_usd,
      first_donation_usd, date_of_largest_donation,
      largest_donation, endowment_largest_donation, lifetime_including_endowment,
      lifetime_usd_total, endowment_lifetime_usd_total,
      last_donation_date, endowment_last_donation_date, first_donation_date,
      endowment_first_donation_date, number_donations,
      endowment_number_donations, ' . implode(', ', $fields) . '
    )

    SELECT
      NEW.contact_id as entity_id,
       # to honour FULL_GROUP_BY mysql mode we need an aggregate command for each
      # field - even though we know we just want `the value from the subquery`
      # MAX is a safe wrapper for that
      # https://www.percona.com/blog/2019/05/13/solve-query-failures-regarding-only_full_group_by-sql-mode/
      MAX(COALESCE(x.original_currency, latest.currency)) as last_donation_currency,
      MAX(COALESCE(x.original_amount, latest.total_amount, 0)) as last_donation_amount,
      MAX(COALESCE(latest.total_amount, 0)) as last_donation_usd,
      MAX(COALESCE(earliest.total_amount, 0)) as first_donation_usd,
      MAX(largest.receive_date) as date_of_largest_donation,
      MAX(largest_donation) as largest_donation,
      MAX(endowment_largest_donation) as endowment_largest_donation,
      MAX(lifetime_including_endowment) as lifetime_including_endowment,
      MAX(lifetime_usd_total) as lifetime_usd_total,
      MAX(endowment_lifetime_usd_total) as endowment_lifetime_usd_total,
      MAX(last_donation_date) as last_donation_date,
      MAX(endowment_last_donation_date) as endowment_last_donation_date,
      MIN(first_donation_date) as first_donation_date,
      MIN(endowment_first_donation_date) as endowment_first_donation_date,
      MAX(number_donations) as number_donations,
      MAX(endowment_number_donations) as endowment_number_donations,
      ' . implode(',', $aggregateFieldStrings) . "

    FROM (
      SELECT
        MAX(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS largest_donation,
        MAX(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_largest_donation,
        SUM(COALESCE(total_amount, 0)) AS lifetime_including_endowment,
        SUM(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS lifetime_usd_total,
        SUM(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_lifetime_usd_total,
        MAX(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS last_donation_date,
        MAX(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_last_donation_date,
        MIN(IF(financial_type_id <> $endowmentFinancialType AND total_amount, receive_date, NULL)) AS first_donation_date,
        MIN(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_first_donation_date,
        COUNT(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS number_donations,
        COUNT(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_number_donations,
     " . implode(',', $fieldSelects) . "
      FROM civicrm_contribution c
      USE INDEX(FK_civicrm_contribution_contact_id)
      WHERE contact_id = NEW.contact_id
        AND contribution_status_id = 1
        AND (c.trxn_id NOT LIKE 'RFD %' OR c.trxn_id IS NULL)
    ) as totals
  LEFT JOIN civicrm_contribution latest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON latest.contact_id = NEW.contact_id
    AND latest.receive_date = totals.last_donation_date
    AND latest.contribution_status_id = 1
    AND latest.total_amount > 0
    AND (latest.trxn_id NOT LIKE 'RFD %' OR latest.trxn_id IS NULL)
    AND latest.financial_type_id <> $endowmentFinancialType
  LEFT JOIN wmf_contribution_extra x ON x.entity_id = latest.id

  LEFT JOIN civicrm_contribution earliest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON earliest.contact_id = NEW.contact_id
    AND earliest.receive_date = totals.first_donation_date
    AND earliest.contribution_status_id = 1
    AND earliest.total_amount > 0
    AND (earliest.trxn_id NOT LIKE 'RFD %' OR earliest.trxn_id IS NULL)
  LEFT JOIN civicrm_contribution largest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON largest.contact_id = NEW.contact_id
    AND largest.total_amount = totals.largest_donation
    AND largest.contribution_status_id = 1
    AND largest.total_amount > 0
    AND (largest.trxn_id NOT LIKE 'RFD %' OR largest.trxn_id IS NULL)
  GROUP BY NEW.contact_id

  ON DUPLICATE KEY UPDATE
    last_donation_currency = VALUES(last_donation_currency),
    last_donation_amount = VALUES(last_donation_amount),
    last_donation_usd = VALUES(last_donation_usd),
    first_donation_usd = VALUES(first_donation_usd),
    largest_donation = VALUES(largest_donation),
    date_of_largest_donation = VALUES(date_of_largest_donation),
    lifetime_usd_total = VALUES(lifetime_usd_total),
    last_donation_date = VALUES(last_donation_date),
    first_donation_date = VALUES(first_donation_date),
    number_donations = VALUES(number_donations),
    endowment_largest_donation = VALUES(endowment_largest_donation),
    lifetime_including_endowment = VALUES(lifetime_including_endowment),
    endowment_lifetime_usd_total = VALUES(endowment_lifetime_usd_total),
    endowment_last_donation_date = VALUES(endowment_last_donation_date),
    endowment_first_donation_date = VALUES(endowment_first_donation_date),
    endowment_number_donations = VALUES(endowment_number_donations),
    " . implode(',', $updates) . ";";

    $significantFields = ['contribution_status_id', 'total_amount', 'contact_id', 'receive_date', 'currency', 'financial_type_id'];
    $updateConditions = [];
    foreach ($significantFields as $significantField) {
      $updateConditions[] = "(NEW.{$significantField} != OLD.{$significantField})";
    }

    $requiredClauses = [1];

    $matchingGiftDonors = civicrm_api3('Contact', 'get', ['nick_name' => ['IN' => ['Microsoft', 'Google', 'Apple']]])['values'];
    $excludedContacts = array_keys($matchingGiftDonors);
    $anonymousContact = civicrm_api3('Contact', 'get', [
      'first_name' => 'Anonymous',
      'last_name' => 'Anonymous',
      'options' => ['limit' => 1, 'sort' => 'id ASC'],
    ]);
    if ($anonymousContact['count']) {
      $excludedContacts[] = $anonymousContact['id'];
    }
    if (!empty($excludedContacts)) {
      // On live there will always be an anonymous contact. Check is just for dev instances.
      $requiredClauses[] = '(NEW.contact_id NOT IN (' . implode(',', $excludedContacts) . '))';
    }

    $insertSQL = ' IF ' . implode(' AND ', $requiredClauses) . ' THEN ' . $sql . ' END IF; ';
    $updateSQL = ' IF ' . implode(' AND ', $requiredClauses) . ' AND (' . implode(' OR ', $updateConditions) . ' ) THEN ' . $sql . ' END IF; ';
    $requiredClausesForOldClause = str_replace('NEW.', 'OLD.', implode(' AND ', $requiredClauses));
    $oldSql = str_replace('NEW.', 'OLD.', $sql);
    $updateOldSQL = ' IF ' . $requiredClausesForOldClause
      . ' AND (NEW.contact_id <> OLD.contact_id) THEN '
      . $oldSql . ' END IF; ';

    $deleteSql = ' IF ' . $requiredClausesForOldClause . ' THEN ' . $oldSql . ' END IF; ';


    // We want to fire this trigger on insert, update and delete.
    $info[] = [
      'table' => 'civicrm_contribution',
      'when' => 'AFTER',
      'event' => 'INSERT',
      'sql' => $insertSQL,
    ];
    $info[] = [
      'table' => 'civicrm_contribution',
      'when' => 'AFTER',
      'event' => 'UPDATE',
      'sql' => $updateSQL,
    ];
    $info[] = [
      'table' => 'civicrm_contribution',
      'when' => 'AFTER',
      'event' => 'UPDATE',
      'sql' => $updateOldSQL,
    ];
    // For delete, we reference OLD.field instead of NEW.field
    $info[] = [
      'table' => 'civicrm_contribution',
      'when' => 'AFTER',
      'event' => 'DELETE',
      'sql' => $deleteSql,
    ];
  }

}

/**
 * Is our database ready for triggers to be created.
 *
 * If we are still building our environment and the donor custom fields
 * and endowment financial type are not yet present we should skip
 * adding our triggers until later.
 *
 * If this were to be the case on production I think we would have
 * bigger issues than triggers so this should be a dev-only concern.
 *
 * @return false
 *
 * @throws \API_Exception
 */
function _wmf_civicrm_is_db_ready_for_triggers(): bool {
  $endowmentFinancialType = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
  if (!$endowmentFinancialType) {
    return FALSE;
  }
  $wmfDonorQuery = CustomGroup::get(FALSE)->addWhere('name', '=', 'wmf_donor')->execute();
  return (bool) count($wmfDonorQuery);

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
 * @throws \WmfException
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
  if (preg_match('/^([a-z]{3}) [0-9]+(\.[0-9]+)?$/i', $source, $matches)) {
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
    $postmark_field_name = _wmf_civicrm_get_form_custom_field_name('postmark_date');
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
