<?php
use CRM_CiviDataTranslate_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_CiviDataTranslate_ApiGetWrapper {

  protected $fields;

  /**
   * CRM_CiviDataTranslate_ApiWrapper constructor.
   *
   * This wrapper replaces values with configured translated values, if any exist.
   *
   * @param $enity
   * @param $translatedFields
   */
  public function __construct($translatedFields) {
    $this->fields = $translatedFields;
  }

  /**
   * @inheritdoc
   */
  public function fromApiInput($apiRequest) {
    return $apiRequest;
  }

  /**
   * @inheritdoc
   */
  public function toApiOutput($apiRequest, $result) {
    foreach ($result as &$value) {
      if (!isset($value['id'], $this->fields[$value['id']])) {
        continue;
      }
      $toSet = array_intersect_key($this->fields[$value['id']], $value);
      $value = array_merge($value, $toSet);
    }
    return $result;
  }
}
