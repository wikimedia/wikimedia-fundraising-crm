<?php

require_once 'deduper.civix.php';
use Civi\Api4\DedupeRule;
use Civi\Api4\UserJob;
use Civi\Import\FullNameHook;
use Symfony\Component\DependencyInjection\Definition;
use CRM_Deduper_ExtensionUtil as E;
use Civi\Api4\Email;
use Civi\Api4\Phone;
use Civi\Api4\Address;
use Civi\Api4\Service\Spec\Provider\ContactFullNameSpecProvider;

// checking if the file exists allows compilation elsewhere if desired.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function deduper_civicrm_config(&$config) {
  _deduper_civix_civicrm_config($config);
  // This listener unpacks the full_name field. It has a priority of
  // 100 so that it will run before other listeners, which might want to
  // know the first_name & last_name.
  \Civi::dispatcher()->addListener('hook_civicrm_importAlterMappedRow', [FullNameHook::class, 'alterMappedImportRow'], 100);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function deduper_civicrm_install() {
  _deduper_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function deduper_civicrm_enable() {
  _deduper_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_angularModules().
 *
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function deduper_civicrm_angularModules(&$angularModules) {
  $angularModules['xeditable'] = [
    'ext' => 'deduper',
    'js' => ['bower_components/angular-xeditable/dist/js/xeditable.js'],
    'css' => ['bower_components/angular-xeditable/dist/css/xeditable.css'],
  ];
  $angularModules['angularUtils.directives.dirPagination'] = [
    'ext' => 'deduper',
    'js' => ['bower_components/angularUtils-pagination/dirPagination.js'],
  ];
}

/**
 * Implements hook_civicrm_searchKitTasks().
 *
 * @param array[] $tasks
 *
 * @noinspection PhpUnused
 */
function deduper_civicrm_searchKitTasks(array &$tasks) {
  $tasks['Contact']['flip'] = [
    'module' => 'dedupeSearchTasks',
    'title' => E::ts('Flip first/last name'),
    'icon' => 'fa-random',
    'uiDialog' => ['templateUrl' => '~/dedupeSearchTasks/dedupeSearchTaskFlip.html'],
  ];
}

/**
 * Add dedupe searches to actions available.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_summaryActions
 *
 * @param array $actions
 * @param int $contactID
 */
function deduper_civicrm_summaryActions(&$actions, $contactID) {
  if ($contactID === NULL) {
    return;
  }
  try {
    $ruleGroups = civicrm_api3('RuleGroup', 'get', [
      'contact_type' => civicrm_api3('Contact', 'getvalue', ['id' => $contactID, 'return' => 'contact_type']),
    ]);
    $weight = 500;

    $contactIDS = array($contactID);
    foreach ($ruleGroups['values'] as $ruleGroup) {
      $actions['otherActions']['dupe' . $ruleGroup['id']] = array(
        'title' => ts('Find matches using Rule : %1', array(1 => $ruleGroup['title'])),
        'name' => ts('Find matches using Rule : %1', array(1 => $ruleGroup['title'])),
        'weight' => $weight,
        'ref' => 'dupe-rule crm-contact_activities-list',
        'key' => 'dupe' . $ruleGroup['id'],
        'href' => CRM_Utils_System::url('civicrm/contact/dedupefind', array(
          'reset' => 1,
          'action' => 'update',
          'rgid' => $ruleGroup['id'],
          'criteria' => json_encode(array('contact' => array('id' => array('IN' => $contactIDS)))),
          'limit' => count($contactIDS),
        )),
      );
      $weight++;
    }
  }
  catch (CRM_Core_Exception $e) {
    // This would most likely happen if viewing a deleted contact since we are not forcing
    // them to be returned. Keep calm & carry on.
  }
}

/**
 * Keep merge conflict analysis out of log tables. It is temporary data.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterLogTables
 *
 * @param array $logTableSpec
 */
function deduper_civicrm_alterLogTables(&$logTableSpec) {
  unset($logTableSpec['civicrm_merge_conflict']);
}

/**
 * This hook is called to display the list of actions allowed after doing a search,
 * allowing you to inject additional actions or to remove existing actions.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_searchTasks
 *
 * @param string $objectType
 * @param array $tasks
 */
function deduper_civicrm_searchTasks($objectType, &$tasks) {
  if ($objectType === 'contact') {
    $tasks[] = [
      'title' => ts('Find duplicates for these contacts'),
      'class' => 'CRM_Contact_Form_Task_FindDuplicates',
      'result' => TRUE,
    ];

  }
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 * @noinspection PhpUnusedParameterInspection
 */
function deduper_civicrm_preProcess($formName, &$form) {
  if ($formName === 'CRM_Contact_Form_Merge') {
    // Re-add colour coding - sill not be required when issue is resolved.
    // https://github.com/civicrm/org.civicrm.shoreditch/issues/373
    CRM_Core_Resources::singleton()->addStyle('
    /* table row highlightng */
    .page-civicrm-contact-merge .crm-container table.row-highlight tr.crm-row-ok td{
       background-color: #EFFFE7 !important;
    }
    .page-civicrm-contact-merge .crm-container table.row-highlight .crm-row-error td{
       background-color: #FFECEC !important;
    }');
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function deduper_civicrm_navigationMenu(&$menu) {
  _deduper_civix_insert_navigation_menu($menu, 'Contacts', [
    'label' => E::ts('Deduper'),
    'name' => 'deduper',
    'url' => 'civicrm/a/#/dupefinder/Contact',
    'permission' => 'access CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _deduper_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', [
    'label' => E::ts('Deduper'),
    'name' => 'Deduper',
    'url' => '',
    'permission' => 'administer CiviCRM data',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _deduper_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens/Deduper', [
    'label' => E::ts('Deduper Conflict Resolution'),
    'name' => 'dedupe_settings',
    'url' => 'civicrm/admin/setting/deduper',
    'permission' => 'administer CiviCRM data',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _deduper_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens/Deduper', [
    'label' => E::ts('Deduper Equivalent Names'),
    'name' => 'dedupe_settings',
    'url' => 'civicrm/search#/display/Equivalent_names/Equivalent_names',
    'permission' => 'administer CiviCRM data',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _deduper_civix_navigationMenu($menu);
}

/**
 * Do not require administer CiviCRM to use deduper.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_alterAPIPermissions
 *
 * @param string $entity
 * @param string $action
 * @param array $params
 * @param array $permissions
 */
function deduper_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // Set permission for all deduping actions to 'merge duplicate contacts'
  // We are still hoping to get something merged upstream for these 2.
  $permissions['merge'] = [
    'mark_duplicate_exception' => ['merge duplicate contacts'],
    'getcount' => ['merge duplicate contacts'],
  ];

  // This isn't really exposed at the moment but it would have the same perms if it were.
  // It would be to allow conflicts to be marked as skip-handlable.
  $permissions['merge_conflict'] = [
    'get' => ['merge duplicate contacts'],
    'create' => ['merge duplicate contacts'],
  ];

  // This is a fairly brittle approach to allowing users without Administer CiviCRM
  // to access the deduper screen. See https://lab.civicrm.org/dev/core/issues/1633
  // for thoughts on a future approach.
  if ($entity === 'setting' &&
    (($action === 'get' && isset($params['return']) && $params['return'] === 'deduper_equivalent_name_handling')
    || ($action === 'getoptions' && $params['field'] === 'deduper_equivalent_name_handling'))
  ) {
    $permissions['setting']['get'] = [['merge duplicate contacts', 'administer CiviCRM']];
  }

}

/**
 * Implements hook_civicrm_merge().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_merge
 *
 * @throws \CRM_Core_Exception
 */
function deduper_civicrm_merge($type, &$refs, $mainId, $otherId, $tables) {
  switch ($type) {
    case 'flip':
      // This is the closest we have to a pre-hook. It is called in batch mode
      // and since duplicate locations mess up merges this is our chance to fix any before
      // the merge starts.
      Email::clean()->setContactIDs([$mainId, $otherId])->execute();
      Phone::clean()->setContactIDs([$mainId, $otherId])->execute();
      Address::clean()->setContactIDs([$mainId, $otherId])->execute();
      return;

    case 'batch':
    case 'form':
      $refs['migration_info']['context'] = $type;
      // Randomise log connection id. This ensures reverts can be done without reverting the whole batch if logging is enabled.
      CRM_Core_DAO::executeQuery('SET @uniqueID = %1', array(
        1 => array(
          uniqid() . CRM_Utils_String::createRandom(4, CRM_Utils_String::ALPHANUMERIC),
          'String',
        ),
      ));

      if ($type === 'batch') {
        $merger = new CRM_Deduper_BAO_MergeHandler($refs, (int) $mainId, (int) $otherId, $type, ($refs['mode'] === 'safe'));
        $merger->resolve();
        $refs = $merger->getDedupeData();
        $refs['migration_info']['merge_handler'] = $merger;
      }
  }
}

/**
 * Implement post hook to clear cache for any changes to NamePair table.
 *
 * @param string $op
 * @param string $objectName
 * @param int $objectId
 * @param \CRM_Core_DAO $objectRef
 */
function deduper_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName === 'ContactNamePair') {
    foreach ([$objectRef->name_b, $objectRef->name_a] as $value) {
      if ($value && \Civi::cache('dedupe_pairs')->has('name_alternatives_' . md5($value))) {
        \Civi::cache('dedupe_pairs')->delete('name_alternatives_' . md5($value));
      }
    }
  }
}

/**
 * Alter location data 'planned' for merge.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterLocationMergeData
 *
 * @param array $blocksDAO
 *   Array of location DAO to be saved. These are arrays in 2 keys 'update' &
 *   'delete'.
 * @param int $mainId
 *   Contact_id of the contact that survives the merge.
 * @param int $otherId
 *   Contact_id of the contact that will be absorbed and deleted.
 * @param array $migrationInfo
 *   Calculated migration info, informational only.
 */
function deduper_civicrm_alterLocationMergeData(&$blocksDAO, $mainId, $otherId, $migrationInfo) {
  // Do not override form mode.
  if ($migrationInfo['context'] !== 'form' && isset($migrationInfo['merge_handler'])) {
    /* @var CRM_Deduper_BAO_MergeHandler $merger */
    $merger = $migrationInfo['merge_handler'];
    $merger->setLocationBlocks($blocksDAO);
    $merger->resolveLocations();
    $blocksDAO = $merger->getLocationBlocks();
  }

}

/**
 * Set up a cache for saving dedupe pairs in.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container
 *
 * We want to be able to save dedupe name match info into an efficient cache - this equates to
 * caching in php for the duration of a process & Redis / MemCache (if available) for longer.
 *
 * Using 'withArray' => 'fast' means that if we access a value from Redis it's help in a php
 * cache for the rest of the process - so if we look up 'Tom' a lot we will usually be able to use
 * memory, sometimes Redis & rarely have to look up the table. If Redis is not available we will
 * use mysql to look up the table more.
 *
 * In latest CiviCRM there is a pre-defined 'metadata' cache with similar definitions & in the
 * future we will consider switching to it.
 *
 * https://docs.civicrm.org/dev/en/latest/framework/cache/
 *
 * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
 */
function deduper_civicrm_container($container) {
  $container->setDefinition('cache.dedupe_pairs', new Definition(
    'CRM_Utils_Cache_Interface', [
      [
        'type' => ['*memory*', 'ArrayCache'],
        'name' => 'dedupe_pairs',
        'withArray' => 'fast',
      ],
    ]
  ))->setPublic(TRUE)->setFactory('CRM_Utils_Cache::create');

  $container->setDefinition('civi.api.prepare', new Definition(
    ContactFullNameSpecProvider::class,
    []
  ))->addTag('kernel.event_subscriber')->setPublic(TRUE);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function deduper_civicrm_entityTypes(&$entityTypes) {
  $entityTypes['CRM_Deduper_DAO_ContactNamePairFamily']['links_callback'][] = ['CRM_Deduper_BAO_ContactNamePairFamily', 'alterLinks'];
}


/**
 * @param $formName
 * @param $fields
 * @param $files
 * @param CRM_Contribute_Import_Form_MapField $form
 * @param $errors
 *
 * @return void
 */
function deduper_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors): void {
  if ($formName !== 'CRM_Contribute_Import_Form_MapField') {
    return;
  }

  // //"Missing required contact matching fields.  first_name(weight 5) last_name(weight 5) street_address(weight 5) middle_name(weight 1) suffix_id(weight 1) (Sum of all weights should be greater than or equal to threshold: 15).<br />";
  $requiredFieldsError = $form->getElementError('_qf_default');
  // Regular expression pattern to extract weights and threshold. So says ChatGPT.
  $pattern = '/first_name\(weight (\d+)\).*last_name\(weight (\d+)\).*threshold:\s*(\d+)/';

  // Perform the regex match
  if ($requiredFieldsError && preg_match($pattern, $requiredFieldsError, $matches)) {
    $firstNameWeight = (int) $matches[1];
    $lastNameWeight = (int) $matches[2];
    $threshold = (int) $matches[3];
    if ($threshold) {
      $remainingThreshold = $threshold - $firstNameWeight - $lastNameWeight;
      if ($remainingThreshold <= 0) {
        // We can git rid of this error as we have the full_name.
        $form->setElementError('_qf_default', NULL);
        return;
      }
      $metadata = UserJob::get(FALSE)
        ->addWhere('id', '=', $form->getUserJobID())
        ->addSelect('metadata')
        ->execute()->first()['metadata'];
      $mappedValues = $fields['mapper'];
      $mappedFields = [];
      foreach ($mappedValues as $mappedValue) {
        if ($mappedValue[0]) {
          $fieldName = str_replace('__', '.', $mappedValue[0]);
          if (str_contains($fieldName, '.')) {
            // If the field name contains a . - eg. address_primary.street_address
            // we just want the part after the .
            $fieldName = substr($fieldName, strpos($fieldName, '.') + 1);
          }
          if (str_contains($fieldName, '.')) {
            // Sorry for the hack - regex another day... We might have contact.primary_address.street_address...
            $fieldName = substr($fieldName, strpos($fieldName, '.') + 1);
          }
          $mappedFields[] = $fieldName;
        }
      }
      $dedupeFields = DedupeRule::get(FALSE)
        ->addWhere('dedupe_rule_group_id.name', '=', $metadata['entity_configuration']['Contact']['dedupe_rule'])
        ->execute();
      foreach ($dedupeFields as $field) {
        if (in_array($field['rule_field'], $mappedFields, TRUE)) {
          $remainingThreshold = $remainingThreshold - $field['rule_weight'];
          if ($remainingThreshold <= 0) {
            // We can git rid of this error as we have the full_name.
            $form->setElementError('_qf_default', NULL);
            return;
          }
        }
      }
    }
  }

}
