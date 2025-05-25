<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/31/18
 * Time: 10:07 AM
 */

use CRM_Forgetme_ExtensionUtil as E;
/**
 * Class CRM_Forgetme_Showme
 *
 * Show me class is intended to get as much data as is relevant about an entity
 * in a key-value format where the keys align to the entitiy metadata and the values
 * are formatted for display.
 *
 * This differs from other displays in Civi (e.g forms) in that forms tend to be based on opt-in
 * rather than opt-out (ie. we decide what to show). Here we decide only what not to shoe.
 */
class CRM_Forgetme_Showme {

  /**
   * @var string
   */

  protected $entity;
  /**
   * @var array
   */
  protected $filters;

  /**
   * Options from the api - notably this can contain 'OR'.
   *
   * @var array
   */
  protected $apiOptions;

  /**
   * @return array
   */
  public function getApiOptions() {
    return $this->apiOptions;
  }

  /**
   * @param array $apiOptions
   */
  public function setApiOptions($apiOptions) {
    $this->apiOptions = $apiOptions;
  }

  /**
   * @var array
   */
  protected $metadata;

  /**
   * @return array
   */
  public function getMetadata() {
    return $this->metadata;
  }

  /**
   * @param array $metadata
   */
  public function setMetadata($metadata) {
    $this->metadata = $metadata;
  }

  protected $displaySeparator = '|';

  /**
   * @return string
   */
  public function getDisplaySeparator() {
    return $this->displaySeparator;
  }

  /**
   * @param string $displaySeparator
   */
  public function setDisplaySeparator($displaySeparator) {
    $this->displaySeparator = $displaySeparator;
  }

  /**
   * Negative fields are fields that are not of interest if they are not true.
   *
   * Ie. is_deceased, do_not_email etc are implicitly uninteresting unless true.
   *
   * @var array
   */
  protected $negativeFields = [];

  /**
   * Fields that are for the system and are not useful to the user.
   *
   * @var array
   */
  protected $internalFields = [];

  /**
   * @return array
   */
  public function getInternalFields() {
    return $this->internalFields;
  }

  /**
   * @param array $internalFields
   */
  public function setInternalFields($internalFields) {
    $this->internalFields = $internalFields;
  }

  /**
   * Display values.
   *
   * @var array
   */
  protected $displayValues = [];

  /**
   * @return array
   */
  public function getNegativeFields() {
    return $this->negativeFields;
  }

  /**
   * @param array $negativeFields
   */
  public function setNegativeFields($negativeFields) {
    $this->negativeFields = $negativeFields;
  }

  /**
   * Showme constructor.
   *
   * @param string $entity
   * @param array $filters
   * @param array $apiOptions
   */
  public function __construct($entity, $filters, $apiOptions) {
    $this->entity = $entity;
    $this->setMetadataForEntity($entity);
    $this->setFilters($filters);
    $this->setApiOptions($apiOptions);
    $this->setEntityBasedMetadataDefinitions($entity);
  }

  /**
   * Get all the values for the entity.
   *
   * @return array
   */
  protected function getAllValuesForEntity() {
    $getParams = $this->filters;
    $getParams['return'] = array_keys($this->metadata);
    $getParams['options'] = array_merge($this->getApiOptions(), ['limit' => 0]);
    return civicrm_api3($this->entity, 'get', $getParams)['values'];
  }

  /**
   * Get the values for the entity that are suitable for display.
   */
  public function getDisplayValues() {
    if (empty($this->displayValues)) {
      $this->displayValues = $this->getAllValuesForEntity();
      $this->preFormatDisplayValues();
    }
    return $this->displayValues;
  }

  /**
   * Get the displayable data as a string.
   *
   * @return array
   */
  public function getDisplayTiles() {
    $return = [];
    foreach ($this->getDisplayValues() as $index => $entities) {
      $display = [];
      foreach ($entities as $key => $value) {
        $display[] = (isset($this->metadata[$key]['title']) ? $this->metadata[$key]['title'] : $key) . ':' . $value;
      }
      $return[$index] = implode($this->displaySeparator, $display);
    }
    return $return;
  }

  /**
   * Filter out fields with no data.
   */
  protected function filterOutEmptyFields() {
    foreach ($this->displayValues as $index => $displayValue) {
      foreach ($displayValue as $field => $value)
      if ($value === '') {
        unset($this->displayValues[$index][$field]);
      }
    }
  }

  /**
   * Filter out {$entity}_id as it duplicates if field.
   */
  protected function filterOutDuplicateEntityID() {
    foreach (array_keys($this->displayValues) as $displayValue) {
      unset($this->displayValues[$displayValue][strtolower($this->entity) . '_id']);
    }
  }

  /**
   * Filter out negative values when they are false.
   *
   * Transform to yes when true.
   */
  protected function filterOutNegativeValues() {
    foreach (array_keys($this->displayValues) as $displayValue) {
      foreach ($this->getNegativeFields() as $negativeField) {
        if (isset($this->displayValues[$displayValue][$negativeField])) {
          if ($this->displayValues[$displayValue][$negativeField]) {
            $this->displayValues[$displayValue][$negativeField] = E::ts('Yes');
          }
          else {
            unset($this->displayValues[$displayValue][$negativeField]);
          }
        }
      }
    }
  }

  /**
   * Filter out fields that are system fields not useful to users.
   */
  protected function filterOutInternalFields() {
    foreach (array_keys($this->displayValues) as $displayValue) {
      foreach ($this->getInternalFields() as $internalField) {
        if (isset($this->displayValues[$displayValue][$internalField])) {
          unset($this->displayValues[$displayValue][$internalField]);
        }
      }
    }
  }

  /**
   * Consolidate and filter fields based on option values.
   *
   * We are likely to get 2 fields returned eg.
   *   gender_id=1
   *   gender=Female
   *
   * We consolidate this to gender_id=Female (gender_id is the real
   * field & has the metadata.
   */
  protected function processOptionValueFields() {
    foreach ($this->displayValues as $displayIndex => $displayValue) {
      foreach ($displayValue as $index => $field) {
        if (!isset($this->metadata[$index]['pseudoconstant']['optionGroupName'])) {
          continue;
        }
        $secondaryFieldName = $this->metadata[$index]['pseudoconstant']['optionGroupName'];

        if (isset($displayValue[$secondaryFieldName])) {
          $this->displayValues[$displayIndex][$index] = $displayValue[$secondaryFieldName];
        }
        unset($this->displayValues[$displayIndex][$secondaryFieldName]);
      }
    }
  }

  /**
   * Remove or alter values in conjunction with the metadata.
   */
  protected function preFormatDisplayValues() {
    $this->filterOutEmptyFields();
    $this->filterOutDuplicateEntityID();
    $this->filterOutNegativeValues();
    $this->processOptionValueFields();
    $this->filterOutInternalFields();
  }

  /**
   * @param $entity
   */
  protected function setEntityBasedMetadataDefinitions($entity) {
    $this->setInternalFields(CRM_Forgetme_Metadata::getMetadataForEntity($entity, 'internal_fields'));
    $this->setNegativeFields(CRM_Forgetme_Metadata::getMetadataForEntity($entity, 'negative_fields'));
  }

  /**
   * @param $filters
   */
  protected function setFilters($filters) {
    $acceptableFields = array_merge($this->metadata, ['debug', 'sequential', 'check_permissions']);
    $this->filters = array_intersect_key($filters, $acceptableFields);
  }

  /**
   * @param $entity
   */
  protected function setMetadataForEntity($entity) {
    $this->metadata = civicrm_api3($entity, 'getfields', ['action' => 'get'])['values'];
    foreach ($this->metadata as $key => $value) {
      if ($value['name'] !== $key) {
        // in some cases CiviCRM keys by 'uniqueName' instead of 'name'.
        // key by both so we don't miss any important params - esp not 'id' vs payment_token_id
        // on payment token! T212705
        $this->metadata[$value['name']] = $value;
      }
    }
  }

}
