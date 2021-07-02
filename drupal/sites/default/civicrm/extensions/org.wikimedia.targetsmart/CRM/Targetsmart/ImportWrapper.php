<?php
use CRM_Targetsmart_ExtensionUtil as E;
use Civi\Api4\Mapping;
use Civi\Api4\MappingField;
use Civi\Api4\LocationType;

/**
 * Collection of upgrade steps.
 */
class CRM_Targetsmart_ImportWrapper {
  use CRM_Contact_Import_MetadataTrait;

  /**
   * Name of mapping to use.
   *
   * @var string
   */
  protected $mappingName;

  /**
   * Name of group to add the contacts to.
   *
   * @var string
   */
  protected $groupName;

  /**
   * Number of columns to fill with 'null' to blank out data at the end.
   *
   * This is useful if importing an address & we want to make sure
   * we clear out 'supplemental address 1' because it's not provided
   * and the old data would be obsolete with the new street_address.
   *
   * @var int
   */
  protected $nullColumns = 0;

  /**
   * @return string
   */
  public function getMappingName(): string {
    return $this->mappingName;
  }

  /**
   * @param string $mappingName
   */
  public function setMappingName(string $mappingName) {
    $this->mappingName = $mappingName;
  }

  /**
   * @return int
   */
  public function getNullColumns(): int {
    return $this->nullColumns;
  }

  /**
   * @param int $nullColumns
   */
  public function setNullColumns(int $nullColumns) {
    $this->nullColumns = $nullColumns;
  }


  /**
   * @return string
   */
  public function getGroupName(): string {
    return $this->groupName;
  }

  /**
   * @param string $groupName
   */
  public function setGroupName(string $groupName) {
    $this->groupName = $groupName;
  }

  /**
   * CSV headers.
   *
   * @var array
   */
  protected $headers = [];

  /**
   * @return array
   */
  public function getHeaders(): array {
    return $this->headers;
  }

  /**
   * @param array $headers
   */
  public function setHeaders(array $headers) {
    $this->headers = $headers;
  }

  /**
   * @return array
   */
  public function getAdditionalValues(): array {
    return $this->additionalValues;
  }

  /**
   * @param array $additionalValues
   */
  public function setAdditionalValues(array $additionalValues) {
    $this->additionalValues = $additionalValues;
  }

  protected $additionalValues = [];

  /**
   * Import row.
   *
   * @param array $values
   *   Row values.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function importRow($values) {
    // Really? A session var? 1 = Ymd - which is us.
    CRM_Core_Session::singleton()->set('dateTypes', 1);
    // This is coming from the targetsmart column name & seems to vary over time...
    // ref https://collab.wikimedia.org/wiki/Target-smart
    $contactID = $values['Contact ID'] ?? $values['contact_id'];

    $importObj = $this->getImporter('Individual');

    try {
      $this->importSingle($importObj, $values);
      if ($this->getGroupName()) {
        civicrm_api3('GroupContact', 'create', ['contact_id' => $contactID, 'group_id' => $this->getGroupName()]);
      }
    }
    catch (Exception $e) {
      // The exception is different in unit tests than 'live' so catch a generic Exception & check
      // if it is an org. We could have checked first but we'd check millions for just a
      // few hits.
      if (!$contactID) {
        throw new CRM_Core_Exception($e->getMessage() . 'boo' . print_r($values, TRUE));
      }
      $contactType = (string) civicrm_api3('Contact', 'getvalue', ['id' => $contactID, 'return' => 'contact_type']);
      if ('Individual' !==  $contactType) {
        $importObj = $this->getImporter($contactType);
        $this->importSingle($importObj, $values);
        civicrm_api3('GroupContact', 'create', ['contact_id' => $contactID, 'group_id' => $this->getGroupName()]);
      }
      else {
        throw new CRM_Core_Exception($e->getMessage() . ' blah ' . print_r($values, TRUE));
      }
    }
  }

  /**
   * Get importer object.
   *
   * @param 'Individual'|'Organization'|'Household' $contactType
   *
   * @return \CRM_Contact_Import_Parser_Contact
   * @throws \CiviCRM_API3_Exception
   */
  protected function getImporter($contactType): \CRM_Contact_Import_Parser_Contact {
    $importer = new CRM_Import_ImportProcessor();
    $importer->setMappingID((int) civicrm_api3('Mapping', 'getvalue', ['name' => $this->getMappingName(), 'return' => 'id']));
    $importer->setContactType($contactType);
    $importer->setMetadata($this->getContactImportMetadata());
    return $importer->getImporterObject();
  }

  /**
   * Do the actual import task.
   *
   * @param \CRM_Contact_Import_Parser_Contact $importObj
   * @param array $values
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function importSingle(CRM_Contact_Import_Parser_Contact $importObj, $values) {
    foreach ($values as $index => $value) {
      // We don't need to worry about prospecting data being invalid - this will be old address data - it's low volume
      // & low value so let is go....
      // The pattern I'm seeing is Chinese addresses that say 'Alabama, US' on older contacts.
      // If they had seen US they  would not have been in the export (I've fixed the few I looked up on live
      // above).
      $validUTF8 = mb_check_encoding($value, 'UTF-8');
      if (!$validUTF8) {
        Civi::log()->debug('skipped ' . $index . ' for contact ' . $values['Contact ID'] . ' value is ' . $value);
        $values[$index] = '';
      }
      if ($value === 'NA') {
        $values[$index] = '';
      }
    }

    $values = array_values($values);
    for ($i =0; $i < $this->getNullColumns(); $i++) {
      $values[] = 'null';
    }

    $result = $importObj->import(CRM_Import_Parser::DUPLICATE_UPDATE, $values);
    if ($result !== CRM_Import_Parser::VALID) {
      throw new CRM_Core_Exception('Row failed to import ' . $values[0] . print_r($values, TRUE));
    }
  }

}
