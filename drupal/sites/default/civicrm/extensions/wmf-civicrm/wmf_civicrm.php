<?php

use Civi\Api4\CustomField;
use Civi\WMFHelper\Queue;
use Civi\WMFHook\Address as AddressHook;

require_once 'wmf_civicrm.civix.php';
// phpcs:disable
use Civi\WMFHook\Activity;
use Civi\WMFHook\CalculatedData;
use Civi\WMFHook\Contribution;
use Civi\WMFHook\ContributionRecurTrigger;
use Civi\WMFHook\ContributionSoft;
use Civi\WMFHook\Import;
use Civi\WMFHook\ProfileDynamic;
use Civi\WMFHook\QuickForm;
use Civi\WMFHook\Data;
use Civi\Api4\MessageTemplate;
use Civi\WMFHelper\Language;
use Civi\WMFHook\PreferencesLink;
use Civi\WMFStatistic\PrometheusReporter;

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
  // Ensure it runs after the first ones, since we override some core tokens.
  $dispatcher->addListener('civi.token.eval', ['CRM_Wmf_Tokens', 'onEvalTokens'], -200);
  $dispatcher->addListener('hook_civicrm_queueActive', [Queue::class, 'isSiteBusy']);
  // Increase the weight on the angular directory in this extension so it overrides the others.
  // This ensures our tweaks take precedence.
  // See https://github.com/civicrm/civicrm-core/pull/30817.
  Civi::dispatcher()->addListener('&civi.afform.searchPaths', function(&$paths) {
    foreach ($paths as &$path) {
      if (str_contains($path['path'], __DIR__)) {
        $path['weight'] = 50;
      }
    }
  });
  Civi::dispatcher()->addListener('civi.api.prepare', ['Civi\WMFHook\Contribution', 'apiPrepare']);
}

/**
 * Abuse the permissions hook to prevent de-duping without a limit
 *
 * @param string $permission
 * @param bool $granted
 *
 * @return bool
 */
function wmf_civicrm_civicrm_permission_check($permission, &$granted) {
  if ($permission === 'merge duplicate contacts') {
    $action = CRM_Utils_Request::retrieve('action', 'String');
    $path = CRM_Utils_System::currentPath();
    if (
      $path === 'civicrm/contact/dedupefind' &&
      !CRM_Utils_Request::retrieve('limit', 'Integer') &&
      ($action != CRM_Core_Action::PREVIEW)
    ) {
      CRM_Core_Session::setStatus(ts('Not permitted for WMF without a limit - this is a setting (dedupe_default_limit) configured on administer->system settings -> misc'));
      $granted = FALSE;
    }
  }
  return TRUE;
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
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function wmf_civicrm_civicrm_enable() {
  _wmf_civicrm_civix_civicrm_enable();
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
  foreach ($tempEntities as $index => $tempEntity) {
    // WMF only uses our own geocoder ...
    if ($tempEntity['entity'] === 'Geocoder' && $tempEntity['name'] !== 'uk_postcode') {
      $tempEntities[$index]['params']['is_active'] = 0;
    }
    if ($tempEntity['entity'] === 'Monolog' || $tempEntity['entity'] === 'MessageTemplate' || $tempEntity['entity'] === 'Translation') {
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
 * Intercede in searches to unset 'force' when it appears to be accidentally set.
 *
 * This is a long standing wmf hack & it's not sure when the url would be hit by
 * a user but it can be replicated by accessing
 *
 * /civicrm/contribute/search?force=1&context=search&reset=1
 *
 * When this is working correctly the criteria form not the results will show.
 *
 * Note this is a totally weird place to do this - but seems tobe the only place called
 * before the search is rendered.
 *
 * @throws \CRM_Core_Exception
 * @noinspection PhpUnused
 */
function wmf_civicrm_civicrm_searchTasks() {
  if (CRM_Utils_Request::retrieveValue('context', 'String', 'search') === 'search'
    && CRM_Utils_Request::retrieve('qfKey', 'String') === NULL
  ) {
    $_GET['force'] = $_REQUEST['force'] = FALSE;
  }
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
 * Implements hook_civicrm_preProcess
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 *
 * @noinspection PhpUnused
 */
function wmf_civicrm_civicrm_preProcess(string $formName, $form) {
  QuickForm::preProcess($formName, $form);
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

/**
 * Implementation of hook_civicrm_pre
 *
 * @param string $op
 * @param string $type
 * @param int $id
 * @param array $entity
 *
 * @throws \Civi\WMFException\WMFException
 * @noinspection PhpUnused
 */
function wmf_civicrm_civicrm_pre(string $op, $type, $id, &$entity) {
  switch ($type) {
    case 'Contribution':
      Contribution::pre($op, $entity);
      break;

    case 'ContributionSoft':
      ContributionSoft::pre($op, $entity);
      break;

    case 'Address':
      AddressHook::pre($op, $entity);
      break;
  }
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
    // our queues are not the primary source for this Acoustic based table (and there is a log of data)
    'civicrm_mailing_provider_data',
    // this table is not important because we don't send from civi / need to link back
    // mailing events within civi.
    'civicrm_mailing_job',
    // this table logs group membership & largely repeats log_civicrm_group_contact.
    'civicrm_subscription_history',
    // the volume of data in this table + it's read only nature mean it
    // doesn't make sense to track.
    'civicrm_contribution_tracking',
    // wmf_donor contains calculated data only.
    'wmf_donor',
  ];
  foreach ($tablesNotToLog as $noLoggingTable) {
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
  $recurProcessor = new ContributionRecurTrigger();
  $recurTriggerInfo = $recurProcessor->setTableName($tableName)->triggerInfo();
  $info = array_merge($info, $recurTriggerInfo);
  $info = Activity::alterTriggerSql($info);

  // Remove any disabled custom fields from our SQL. This allows us to stage the deletion process
  // 1) disable the field - can be done at any time
  // 2) reload triggers - can also be done at any time after 1 is done (but before 3)
  // 3) delete the fields - depending on the table size this may require an outage.
  $disabledCustomFields = CustomField::get(FALSE)
    ->addWhere('is_active', '=', FALSE)
    ->addSelect('column_name', 'name', 'custom_group_id.table_name', 'id')
    ->execute();
  $tablesToRemoveFieldsFrom = [];
  foreach ($disabledCustomFields as $disabledCustomField) {
    $tablesToRemoveFieldsFrom[$disabledCustomField['custom_group_id.table_name']][] = $disabledCustomField['column_name'];
  }
  foreach ($info as &$tableSpecification) {
    if (array_intersect((array) $tableSpecification['table'], array_keys($tablesToRemoveFieldsFrom))) {
      // For logging tables, which are the ones the replace will actually change, there
      // is just one table in the table array.
      foreach ($tablesToRemoveFieldsFrom[$tableSpecification['table'][0]] ?? [] as $disabledField) {
        // the field appears twice in the sql, once with NEW. prepended, replace that on first
        $tableSpecification['sql'] = str_replace(
          'OR IFNULL(OLD.`' . $disabledField . '`,\'\') <> IFNULL(NEW.`' . $disabledField . '`,\'\')',
          '', $tableSpecification['sql']);
        $tableSpecification['sql'] = str_replace(
          'NEW.`' . $disabledField . '`,', '', $tableSpecification['sql']);
        $tableSpecification['sql'] = str_replace(
          'OLD.`' . $disabledField . '`,', '', $tableSpecification['sql']);
        $tableSpecification['sql'] = str_replace(
          '`' . $disabledField . '`,', '', $tableSpecification['sql']);
      }
    }
  }
}

/**
 * Implements hook_civicrm_importAlterMappedRow().
 */
function wmf_civicrm_civicrm_importAlterMappedRow(string $importType, string $context, array &$mappedRow, array $rowValues, int $userJobID) {
  Import::alterMappedRow($importType, $context, $mappedRow, $rowValues, $userJobID);
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
    /* @var CRM_Contact_Form_DedupeFind $form */
    if (!$fields['limit']) {
      $errors['limit'] = ts('Save the database. Use a limit');
    }
    $ruleGroupID = $form->getDedupeRuleGroupID();
    if ($fields['limit'] > 1 && 'fishing_net' === civicrm_api3('RuleGroup', 'getvalue', ['id' => $ruleGroupID, 'return' => 'name'])) {
      $errors['limit'] = ts('The fishing net rule should only be applied to a single contact');
    }
  }
  if ($formName === 'CRM_Contribute_Form_Contribution') {
    /* @var CRM_Contribute_Form_Contribution $form */
    $errors = array_merge(Contribution::validateForm($fields, $form));
  }
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

/**
 * Implementation of hook_civicrm_post, used to update contribution_extra fields
 * and wmf_donor rollup fields.
 *
 * @implements hook_civicrm_post
 *
 * @param string $op
 * @param string $type
 * @param int $id
 * @param \CRM_Contribute_BAO_Contribution $entity
 *
 * @throws \Civi\WMFException\WMFException
 * @throws \CRM_Core_Exception
 */
function wmf_civicrm_civicrm_post($op, $type, $id, &$entity) {
  switch ($type) {
    case 'Contribution':
      \Civi\WMFHelper\Contribution::updateWMFDonorLastDonation($op, $entity);
      break;
    case 'ContributionRecur':
      \Civi\WMFHelper\ContributionRecur::cancelRecurAutoRescue($op, $id, $entity);
      break;
  }
}

/**
 * Add Email Preferences Center link to contact summary block list
 *
 * @param array $blocks
 */
function wmf_civicrm_civicrm_contactSummaryBlocks(array &$blocks) {
  PreferencesLink::contactSummaryBlocks($blocks);
}


/**
 * Add mailing event tab to contact summary screen
 * @param string $tabsetName
 * @param array $tabs
 * @param array $context
 */
function wmf_civicrm_civicrm_tabset($tabsetName, &$tabs, $context) {
    if ($tabsetName == 'civicrm/contact/view') {
        $contactID = $context['contact_id'];
        $url = CRM_Utils_System::url('civicrm/contact/zendesk/view', "reset=1&force=1&cid=$contactID");
        $tabs[] = [
            'title' => ts('Zendesk Tickets'),
            'id' => 'zendesk',
            'icon' => 'crm-i fa-envelope-open-o',
            'url' => $url,
            'weight' => 100,
            'class' => 'livePage'
        ];
    }
}

/**
 * Assign template parameters for email preference link contact summary block
 *
 * @param CRM_Core_Page $page
 */
function wmf_civicrm_civicrm_pageRun(CRM_Core_Page $page) {
  PreferencesLink::pageRun($page);
  $pageClass = get_class($page);
  // Pages to load the ContributionTracking Module into - loading into the summary page because of the contribution view popup
  $ctPages = ['CRM_Contact_Page_View_Summary', 'CRM_Contribute_Page_Tab'];
  if (in_array($pageClass, $ctPages)) {
    Civi::service('angularjs.loader')->addModules('afsearchContributionTracking');
  }
  ProfileDynamic::pageRun($page);
  // Only add the markup to the contribution page
  if ($pageClass === 'CRM_Contribute_Page_Tab') {
    $id = $page->getVar('_id');
    if ($id != NULL) {
      CRM_Core_Region::instance('page-body')->add([
        'markup' => '<crm-angular-js modules="afsearchContributionTracking">
          <div class="spacer" style="height: 20px;"></div>
          <h3>Contribution Tracking</h3><form id="bootstrap-theme"><afsearch-contribution-tracking options="{contribution_id:' . $id . '}"></afsearch-contribution-tracking></form></crm-angular-js>',
      ]);
    }
  }
}

/**
 * @param string $workflowName
 *
 * @return array
 * @throws \API_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function _wmf_civicrm_managed_get_translations(string $workflowName): array {
  $template = MessageTemplate::get(FALSE)
    ->addWhere('workflow_name', '=', $workflowName)
    ->addWhere('is_reserved', '=', 0)
    ->setSelect(['id'])->execute()->first();
  if (empty($template['id'])) {
    return [];
  }
  $translations = [];
  $directory = __DIR__ . '/msg_templates/' . $workflowName . '/';
  // The folder may contain pseudo-directories '.' and '..' and the files we actually want.
  $files = array_diff(scandir($directory), ['.', '..']);

  foreach ($files as $file) {
    $content = file_get_contents($directory . $file);
    $parts = explode('.', $file);
    $language = $parts[1];
    $field = 'msg_' . $parts[2];
    if ($workflowName === 'recurring_failed_message') {
      // We added these translations to live first
      // and then realised the namespacing was inadequate. But, it's a pain
      // to change the name of the existing installed entities - since
      // adding a new better name-spaced name would create duplicate translation
      // entities.
      $managedEntityName = 'translation_' . $language . '_' . $field;
    }
    else {
      $managedEntityName = 'translation_' . $workflowName . '_' . $language . '_' . $field;
    }

    $translations[] = [
      'name' => $managedEntityName,
      'entity' => 'Translation',
      'cleanup' => 'never',
      'update' => 'never',
      'params' => [
        'version' => 4,
        'checkPermissions' => FALSE,
        'match' => ['entity_id', 'entity_table', 'entity_field', 'language'],
        'values' => [
          'entity_table' => 'civicrm_msg_template',
          'entity_field' => $field,
          'entity_id' => $template['id'],
          'language' => Language::getLanguageCode($language),
          'string' => $content,
          'status_id:name' => 'active',
        ],
      ],
    ];
  }
  return $translations;
}

/**
 * Implements hook_civicrm_links().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_links/
 *
 */
function wmf_civicrm_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
  // links on the right side of the Recurring Contributions tab in Contributions
  if ($objectName === 'Contribution' && $op === 'contribution.selector.recurring') {
    // rearrange the order to be friendlier to Donor Relations
    $order = [
      'View',
      'Cancel',
      'Edit',
      'View Template',
    ];

    usort($links, function($a, $b) use ($order) {
      $pos_a = array_search($a['name'], $order);
      $pos_b = array_search($b['name'], $order);
      return $pos_a - $pos_b;
    });
  }

  if ($objectName === 'Activity') {
    Activity::links($objectId, $links);
  }
}

/**
 * Get a reference to the damaged queue.
 *
 * @param \CRM_Queue_Queue $original
 *
 * @return \CRM_Queue_Queue
 */
function find_damaged_queue(\CRM_Queue_Queue $original): \CRM_Queue_Queue {
  $name = $original->getName() . '/damaged';
  return \Civi::queue($name, [
    'type' => 'SqlParallel',
    'status' => 'aborted', // The queue never executes
    'error' => 'abort',
    'agent' => NULL,
  ]);
}

function wmf_civicrm_civicrm_queueTaskError(\CRM_Queue_Queue $queue, $item, &$outcome, ?\Throwable $exception) {
  $message = "Queue item from {$queue->getName()} with id={$item->id} failed with exception=\"{$exception->getMessage()}\"";
  Civi::log('wmf')->error($message);

  if ($outcome === 'abort' && !empty($item)) {
    $mailableDetails = $message . ", aborted and moved to the dedicated damaged queue";
    wmf_common_failmail('WMFQueues', '', $exception, $mailableDetails);

    \CRM_Core_DAO::executeQuery('UPDATE civicrm_queue_item SET queue_name = %1 WHERE id = %2', [
      1 => [find_damaged_queue($queue)->getName(), 'String'],
      2 => [$item->id, 'Positive'],
    ]);
    $outcome = 'retry';
  }
}


/**
 * Listener for hook defined in CRM_SmashPig_Hook::smashpigOutputStats
 * @param array $stats
 */
function wmf_civicrm_civicrm_smashpig_stats($stats) {
  $metrics = [];
  foreach ($stats as $status => $count) {
    $lcStatus = strtolower($status);
    $metrics["recurring_smashpig_$lcStatus"] = $count;
  }
  $prometheusPath = \Civi::settings()->get('metrics_reporting_prometheus_path');
  $reporter = new PrometheusReporter($prometheusPath);
  $reporter->reportMetrics('recurring_smashpig', $metrics);
}
