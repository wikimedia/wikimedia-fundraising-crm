<?php

namespace Civi\Test;

use Civi\Api4\Generic\Result;
use Civi\Api4\Utils\CoreUtil;

/**
 * Class Api3TestTrait
 *
 * @package Civi\Test
 *
 * This trait defines a number of helper functions for testing APIv4.
 *
 * This trait is intended for use with PHPUnit-based test cases.
 */
trait Api4TestTrait {

  /**
   * Inserts a test record, supplying all required values if not provided.
   *
   * Test records will be automatically deleted during tearDown.
   *
   * @param string $entityName
   * @param array $values
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\NotImplementedException
   */
  public function createTestRecord(string $entityName, array $values = []): ?array {
    return $this->saveTestRecords($entityName, ['records' => [$values]])->single();
  }

  /**
   * Saves one or more test records, supplying default values.
   *
   * Test records will be automatically deleted during tearDown.
   *
   * @param string $entityName
   * @param array $saveParams
   * @return \Civi\Api4\Generic\Result
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\NotImplementedException
   */
  public function saveTestRecords(string $entityName, array $saveParams): Result {
    $saveParams += [
      'checkPermissions' => FALSE,
      'defaults' => [],
    ];
    $idField = CoreUtil::getIdFieldName($entityName);
    foreach ($saveParams['records'] as &$record) {
      $record += $saveParams['defaults'];
      if (empty($record[$idField])) {
        $this->getRequiredValuesToCreate($entityName, $record);
      }
    }
    // Unset for clarity as it leaks from the foreach & is a reference.
    unset($record);
    $saved = civicrm_api4($entityName, 'save', $saveParams);
    foreach ($saved as $item) {
      $this->testRecords[] = [$entityName, [[$idField, '=', $item[$idField]]]];
    }
    return $saved;
  }

  /**
   * Generate some random lowercase letters
   * @param int $len
   * @return string
   */
  protected function randomLetters(int $len = 10): string {
    return \CRM_Utils_String::createRandom($len, implode('', range('a', 'z')));
  }

  /**
   * Get the required fields for the api entity + action.
   *
   * @param string $entity
   * @param array $values
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getRequiredValuesToCreate(string $entity, array &$values = []): array {
    $requiredFields = civicrm_api4($entity, 'getfields', [
      'action' => 'create',
      'loadOptions' => TRUE,
      'where' => [
        ['type', 'IN', ['Field', 'Extra']],
        ['OR',
          [
            ['required', '=', TRUE],
            // Include conditionally-required fields only if they don't create a circular FK reference
            ['AND', [['required_if', 'IS NOT EMPTY'], ['fk_entity', '!=', $entity]]],
          ],
        ],
        ['default_value', 'IS EMPTY'],
        ['readonly', 'IS EMPTY'],
      ],
    ], 'name');

    $extraValues = [];
    foreach ($requiredFields as $fieldName => $requiredField) {
      if (!isset($values[$fieldName])) {
        $extraValues[$fieldName] = $this->getRequiredValue($requiredField);
      }
    }

    // Hack in some extra per-entity values that couldn't be determined by metadata.
    // Try to keep this to a minimum and improve metadata as a first-resort.

    switch ($entity) {
      case 'UFField':
        $extraValues['field_name'] = 'activity_campaign_id';
        break;

      case 'Translation':
        $extraValues['entity_table'] = 'civicrm_msg_template';
        $extraValues['entity_field'] = 'msg_subject';
        $extraValues['entity_id'] = $this->getFkID('MessageTemplate');
        break;

      case 'Case':
        $extraValues['creator_id'] = $this->getFkID('Contact');
        break;

      case 'CaseContact':
        // Prevent "already exists" error from using an existing contact id
        $extraValues['contact_id'] = $this->createTestRecord('Contact')['id'];
        break;

      case 'CaseType':
        $extraValues['definition'] = [
          'activityTypes' => [
            [
              'name' => 'Open Case',
              'max_instances' => 1,
            ],
            [
              'name' => 'Follow up',
            ],
          ],
          'activitySets' => [
            [
              'name' => 'standard_timeline',
              'label' => 'Standard Timeline',
              'timeline' => 1,
              'activityTypes' => [
                [
                  'name' => 'Open Case',
                  'status' => 'Completed',
                ],
                [
                  'name' => 'Follow up',
                  'reference_activity' => 'Open Case',
                  'reference_offset' => 3,
                  'reference_select' => 'newest',
                ],
              ],
            ],
          ],
          'timelineActivityTypes' => [
            [
              'name' => 'Open Case',
              'status' => 'Completed',
            ],
            [
              'name' => 'Follow up',
              'reference_activity' => 'Open Case',
              'reference_offset' => 3,
              'reference_select' => 'newest',
            ],
          ],
          'caseRoles' => [
            [
              'name' => 'Parent of',
              'creator' => 1,
              'manager' => 1,
            ],
          ],
        ];
        break;
    }

    $values += $extraValues;
    return $values;
  }

  /**
   * Attempt to get a value using field option, defaults, FKEntity, or a random
   * value based on the data type.
   *
   * @param array $field
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  private function getRequiredValue(array $field) {
    if (!empty($field['options'])) {
      return key($field['options']);
    }
    if (!empty($field['fk_entity'])) {
      return $this->getFkID($field['fk_entity']);
    }
    if (isset($field['default_value'])) {
      return $field['default_value'];
    }
    if ($field['name'] === 'contact_id') {
      return $this->getFkID('Contact');
    }
    if ($field['name'] === 'entity_id') {
      // What could possibly go wrong with this?
      switch ($field['table_name'] ?? NULL) {
        case 'civicrm_financial_item':
          return $this->getFkID(\Civi\Api4\Service\Spec\Provider\FinancialItemCreationSpecProvider::DEFAULT_ENTITY);

        default:
          return $this->getFkID('Contact');
      }
    }

    $randomValue = $this->getRandomValue($field['data_type']);

    if ($randomValue) {
      return $randomValue;
    }

    throw new \CRM_Core_Exception('Could not provide default value');
  }

  protected function deleteTestRecords(): void {
    // Delete all test records in reverse order to prevent fk constraints
    foreach (array_reverse($this->testRecords) as $record) {
      $params = ['checkPermissions' => FALSE, 'where' => $record[1]];

      // Set useTrash param if it exists
      $entityClass = CoreUtil::getApiClass($record[0]);
      $deleteAction = $entityClass::delete();
      if (property_exists($deleteAction, 'useTrash')) {
        $params['useTrash'] = FALSE;
      }

      civicrm_api4($record[0], 'delete', $params);
    }
  }

  /**
   * Get an ID for the appropriate entity.
   *
   * @param string $fkEntity
   *
   * @return int
   *
   * @throws \CRM_Core_Exception
   */
  private function getFkID(string $fkEntity): int {
    $params = ['checkPermissions' => FALSE];
    // Be predictable about what type of contact we select
    if ($fkEntity === 'Contact') {
      $params['where'] = [['contact_type', '=', 'Individual']];
    }
    $entityList = civicrm_api4($fkEntity, 'get', $params);
    // If no existing entities, create one
    if ($entityList->count() < 1) {
      return $this->createTestRecord($fkEntity)['id'];
    }

    return $entityList->last()['id'];
  }

  /**
   * @param $dataType
   *
   * @return int|null|string
   *
   * @noinspection PhpUnhandledExceptionInspection
   * @noinspection PhpDocMissingThrowsInspection
   */
  private function getRandomValue($dataType) {
    switch ($dataType) {
      case 'Boolean':
        return TRUE;

      case 'Integer':
        return random_int(1, 2000);

      case 'String':
        return $this->randomLetters();

      case 'Text':
        return $this->randomLetters(100);

      case 'Money':
        return sprintf('%d.%2d', random_int(0, 2000), random_int(10, 99));

      case 'Date':
        return '20100102';

      case 'Timestamp':
        return 'now';
    }

    return NULL;
  }

}