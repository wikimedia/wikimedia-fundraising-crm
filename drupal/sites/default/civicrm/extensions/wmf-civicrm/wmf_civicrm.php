<?php

require_once 'wmf_civicrm.civix.php';
// phpcs:disable
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
 * @throws \WmfException
 * @throws \CiviCRM_API3_Exception
 */
function wmf_civicrm_civicrm_buildForm($formName, &$form) {
  switch ($formName) {
    case 'CRM_Custom_Form_CustomDataByType':
      if ($form->_type === 'Contribution' && empty($form->_entityId)) {
        // New hand-entered contributions get a default for no_thank_you
        $no_thank_you_reason_field_name = _wmf_civicrm_get_form_custom_field_name('no_thank_you');
        $giftSourceField = _wmf_civicrm_get_form_custom_field_name('Campaign');

        $no_thank_you_toggle_form_elements = [
          $giftSourceField,
          'financial_type_id'
        ];

        if ($no_thank_you_reason_field_name && $form->elementExists($no_thank_you_reason_field_name)) {
          if (CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'financial_type_id', $form->_subType ?? NULL) === 'Stock') {
            $form->setDefaults([$no_thank_you_reason_field_name => '']);
          }
          else {
            $form->setDefaults(
              [$no_thank_you_reason_field_name => 'Manually entered']
            );
          }
          CRM_Core_Resources::singleton()->addScript(wmf_civicrm_get_no_thankyou_js($no_thank_you_reason_field_name, $no_thank_you_toggle_form_elements));
        }
      }
      break;
    case 'CRM_Contribute_Form_Contribution':
      // Only run this validation for users having the Engage role.
      // @todo - move the user_has_role out of the extension. In order
      // to ready this for drupal we can switch to using a permission
      // for engage 'access engage ui options'.
      if (!wmf_civicrm_user_has_role('Engage Direct Mail')) {
        break;
      }

      // Default to the Engage contribution type, if this is a new contribution.
      if ($form->_action & CRM_Core_Action::ADD) {
        $engage_contribution_type_id = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Engage');
        $form->setDefaults([
          'financial_type_id' => $engage_contribution_type_id,
        ]);
        $form->assign('customDataSubType', $engage_contribution_type_id);
      }

      // Make Batch Number required, if the field exists.
      $batch_num_field_name = _wmf_civicrm_get_form_custom_field_name('import_batch_number');
      if ($batch_num_field_name && $form->elementExists($batch_num_field_name)) {
        $form->addRule($batch_num_field_name, t('Batch number is required'), 'required');
      }
      break;

    case 'CRM_Contribute_Form_Search':
    case 'CRM_Contact_Form_Search_Advanced':
      // Remove the field 'Contributions OR Soft Credits?' from the contribution search
      // and advanced search pages.
      // This filter has to be removed as it attempts to create an insanely big
      // temporary table that kills the server.
      if ($form->elementExists('contribution_or_softcredits')) {
        $form->removeElement('contribution_or_softcredits');
      }
      break;

  }
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
