<?php

namespace Civi\Import\DataSource;

use Civi\Api4\GatewayAccount;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * DataSource for importing Julie's finance spreadsheet (xls, xlsx, csv or
 * ods) - a plain table of DATE / TRANSACTION TYPE / DESCRIPTION /
 * AMOUNT (USD) / BALANCE (USD) rows, some blank, only the ACH and Wire rows
 * of which are real batches to import. BALANCE (USD), a running balance,
 * isn't touched here - it's just left for the saved mapping to ignore.
 */
class FinanceSpreadsheet extends \CRM_Import_DataSource implements DataSourceInterface {
  use DataSourceTrait;

  protected const NUM_ROWS_TO_INSERT = 100;

  private const DATE_HEADER = 'DATE';

  private const TRANSACTION_TYPE_HEADER = 'TRANSACTION TYPE';

  private const SETTLEMENT_GATEWAY_ACCOUNT_HEADER = 'Settlement Gateway Account';

  private const IMPORTABLE_TRANSACTION_TYPES = ['ach', 'wire'];

  private const ENDOWMENT_FILENAME_MARKER = 'END';

  private const ENDOWMENT_SUFFIX = 'endowment';

  private const BATCH_DATE_FORMAT = 'm/d/Y';

  private const BATCH_STATUS_HEADER = 'Batch Status';

  private const BATCH_STATUS_VALUE = 'total_verified';

  private const ITEM_COUNT_HEADER = 'Item Count';

  public function getInfo(): array {
    return [
      'title' => ts('Finance Spreadsheet'),
      'permissions' => ['access CiviContribute', 'edit contributions'],
      // Snippet CRM_Import_Form_DataSourceConfig::preProcess() AJAX-loads
      // when this datasource is selected on the DataSource step.
      'template' => 'CRM/Import/DataSource/FinanceSpreadsheet.tpl',
    ];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(\CRM_Import_Forms $form): void {
    if (\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer')) {
      $this->setUserJobID(\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer'));
    }
    $form->add('hidden', 'hidden_dataSource', self::class);
    // No raw-first-row variant - extractDataRows() always finds a real
    // header row itself, so there's no "first row contains headers" choice.
    $form->add('hidden', 'skipColumnHeader', 1);
    $maxFileSizeMegaBytes = \CRM_Utils_File::getMaxFileSize();
    $maxFileSizeBytes = $maxFileSizeMegaBytes * 1024 * 1024;
    $form->assign('uploadSize', $maxFileSizeMegaBytes);
    $form->add('File', 'uploadFile', ts('Import Data File'), NULL, TRUE);
    $form->setMaxFileSize($maxFileSizeBytes);
    $form->addRule('uploadFile', ts('File size should be less than %1 MBytes (%2 bytes)', [
      1 => $maxFileSizeMegaBytes,
      2 => $maxFileSizeBytes,
    ]), 'maxfilesize', $maxFileSizeBytes);
    $form->registerRule('financeSpreadsheet', 'callback', 'isValidFinanceSpreadsheet', self::class);
    $form->addRule('uploadFile', ts('The file must be of type XLS, XLSX, CSV or ODS.'), 'financeSpreadsheet');
  }

  /**
   * Is the uploaded file a spreadsheet type this datasource can read.
   *
   * @noinspection PhpUnused
   */
  public static function isValidFinanceSpreadsheet(array $file): bool {
    if (empty($file['tmp_name']) || !is_readable($file['tmp_name'])) {
      return FALSE;
    }
    $file_type = IOFactory::identify($file['tmp_name']);
    return in_array($file_type, ['Xls', 'Xlsx', 'Csv', 'Ods'], TRUE);
  }

  public function getSubmittableFields(): array {
    return ['uploadFile', 'skipColumnHeader'];
  }

  /**
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
      throw new \CRM_Core_Exception(ts('Spreadsheet not loaded.') . ' ' . $e->getMessage());
    }
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
   */
  private function uploadToTable(): array {
    $fileName = $this->getSubmittedValue('uploadFile')['name'];
    $file_type = IOFactory::identify($fileName);
    $objReader = IOFactory::createReader($file_type);
    $objReader->setReadDataOnly(TRUE);
    $spreadsheet = $objReader->load($fileName);

    $columnHeaders = NULL;
    $dataRows = [];
    foreach ($spreadsheet->getAllSheets() as $sheet) {
      $sheetRows = $sheet->toArray(NULL, TRUE, TRUE, FALSE);
      [$sheetHeaders, $sheetDataRows] = $this->extractDataRows($sheetRows);
      if ($sheetHeaders !== NULL) {
        $columnHeaders = $sheetHeaders;
        $dataRows = array_merge($dataRows, $sheetDataRows);
      }
    }
    if ($columnHeaders === NULL) {
      throw new \CRM_Core_Exception(ts('Could not find the "%1" header row in the uploaded spreadsheet.', [1 => self::DATE_HEADER]));
    }
    $dataRows = $this->keepOnlyAchAndWireRows($columnHeaders, $dataRows);
    $dataRows = $this->convertDateColumn($columnHeaders, $dataRows);
    [$columnHeaders, $dataRows] = $this->convertTransactionTypeToSettlementGatewayAccountId($columnHeaders, $dataRows, $this->isEndowmentFile($fileName));
    [$columnHeaders, $dataRows] = $this->appendConstantColumns($columnHeaders, $dataRows, [
      self::BATCH_STATUS_HEADER => self::BATCH_STATUS_VALUE,
      // Each row is one ACH/Wire deposit, so it's always exactly one item.
      self::ITEM_COUNT_HEADER => '1',
    ]);
    $columns = $this->getColumnNamesFromHeaders($columnHeaders);

    $tableName = $this->createTempTableFromColumns($columns);
    $numColumns = count($columns);

    $sql = [];
    foreach ($dataRows as $row) {
      $row = array_map(['CRM_Core_DAO', 'escapeString'], $row);
      $sql[] = "('" . implode("', '", $row) . "')";
      if (count($sql) >= self::NUM_ROWS_TO_INSERT) {
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
      'column_headers' => $columnHeaders,
    ];
  }

  /**
   * Walk one sheet's raw rows and pull out the header row and data rows.
   *
   * A plain table (DATE, TRANSACTION TYPE, DESCRIPTION, AMOUNT (USD)),
   * unlike the block-structured Engage report - just skips rows with no
   * non-blank value at all.
   *
   * @param array $sheetRows
   *
   * @return array{0: ?string[], 1: array[]}
   *   [column headers, data rows] - headers is NULL if this sheet has no
   *   header row.
   */
  private function extractDataRows(array $sheetRows): array {
    $headerColumns = NULL;
    $rows = [];
    foreach ($sheetRows as $sheetRow) {
      $row = array_map(fn($value): string => self::trimWhitespace((string) ($value ?? '')), $sheetRow);
      if ($headerColumns === NULL) {
        if (($row[0] ?? '') === self::DATE_HEADER) {
          $headerColumns = array_values($row);
        }
        continue;
      }
      if (!array_filter($row, fn(string $value): bool => $value !== '')) {
        continue;
      }
      $rows[] = array_values($row);
    }
    return [$headerColumns, $rows];
  }

  /**
   * Keep only rows whose TRANSACTION TYPE is ACH or Wire - the rest (fees,
   * interest, other transaction types) aren't batches to import.
   *
   * @param string[] $headers
   * @param array[] $rows
   *
   * @return array[]
   */
  private function keepOnlyAchAndWireRows(array $headers, array $rows): array {
    $typeIndex = array_search(self::TRANSACTION_TYPE_HEADER, $headers, TRUE);
    if ($typeIndex === FALSE) {
      return $rows;
    }
    return array_values(array_filter(
      $rows,
      fn(array $row): bool => in_array(strtolower($row[$typeIndex] ?? ''), self::IMPORTABLE_TRANSACTION_TYPES, TRUE)
    ));
  }

  /**
   * Convert any raw Excel date serial in the "DATE" column to a plain m/d/Y
   * string.
   *
   * A date-looking cell doesn't always carry an actual date number_format,
   * in which case PhpSpreadsheet's toArray($formatData=TRUE) leaves it as a
   * bare serial number (e.g. "45660") instead of formatting it - so this
   * converts explicitly rather than relying on the source file's formatting.
   *
   * @param string[] $headers
   * @param array[] $rows
   *
   * @return array[]
   */
  private function convertDateColumn(array $headers, array $rows): array {
    $dateIndex = array_search(self::DATE_HEADER, $headers, TRUE);
    if ($dateIndex === FALSE) {
      return $rows;
    }
    foreach ($rows as &$row) {
      $value = $row[$dateIndex] ?? '';
      if ($value !== '' && is_numeric($value)) {
        $row[$dateIndex] = ExcelDate::excelToDateTimeObject((float) $value)->format(self::BATCH_DATE_FORMAT);
      }
    }
    return $rows;
  }

  /**
   * Replace the raw "TRANSACTION TYPE" column with a "Settlement Gateway
   * Account" column holding the GatewayAccount.id - "ach"/"wire" are
   * themselves GatewayAccount names, with "endowment" appended for an
   * Endowment file (see isEndowmentFile()).
   *
   * Resolves the id explicitly here rather than mapping the plain name and
   * leaving Civi\WMFHook\Data::batchPre() to derive it at save time.
   *
   * @param string[] $headers
   * @param array[] $rows
   * @param bool $isEndowment
   *
   * @return array{0: string[], 1: array[]}
   * @throws \CRM_Core_Exception
   */
  private function convertTransactionTypeToSettlementGatewayAccountId(array $headers, array $rows, bool $isEndowment): array {
    $typeIndex = array_search(self::TRANSACTION_TYPE_HEADER, $headers, TRUE);
    if ($typeIndex === FALSE) {
      return [$headers, $rows];
    }
    $accountIDs = [];
    $headers[$typeIndex] = self::SETTLEMENT_GATEWAY_ACCOUNT_HEADER;
    foreach ($rows as &$row) {
      $accountName = strtolower($row[$typeIndex] ?? '') . ($isEndowment ? self::ENDOWMENT_SUFFIX : '');
      if (!isset($accountIDs[$accountName])) {
        $accountIDs[$accountName] = GatewayAccount::get(FALSE)
          ->addWhere('name', '=', $accountName)
          ->addSelect('id')
          ->execute()->first()['id'] ?? NULL;
        if (!$accountIDs[$accountName]) {
          throw new \CRM_Core_Exception(ts('No GatewayAccount named "%1" found.', [1 => $accountName]));
        }
      }
      $row[$typeIndex] = (string) $accountIDs[$accountName];
    }
    return [$headers, $rows];
  }

  /**
   * Is this file for the Endowment (rather than the Foundation)?
   *
   * Signalled by the literal substring "END" somewhere in the filename
   * (e.g. "September END 9.01.26 to 9.15.26.ods").
   *
   * @param string $fileName
   *
   * @return bool
   */
  private function isEndowmentFile(string $fileName): bool {
    return str_contains($fileName, self::ENDOWMENT_FILENAME_MARKER);
  }

  /**
   * Append columns with the same constant value for every row.
   *
   * Used for Batch Status (status_id is required, and the importer's
   * "missing required fields" check only looks at what's mapped - a default
   * set later by Civi\WMFHook\Import::applyBatchImportDefaults() doesn't
   * count) and Item Count (always 1 - see the call site).
   *
   * @param string[] $headers
   * @param array[] $rows
   * @param array<string, string> $constants
   *   Header => value to append.
   *
   * @return array{0: string[], 1: array[]}
   */
  private function appendConstantColumns(array $headers, array $rows, array $constants): array {
    foreach (array_keys($constants) as $header) {
      $headers[] = $header;
    }
    foreach ($rows as &$row) {
      foreach ($constants as $value) {
        $row[] = $value;
      }
    }
    return [$headers, $rows];
  }

}
