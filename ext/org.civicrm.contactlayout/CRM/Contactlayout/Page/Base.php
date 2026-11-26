<?php
use Civi\Api4\OptionValue;
use Civi\Api4\Setting;
use CRM_Contactlayout_ExtensionUtil as E;

class CRM_Contactlayout_Page_Base extends CRM_Core_Page {

  public function run() {
    $contactEditOptions = OptionValue::get(FALSE)
      ->addSelect('name', 'value')
      ->addWhere('option_group_id:name', '=', 'contact_edit_options')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('weight')
      ->execute()
      ->column('value', 'name');
    $settings = Setting::get(FALSE)
      ->addSelect('contact_edit_options', 'contactlayout_default_tabs')
      ->execute()
      ->column('value', 'name');

    Civi::resources()->addVars(E::SHORT_NAME, [
      'layouts' => (array) civicrm_api4('ContactLayout', 'get', ['orderBy' => ['weight' => 'ASC']]),
      'blocks' => (array) civicrm_api4('ContactLayout', 'getBlocks'),
      'tabs' => (array) civicrm_api4('ContactLayout', 'getTabs'),
      'contactTypes' => (array) civicrm_api4('ContactType', 'get', [
        'select' => ['id', 'name', 'label', 'icon', 'parent_id'],
        'where' => [['is_active', '=', 1]],
        'orderBy' => ['label' => 'ASC'],
      ]),
      'groups' => (array) civicrm_api4('Group', 'get', [
        'select' => ['name', 'title', 'description'],
        'where' => [['is_hidden', '=', 0], ['is_active', '=', 1], ['saved_search_id', 'IS NULL']],
      ]),
      'relationshipTypes' => (array) civicrm_api4('RelationshipType', 'get', ['where' => [['is_active', '=', TRUE]]]),
      'contactEditOptions' => $contactEditOptions,
      'systemDefaultsEnabled' => array_intersect($contactEditOptions, $settings['contact_edit_options']),
      'defaultTabs' => $settings['contactlayout_default_tabs'] ?: NULL,
    ]);

    // Load AngularJs module.
    Civi::service('angularjs.loader')->addModules('contactlayout');

    parent::run();
  }

}
