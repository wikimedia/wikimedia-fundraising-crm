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

use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use League\Csv\Reader;
use CRM_ImportExtensions_ExtensionUtil as E;
use League\Csv\Statement;

/**
 * Objects that implement the DataSource interface can be used in CiviCRM
 * imports.
 *
 * @property string $importTableName
 * @property array $sqlFieldNames
 */
class UploadedFile extends \CRM_Import_DataSource {

  use DataSourceTrait;

  /**
   * @var \League\Csv\Reader
   */
  private Reader $reader;

  private string $importTableName;

  private array $sqlFieldNames;

  /**
   * Provides information about the data source.
   *
   * @return array
   *   collection of info about this data source
   */
  public function getInfo(): array {
    return [
      'title' => ts('Uploaded file'),
      'template' => 'CRM/Import/DataSource/UploadedFile.tpl',
    ];
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
    $form->add('hidden', 'hidden_dataSource', 'CRM_Import_DataSource_UploadedFile');
    $form->addElement('checkbox', 'skipColumnHeader', ts('First row contains column headers'));
    $form->add('text', 'number_of_rows_to_validate', E::ts('Number of rows to upload & validate initially'));
    $fullPathFiles = \CRM_Utils_File::findFiles($this->getResolvedFilePath(), '*.csv');
    foreach ($fullPathFiles as $file) {
      $fileName = str_replace($this->getResolvedFilePath() . '/', '', $file);
      $availableFiles[$fileName] = $fileName;
    }
    $form->assign('upload_message', $this->hasConfiguredFilePath() ? '' : E::ts(
      'Your system administrator has not defined an upload location. The file/s available are sample data only'
    ));
    if ($fullPathFiles) {
      $form->add('select', 'file_name', E::ts('Select File'), $availableFiles, TRUE, ['class' => 'crm-select2 huge']);
    }
    else {
      $form->assign('upload_message', E::ts('There are no uploaded files available'));
    }
    $form->setDataSourceDefaults($this->getDefaultValues());
  }

  /**
   * Get default values for excel dataSource fields.
   *
   * @return array
   */
  public function getDefaultValues(): array {
    return ['skipColumnHeader' => 1, 'number_of_rows_to_validate' => 10];
  }

  /**
   * Initialize the datasource, based on the submitted values stored in the
   * user job.
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
        'number_of_rows' => $result['number_of_rows'],
      ]);
    }
    catch (ReaderException $e) {
      throw new \CRM_Core_Exception(ts('Spreadsheet not loaded.') . '' . $e->getMessage());
    }
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
   */
  private function uploadToTable(): array {
    $this->getReader();
    $this->importTableName = $this->createTempTableFromColumns($this->getSQLFieldNames());
    $this->addTrackingFieldsToTable($this->importTableName);
    $numColumns = count($this->getColumnNames());
    $rowsToInsert = $this->getNumberOfRowsToInitiateWith();

    foreach ($this->reader->getRecords() as $row) {
      $rowsToInsert--;
      if ($rowsToInsert < 0) {
        break;
      }
      $this->insertRowIntoImportTable($row);
    }

    return [
      'import_table_name' => $this->importTableName,
      'number_of_columns' => $numColumns,
      'column_headers' => $this->getColumnTitles(),
      'number_of_rows' => $this->reader->count(),
    ];
  }

  private function getColumnNames(): array {
    if ($this->getSubmittedValue('skipColumnHeader')) {
      $header = $this->getReader()->getHeader();
      return array_values($header);
    }

    $row = $this->getReader()->fetchOne();
    $columnsHeaders = [];
    foreach (array_keys($row) as $index) {
      $columnsHeaders[] = ['column_' . $index];
    }
    return $columnsHeaders;
  }

  private function getColumnTitles(): array {
    if ($this->getSubmittedValue('skipColumnHeader')) {
      $this->reader->setHeaderOffset(0);
      $header = $this->reader->getHeader();
      return array_values($header);
    }

    $row = $this->reader->fetchOne();
    $columnsHeaders = [];
    foreach (array_keys($row) as $index) {
      $columnsHeaders[] = ['column_' . $index];
    }
    return $columnsHeaders;
  }

  /**
   * Get array array of field names that may be submitted for this data source.
   *
   * The quick form for the datasource is added by ajax - meaning that
   * QuickForm
   * does not see them as part of the form. However, any fields listed in this
   * array will be taken from the `$_POST` and stored to the UserJob under the
   * DataSource key.
   *
   * @return array
   */
  public function getSubmittableFields(): array {
    return ['file_name', 'skipColumnHeader', 'number_of_rows_to_validate'];
  }

  public function getRow(): ?array {
    $row = parent::getRow();
    if (empty($row) && $this->getStatuses() === ['new']) {
      // here we load from the file if there are still rows to load.
      $remainingRowsToProcess = $this->getRowCount(['new']);
      if ($remainingRowsToProcess > 0) {
        // We fetch a row into the table - using the row count in the import table
        // as the index (ie get the next row not in the table.
        $rowsAlreadyUploaded = $offset = parent::getRowCount();
        $initialUpload = $this->getNumberOfRowsToInitiateWith();
        $numberOfRowsToLoad = $this->getLimit();
        if ($rowsAlreadyUploaded === $initialUpload) {
          // We are on the first iteration so we want to deduct our initial load from the number
          // required to complete the set.
          $numberOfRowsToLoad -= $initialUpload;
        }
        if ($remainingRowsToProcess < $numberOfRowsToLoad) {
          $numberOfRowsToLoad = $remainingRowsToProcess;
        }
        // When we set the limit and offset the query result set is
        // flushed, causing it to re-load from the database when we call parent::getRow().
        // We set the offset to the first row we are uploading in this csv-to-table
        // load and then the limit to the remaining number of rows to load
        // for this iteration, so the next query will load the rows we are just adding to the
        // table.

        $this->setOffset($offset);
        $this->setLimit($numberOfRowsToLoad);

        $stmt = Statement::create()
          ->offset($offset)
          ->limit($numberOfRowsToLoad);

        // Pull rows into the database, using the offset to specify the starting line in the file.
        $rows = $stmt->process($this->getReader());
        foreach ($rows as $row) {
          $this->insertRowIntoImportTable($row);
        }
        return parent::getRow();
      }
    }
    return $row;
  }

  /**
   * Get row count.
   *
   * The array has all values.
   *
   * @param array $statuses
   *
   * @return int
   *
   * @throws \CRM_Core_Exception
   */
  public function getRowCount(array $statuses = []): int {
    if (empty($statuses)) {
      return $this->getDataSourceMetadata()['number_of_rows'];
    }
    if ($statuses === ['new']) {
      $numberOfRowsUploadedToTable = parent::getRowCount();
      $numberOfRowsNotUploaded = $this->getDataSourceMetadata()['number_of_rows'] - $numberOfRowsUploadedToTable;
      return $numberOfRowsNotUploaded + parent::getRowCount($statuses);
    }
    return parent::getRowCount($statuses);
  }

  /**
   * Get the configured file path.
   *
   * The only way to configure a file path at the moment is to add a define
   * to civicrm.settings.php - eg
   * `define('IMPORT_EXTENSIONS_UPLOAD_FOLDER', /var/www/abc/xyz');
   * The expectation is that this is a file path that the sysadmin sets up
   * for a user (or process) to ftp files to.
   *
   * Note that this function currently respects open_base_dir restrictions.
   *
   * @return string|null
   *
   * @noinspection PhpUnhandledExceptionInspection
   */
  protected function getConfiguredFilePath(): ?string {
    $configuredFilePath = \CRM_Utils_Constant::value('IMPORT_EXTENSIONS_UPLOAD_FOLDER');
    // Only return the directory if it exists...
    if (!\CRM_Utils_File::isDir($configuredFilePath)) {
      \Civi::log('import')->warning('Configured file path {path} is not valid', ['path' => $configuredFilePath]);
      return NULL;
    }
    return $configuredFilePath;
  }

  /**
   * Get the resolved file path - either the configured one or fall back to the
   * sample data one.
   *
   * @return string
   */
  protected function getResolvedFilePath(): string {
    $sampleDataFilePath = E::path() . DIRECTORY_SEPARATOR . 'SampleFiles';
    return $this->getConfiguredFilePath() ?: $sampleDataFilePath;
  }

  /**
   * Has a file path been configured (to a real directory).
   *
   * @return bool
   */
  protected function hasConfiguredFilePath(): bool {
    return (bool) $this->getConfiguredFilePath();
  }

  /**
   * @param $row
   * @param $rowsToInsert
   * @param string $tableName
   *
   * @return void
   * @throws \Civi\Core\Exception\DBQueryException
   */
  private function insertRowIntoImportTable($row): void {
    $row = array_map('strval', $row);
    $row = array_map([__CLASS__, 'trimNonBreakingSpaces'], $row);
    $row = array_map(['CRM_Core_DAO', 'escapeString'], $row);
    $sql = ["('" . implode("', '", $row) . "')"];
    \CRM_Core_DAO::executeQuery("INSERT IGNORE INTO " . $this->getTableName()
      . "(" . implode(', ', $this->getSQLFieldNames()) . ")"
      . " VALUES " . implode(', ', $sql));
  }

  /**
   * Get the table name for the import job.
   *
   * @return string|null
   *
   * @throws \CRM_Core_Exception
   */
  protected function getTableName(): ?string {
    if (!isset($this->importTableName)) {
      $this->importTableName = parent::getTableName();
    }
    return $this->importTableName;
  }

  /**
   *
   * @throws \CRM_Core_Exception
   * @throws \League\Csv\Exception
   */
  public function getReader(): Reader {
    if (!isset($this->reader)) {
      $filePath = $this->getResolvedFilePath() . DIRECTORY_SEPARATOR . $this->getSubmittedValue('file_name');
      $this->reader = Reader::createFromPath($filePath);
      // Remove the header
      if ($this->getSubmittedValue('skipColumnHeader')) {
        $this->reader->setHeaderOffset(0);
      }
    }
    return $this->reader;
  }

  /**
   * @return array
   */
  protected function getSQLFieldNames(): array {
    if (!isset($this->sqlFieldNames)) {
      $this->sqlFieldNames = $this->getColumnNamesFromHeaders($this->getColumnNames());
    }
    return $this->sqlFieldNames;
  }

  /**
   * @return int
   */
  public function getNumberOfRowsToInitiateWith(): int {
    // We only load the first 10 rows at the start - the rest are lazy-loaded during the actual import.
    return $this->getSubmittedValue('number_of_rows_to_validate') ?: 10;
  }

}
