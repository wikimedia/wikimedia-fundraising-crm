<?php

namespace Civi\Import\DataSource;

use Civi\Api4\GatewayAccount;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * DataSource for importing the vendor "Batch Summary Report" export
 * (xls, xlsx, csv or ods).
 *
 * The report is not a plain table: it is a run of repeating blocks - a batch
 * type/group label line, a timestamp line, a (repeated) column-header row,
 * one or more data rows and a "Total" subtotal row - preceded by a title/
 * date-range preamble, and interspersed with blank separator rows and, on a
 * multi-page report, repeated title/header rows at each page break. Some
 * blocks also contain a bare label row (e.g. "DAF") partway through, before
 * the block's Total row.
 *
 * Named EngageSummary, not "BatchSummaryReport", to avoid colliding with
 * core's own Civi\Import\DataSource\BatchSummaryReport test fixture
 * (core/tests/phpunit/CRM/Batch/Import/Parser/BatchSummaryReport.php).
 */
class EngageSummary extends \CRM_Import_DataSource implements DataSourceInterface {
  use DataSourceTrait;

  protected const NUM_ROWS_TO_INSERT = 100;

  private const HEADER_MARKER_COLUMN = 'A';

  private const HEADER_MARKER_VALUE = 'Batch Type';

  private const GROUP_VALUE_HEADER = 'Group Value';

  private const BATCH_ID_HEADER = 'Batch';

  private const BATCH_DATE_HEADER = 'Batch Date';

  private const BATCH_DATE_FORMAT = 'm/d/Y';

  private const SETTLEMENT_GATEWAY_ACCOUNT_HEADER = 'Settlement Gateway Account';

  private const ENDOWMENT_GATEWAY_ACCOUNT = 'engageendowment';

  private const FOUNDATION_GATEWAY_ACCOUNT = 'engage';

  private const BATCH_STATUS_HEADER = 'Batch Status';

  private const BATCH_STATUS_VALUE = 'total_verified';

  public function getInfo(): array {
    return [
      'title' => ts('Engage Summary'),
      'permissions' => ['access CiviContribute', 'edit contributions'],
      // Snippet CRM_Import_Form_DataSourceConfig::preProcess() AJAX-loads
      // when this datasource is selected on the DataSource step.
      'template' => 'CRM/Import/DataSource/EngageSummary.tpl',
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
    $form->registerRule('engageSummary', 'callback', 'isValidEngageSummary', self::class);
    $form->addRule('uploadFile', ts('The file must be of type XLS, XLSX, CSV or ODS.'), 'engageSummary');
  }

  /**
   * Is the uploaded file a spreadsheet type this datasource can read.
   *
   * @noinspection PhpUnused
   */
  public static function isValidEngageSummary(array $file): bool {
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
    $isEndowment = FALSE;
    foreach ($spreadsheet->getAllSheets() as $sheet) {
      $sheetRows = $sheet->toArray(NULL, TRUE, TRUE, TRUE);
      [$sheetHeaders, $sheetDataRows, $sheetIsEndowment] = $this->extractDataRows($sheetRows);
      if ($sheetHeaders !== NULL) {
        $columnHeaders = $sheetHeaders;
        $dataRows = array_merge($dataRows, $sheetDataRows);
      }
      $isEndowment = $isEndowment || $sheetIsEndowment;
    }
    if ($columnHeaders === NULL) {
      throw new \CRM_Core_Exception(ts('Could not find the "%1" header row in the uploaded report.', [1 => self::HEADER_MARKER_VALUE]));
    }
    $dataRows = $this->skipRowsWithoutBatchId($columnHeaders, $dataRows);
    $dataRows = $this->convertBatchDateColumn($columnHeaders, $dataRows);
    [$columnHeaders, $dataRows] = $this->convertGroupValueToSettlementGatewayAccountId($columnHeaders, $dataRows, $isEndowment);
    [$columnHeaders, $dataRows] = $this->appendBatchStatusColumn($columnHeaders, $dataRows);
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
   * Walk one sheet's raw rows and pull out just the real batch data rows.
   *
   * Rows are read by column letter, not position: the report's header only
   * occupies alternating columns (A, B, C, E, G, I, K, L, with merged-cell
   * gaps at D, F, H, J), and a "Total" row leaves A/B/C blank entirely,
   * which would otherwise misalign a naively-reindexed row.
   *
   * Skips everything before the header row; repeated header rows (one per
   * block, and at page breaks); "Total" subtotal rows; and rows with at
   * most one non-blank value (separator rows, group-label/timestamp lines,
   * bare labels like "DAF", repeated title/date-range lines).
   *
   * Also checks the pre-header preamble for the word "Endowment" (e.g. a
   * "- All Endowment" title line) - the real Foundation/Endowment signal
   * for the whole file, since the per-row Group Value column doesn't vary.
   *
   * @param array $sheetRows
   *
   * @return array{0: ?string[], 1: array[], 2: bool}
   *   [column headers, data rows, is this an Endowment report] - headers is
   *   NULL if this sheet has no header row.
   */
  private function extractDataRows(array $sheetRows): array {
    $headerColumns = NULL;
    $rows = [];
    $isEndowment = FALSE;
    foreach ($sheetRows as $sheetRow) {
      $row = array_map(fn($value): string => self::trimWhitespace((string) ($value ?? '')), $sheetRow);
      if ($headerColumns === NULL) {
        if (($row[self::HEADER_MARKER_COLUMN] ?? '') === self::HEADER_MARKER_VALUE) {
          $headerColumns = array_filter($row, fn(string $value): bool => $value !== '');
        }
        elseif (!$isEndowment && stripos(implode(' ', $row), 'Endowment') !== FALSE) {
          $isEndowment = TRUE;
        }
        continue;
      }
      $dataRow = [];
      foreach ($headerColumns as $column => $label) {
        $dataRow[] = $row[$column] ?? '';
      }
      $nonBlankValues = array_values(array_filter($dataRow, fn(string $value): bool => $value !== ''));
      if ($dataRow === array_values($headerColumns) || count($nonBlankValues) <= 1) {
        continue;
      }
      if ($nonBlankValues[0] === 'Total') {
        continue;
      }
      $rows[] = $dataRow;
    }
    return [$headerColumns === NULL ? NULL : array_values($headerColumns), $rows, $isEndowment];
  }

  /**
   * Convert any raw Excel date serial in the "Batch Date" column to a plain
   * m/d/Y string.
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
  private function convertBatchDateColumn(array $headers, array $rows): array {
    $dateIndex = array_search(self::BATCH_DATE_HEADER, $headers, TRUE);
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
   * Replace the raw "Group Value" column with a "Settlement Gateway Account"
   * column holding the GatewayAccount.id, the same for every row (a single
   * upload is either a Foundation or an Endowment report, never a mix - see
   * extractDataRows()).
   *
   * Resolves the id explicitly here rather than mapping the plain
   * "engage"/"engageendowment" name and leaving Civi\WMFHook\Data::batchPre()
   * to derive it at save time.
   *
   * @param string[] $headers
   * @param array[] $rows
   * @param bool $isEndowment
   *
   * @return array{0: string[], 1: array[]}
   * @throws \CRM_Core_Exception
   */
  private function convertGroupValueToSettlementGatewayAccountId(array $headers, array $rows, bool $isEndowment): array {
    $groupValueIndex = array_search(self::GROUP_VALUE_HEADER, $headers, TRUE);
    if ($groupValueIndex === FALSE) {
      return [$headers, $rows];
    }
    $accountName = $isEndowment ? self::ENDOWMENT_GATEWAY_ACCOUNT : self::FOUNDATION_GATEWAY_ACCOUNT;
    $accountID = GatewayAccount::get(FALSE)
      ->addWhere('name', '=', $accountName)
      ->addSelect('id')
      ->execute()->first()['id'] ?? NULL;
    if (!$accountID) {
      throw new \CRM_Core_Exception(ts('No GatewayAccount named "%1" found.', [1 => $accountName]));
    }
    $headers[$groupValueIndex] = self::SETTLEMENT_GATEWAY_ACCOUNT_HEADER;
    foreach ($rows as &$row) {
      $row[$groupValueIndex] = (string) $accountID;
    }
    return [$headers, $rows];
  }

  /**
   * Drop rows with no batch reference (the "Batch" column) - seen on some
   * Credit Card lines in the report - since there's nothing to key the
   * batch off.
   *
   * @param string[] $headers
   * @param array[] $rows
   *
   * @return array[]
   */
  private function skipRowsWithoutBatchId(array $headers, array $rows): array {
    $batchIdIndex = array_search(self::BATCH_ID_HEADER, $headers, TRUE);
    if ($batchIdIndex === FALSE) {
      return $rows;
    }
    return array_values(array_filter($rows, fn(array $row): bool => ($row[$batchIdIndex] ?? '') !== ''));
  }

  /**
   * Add a "Batch Status" column, constant "total_verified" for every row.
   *
   * status_id is required, and the importer's "missing required fields"
   * check only looks at what's mapped - a default set later by
   * Civi\WMFHook\Import::applyBatchImportDefaults() doesn't count.
   *
   * @param string[] $headers
   * @param array[] $rows
   *
   * @return array{0: string[], 1: array[]}
   */
  private function appendBatchStatusColumn(array $headers, array $rows): array {
    $headers[] = self::BATCH_STATUS_HEADER;
    foreach ($rows as &$row) {
      $row[] = self::BATCH_STATUS_VALUE;
    }
    return [$headers, $rows];
  }

}
