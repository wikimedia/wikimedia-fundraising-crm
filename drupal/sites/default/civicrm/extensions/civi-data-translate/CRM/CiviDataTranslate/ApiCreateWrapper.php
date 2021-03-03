<?php
use CRM_CiviDataTranslate_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_CiviDataTranslate_ApiCreateWrapper {

  protected $fields;

  /**
   * CRM_CiviDataTranslate_ApiWrapper constructor.
   *
   * This wrapper replaces values with configured translated values, if any exist.
   *
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
   *
   * @param \Civi\Api4\Generic\AbstractAction $apiRequest
   * @param array $result
   *
   * @return mixed
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function toApiOutput($apiRequest, $result) {
    foreach ($result as &$value) {
      foreach ($this->fields as $fieldName => $string) {
        \Civi\Api4\Strings::create()
          ->setValues([
            'entity_id'=> $value['id'],
            'string' => $string,
            'language' => $apiRequest->getLanguage(),
            'entity_table' => CRM_Core_DAO_AllCoreTables::getTableForEntityName($apiRequest->getEntityName()),
            'entity_field' =>$fieldName,
            'is_default' => TRUE,
          ])
          ->setCheckPermissions($apiRequest->getCheckPermissions())
          ->execute();
      }
    }
    return $result;
  }
}
