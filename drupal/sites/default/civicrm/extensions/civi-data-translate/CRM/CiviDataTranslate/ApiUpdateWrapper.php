<?php
use CRM_CiviDataTranslate_ExtensionUtil as E;
use Civi\Api4\Strings;

/**
 * Collection of upgrade steps.
 */
class CRM_CiviDataTranslate_ApiUpdateWrapper {

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
   * Filter out any language specific fields.
   *
   * These are saved in the toApiOutput wrapper.
   * @inheritdoc
   *
   * @param \Civi\Api4\Generic\DAOUpdateAction $apiRequest
   */
  public function fromApiInput($apiRequest) {
    $apiRequest->setValues(array_diff_key($apiRequest->getValues(), $this->fields));
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
      $strings = Strings::get()
        ->setSelect(['id', 'entity_field'])
        ->setWhere([
          ['entity_id', '=', $value['id']],
          ['entity_table', '=', CRM_Core_DAO_AllCoreTables::getTableForEntityName($apiRequest->getEntityName())],
          ['language', '=', $apiRequest->getLanguage()],
          ['is_active', '=', TRUE],
          ['is_default', '=', TRUE],
          ['entity_field', 'IN', array_keys($this->fields)],
      ])->setCheckPermissions($apiRequest->getCheckPermissions())
      ->execute()
      ->indexBy('entity_field');
      foreach ($this->fields as $fieldName => $string) {
        if (empty($strings[$fieldName])) {
          Strings::create()
            ->setValues([
              'entity_id' => $value['id'],
              'string' => $string,
              'language' => $apiRequest->getLanguage(),
              'entity_table' => CRM_Core_DAO_AllCoreTables::getTableForEntityName($apiRequest->getEntityName()),
              'entity_field' => $fieldName,
              'is_default' => TRUE,
            ])
            ->setCheckPermissions($apiRequest->getCheckPermissions())
            ->execute();
        }
        else {
          Strings::update()
            ->setValues([
              'entity_id' => $value['id'],
              'string' => $string,
              'language' => $apiRequest->getLanguage(),
              'entity_table' => CRM_Core_DAO_AllCoreTables::getTableForEntityName($apiRequest->getEntityName()),
              'entity_field' => $fieldName,
              'is_default' => TRUE,
              'id' => $strings[$fieldName]['id'],
            ])
            ->setCheckPermissions($apiRequest->getCheckPermissions())
            ->execute();
        }
      }
    }
    unset(\Civi::$statics['cividatatranslate']['translate_fields'][$apiRequest['entity']]);
    return $result;
  }
}
