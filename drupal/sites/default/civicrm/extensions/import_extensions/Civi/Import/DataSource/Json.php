<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Import\DataSource;

use GuzzleHttp\Client;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use CRM_ImportExtensions_ExtensionUtil as E;

/**
 * Objects that implement the DataSource interface can be used in CiviCRM imports.
 */
class Json extends \CRM_Import_DataSource {

  use DataSourceTrait;

  /**
   * Provides information about the data source.
   *
   * @return array
   *   collection of info about this data source
   */
  public function getInfo(): array {
    return [
      'title' => ts('Json feed'),
      'template' => 'CRM/Import/DataSource/Json.tpl',
    ];
  }

  /**
   * Get array array of field names that may be submitted for this data source.
   *
   * The quick form for the datasource is added by ajax - meaning that QuickForm
   * does not see them as part of the form. However, any fields listed in this array
   * will be taken from the `$_POST` and stored to the UserJob under the DataSource key.
   *
   * @return array
   */
  public function getSubmittableFields(): array {
    return ['url', 'header'];
  }

  /**
   * This is function is called by the form object to get the DataSource's form
   * snippet.
   *
   * It should add all fields necessary to get the data
   * uploaded to the temporary table in the DB.
   *
   * @param \CRM_Contact_Import_Form_DataSource|\CRM_Import_Form_DataSourceConfig $form
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(\CRM_Import_Forms $form): void {
    if (\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer')) {
      $this->setUserJobID(\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer'));
    }
    $form->add('hidden', 'hidden_dataSource', 'CRM_Import_DataSource_Json');
    $form->addElement('textarea', 'url', E::ts('URL to access json'), ['rows' => 1, 'cols' => 60]);
    $form->addElement('textarea', 'header', E::ts('Header to add to request'), ['rows' => 3, 'cols' => 60]);

    $form->setDataSourceDefaults($this->getDefaultValues());
  }

  /**
   * Get default values for excel dataSource fields.
   *
   * These defaults are mostly for testing use & it's not sure how we will
   * handle in future.
   *
   * @return array
   */
  public function getDefaultValues(): array {
    return [
      'url' => \CRM_Utils_Constant::value('json_url'),
      'header' => \CRM_Utils_Constant::value('json_header'),
    ];
  }

  /**
   * Initialize the datasource, based on the submitted values stored in the user job.
   *
   * Generally this will include transferring the data to a database table.
   *
   * @throws \CRM_Core_Exception
   */
  public function initialize(): void {
    try {
      $result = $this->uploadToTable();

      $this->updateUserJobDataSource([
        'table_name' => $result['import_table_name'],
        'column_headers' => $result['column_headers'],
        'number_of_columns' => $result['number_of_columns'],
      ]);
    }
    catch (ReaderException $e) {
      throw new \CRM_Core_Exception(ts('Spreadsheet not loaded.') . '' . $e->getMessage());
    }
  }

  /**
   * @return \GuzzleHttp\Client
   */
  public function getGuzzleClient(): Client {
    return $this->guzzleClient ?? new Client([
      'base_uri' => $this->getSubmittedValue('url'),
      'headers' => [
        $this->getSubmittedValue('header'),
      ],
      'timeout' => \Civi::settings()->get('http_timeout'),
      'debug' => TRUE,
     ]);
  }

  /**
   * Download the remote json.
   *
   * @return array|false
   *   Whether the download was successful.
   */
  protected function fetch(): false|array {
    $client = $this->getGuzzleClient();
    $response = $client->request('GET', '', [
      'headers' => $this->getHeaders(),
      'timeout' => \Civi::settings()->get('http_timeout'),
    ]);
    if ($response->getStatusCode() === 200) {
      return \GuzzleHttp\json_decode($response->getBody(), TRUE);
    }
    return FALSE;
  }

  /**
   * Get the headers. Note we just have limited support at the moment for a single
   * header in the format key:value
   */
  protected function getHeaders(): array {
    $values = explode(':', $this->getSubmittedValue('header'));
    return [$values[0] => $values[1]];
  }

  /**
   * @param $columns
   * @param $dataRows
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   */
  protected function uploadToTable(): array {
    $dataRows = $this->fetch();
    if ($dataRows === FALSE) {
      throw new \CRM_Core_Exception('nada');
    }
    $firstRow = reset($dataRows);
    $columns = array_keys($firstRow);
    $tableName = $this->createTempTableFromColumns($columns);
    $numColumns = count($columns);
    // Re-key data using the headers
    $sql = [];
    foreach ($dataRows as $row) {
      $row = $this->cleanValues($row);
      $sql[] = "('" . implode("', '", $row) . "')";

      if (count($sql) >= 100) {
        \CRM_Core_DAO::executeQuery("INSERT IGNORE INTO $tableName VALUES " . implode(', ', $sql));
        $sql = [];
      }
    }

    if (!empty($sql)) {
      \CRM_Core_DAO::executeQuery("INSERT IGNORE INTO $tableName VALUES " . implode(', ', $sql));
    }
    $this->addTrackingFieldsToTable($tableName);
    return [
      'import_table_name' => $tableName,
      'number_of_columns' => $numColumns,
      'column_headers' => array_keys($columns),
    ];
  }

  /**
   * Clean up any non-breaking spaces & escape for mysql insertion.
   *
   * @param array $row
   *
   * @return array
   */
  public function cleanValues(array $row): array {
    foreach ($row as $key => $value) {
      if (is_string($value)) {
        // CRM-17859 Trim non-breaking spaces from columns.
        $row[$key] = \CRM_Core_DAO::escapeString(self::trimNonBreakingSpaces($value));
      }
      elseif (!is_numeric($value)) {
        $row[$key] = '';
      }
    }
    return $row;
  }

}
