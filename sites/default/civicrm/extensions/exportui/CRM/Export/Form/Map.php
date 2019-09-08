<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 5                                                  |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2019                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2019
 * $Id$
 *
 */

/**
 * This class gets the name of the file to upload
 */
class CRM_Export_Form_Map extends CRM_Core_Form {

  /**
   * Loaded mapping ID
   *
   * @var int
   */
  protected $_mappingId;

  /**
   * Build the form object.
   *
   * @return void
   */
  public function preProcess() {
    $this->_mappingId = $this->get('mappingId');

    $contactTypes = array_column(CRM_Utils_Array::makeNonAssociative(CRM_Contact_BAO_ContactType::basicTypePairs(), 'id', 'text'), NULL, 'id');
    foreach (CRM_Contact_BAO_ContactType::subTypeInfo() as $subType) {
      $contactTypes[$subType['parent']]['children'][] = [
        'id' => $subType['name'],
        'text' => $subType['label'],
        'description' => $subType['description'] ?? NULL,
      ];
    }
    $mappingTypeId = $this->get('mappingTypeId');
    $mappings = civicrm_api3('Mapping', 'get', ['return' => ['name', 'description'], 'mapping_type_id' => $mappingTypeId, 'options' => ['limit' => 0]]);

    Civi::resources()->addVars('exportUi', [
      'fields' => CRM_Export_Utils::getExportFields($this->get('exportMode')),
      'contact_types' => array_values($contactTypes),
      'location_type_id' => CRM_Utils_Array::makeNonAssociative(CRM_Core_BAO_Address::buildOptions('location_type_id'), 'id', 'text'),
      'preview_data' => $this->getPreviewData(),
      'mapping_id' => $this->_mappingId,
      'mapping_description' => $mappings['values'][$this->_mappingId]['description'] ?? '',
      'mapping_type_id' => $mappingTypeId,
      'mapping_names' => CRM_Utils_Array::collect('name', $mappings['values']),
      'option_list' => [
        'phone_type_id' => CRM_Utils_Array::makeNonAssociative(CRM_Core_BAO_Phone::buildOptions('phone_type_id'), 'id', 'text'),
        'website_type_id' => CRM_Utils_Array::makeNonAssociative(CRM_Core_BAO_Website::buildOptions('website_type_id'), 'id', 'text'),
        'im_provider_id' => CRM_Utils_Array::makeNonAssociative(CRM_Core_BAO_IM::buildOptions('provider_id'), 'id', 'text'),
      ],
    ]);

    // Bootstrap angular and load exportui app
    $loader = new Civi\Angular\AngularLoader();
    $loader->setModules(['exportui']);
    $loader->load();
  }

  public function buildQuickForm() {

    $this->add('hidden', 'export_field_map');

    $this->addButtons([
      [
        'type' => 'back',
        'name' => ts('Previous'),
      ],
      [
        'type' => 'done',
        'icon' => 'fa-ban',
        'name' => ts('Return to Search'),
      ],
      [
        'type' => 'next',
        'icon' => 'fa-download',
        'name' => ts('Download File'),
      ],
    ]);
  }

  public function setDefaultValues() {
    $defaults = [];
    if ($this->_mappingId) {
      $mappingFields = civicrm_api3('mappingField', 'get', ['mapping_id' => $this->_mappingId, 'options' => ['limit' => 0, 'sort' => 'column_number']]);
      $defaults['export_field_map'] = json_encode(array_values($mappingFields['values']));
    }
    return $defaults;
  }

  /**
   * Process the form submission.
   */
  public function postProcess() {
    $params = $this->controller->exportValues($this->_name);
    $exportParams = $this->controller->exportValues('Select');

    // Redirect back to search if "done" button pressed
    if ($this->controller->getButtonName('done') == '_qf_Map_done') {
      $currentPath = CRM_Utils_System::currentPath();
      $urlParams = NULL;
      $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $this);
      if (CRM_Utils_Rule::qfKey($qfKey)) {
        $urlParams = "&qfKey=$qfKey";
      }
      $this->controller->resetPage($this->_name);
      return CRM_Utils_System::redirect(CRM_Utils_System::url($currentPath, 'force=1' . $urlParams));
    }

    $selectedFields = json_decode($params['export_field_map'], TRUE);

    //get the csv file
    CRM_Export_BAO_Export::exportComponents($this->get('selectAll'),
      $this->get('componentIds'),
      (array) $this->get('queryParams'),
      $this->get(CRM_Utils_Sort::SORT_ORDER),
      $selectedFields,
      $this->get('returnProperties'),
      $this->get('exportMode'),
      $this->get('componentClause'),
      $this->get('componentTable'),
      $this->get('mergeSameAddress'),
      $this->get('mergeSameHousehold'),
      $exportParams,
      $this->get('queryOperator')
    );
  }

  /**
   * @return array
   */
  protected function getPreviewData() {
    $exportParams = $this->controller->exportValues('Select');
    $isPostalOnly = (
      isset($exportParams['postal_mailing_export']['postal_mailing_export']) &&
      $exportParams['postal_mailing_export']['postal_mailing_export'] == 1
    );
    $processor = new CRM_Export_BAO_ExportProcessor($this->get('exportMode'), NULL, $this->get('queryOperator'), $this->get('mergeSameHousehold'), $isPostalOnly, $this->get('mergeSameAddress'));
    $processor->setComponentTable($this->get('componentTable'));
    $processor->setComponentClause($this->get('componentClause'));
    $data = $processor->getPreview(4);
    $ids = CRM_Utils_Array::collect('id', $data);
    $data = array_pad($data, 4, []);

    // Add location-type-specific data
    if ($ids) {
      foreach (['address', 'phone', 'email'] as $ent) {
        foreach (civicrm_api3($ent, 'get', ['options' => ['limit' => 0], 'contact_id' => ['IN' => $ids]])['values'] as $loc) {
          $row = array_search($loc['contact_id'], $ids);
          $suffix = '_' . $loc['location_type_id'] . ($ent == 'phone' ? '_' . $loc['phone_type_id'] : '');
          CRM_Utils_Array::remove($loc, 'id', 'contact_id', 'location_type_id', 'phone_type_id');
          foreach ($loc as $name => $val) {
            $data[$row][$name . $suffix] = $val;
          }
        }
      }
    }
    return $data;
  }

  /**
   * Return a descriptive name for the page, used in wizard header
   *
   * @return string
   */
  public function getTitle() {
    return ts('Select Fields to Export');
  }

}
