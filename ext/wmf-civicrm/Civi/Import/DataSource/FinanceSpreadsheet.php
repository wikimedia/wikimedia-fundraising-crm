<?php

namespace Civi\Import\DataSource;

use Civi\Api4\GatewayAccount;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * DataSource for importing Finance's bank transaction exports (xls, xlsx or csv).
 *
 * We now get one for the 'main' account and one for the Citibank account (International checks).
 *
 * Each donation row is imported as a batch, with the gateway account
 * worked out from the memo. There will be other rows in the import which are ignored.
 * This is an opt-in approach.
 *
 * The table holds only the batch values calculated from each row
 * (CALCULATED_HEADERS), so the saved mapping fits whatever the
 * spreadsheet's columns are.
 */
class FinanceSpreadsheet extends \CRM_Import_DataSource implements DataSourceInterface {
  use DataSourceTrait;

  protected const NUM_ROWS_TO_INSERT = 100;

  private const REFERENCE_HEADER = 'Batch Reference';

  private const GATEWAY_HEADER = 'Settlement Gateway';

  private const GATEWAY_ACCOUNT_HEADER = 'Settlement Gateway Account';

  private const SETTLEMENT_DATE_HEADER = 'Settlement Date';

  private const TITLE_HEADER = 'Batch Title';

  private const TOTAL_HEADER = 'Total';

  private const CURRENCY_HEADER = 'Currency';

  private const ITEM_COUNT_HEADER = 'Item Count';

  private const STATUS_HEADER = 'Batch Status';

  private const VERIFIED_DATE_HEADER = 'Total Verified Date';

  // The table's columns, in the order the saved mapping expects - see
  // UserJobFinanceSpreadsheet.mgd.php.
  private const CALCULATED_HEADERS = [
    self::REFERENCE_HEADER,
    self::GATEWAY_HEADER,
    self::GATEWAY_ACCOUNT_HEADER,
    self::SETTLEMENT_DATE_HEADER,
    self::TITLE_HEADER,
    self::TOTAL_HEADER,
    self::CURRENCY_HEADER,
    self::ITEM_COUNT_HEADER,
    self::STATUS_HEADER,
    self::VERIFIED_DATE_HEADER,
  ];

  // The signed amount, used over Txn amt (which is unsigned) when present -
  // it is not in the Citibank export. Older exports have 'USD Amount'.
  private const AMOUNT_HEADERS = ['amount $', 'usd amount'];

  // e.g. "Citibank - EUR Public #2 Donation Received from 1/ANNA MOUSE 10/02/2026".
  private const CITIBANK_DONATION_PATTERN = '#Donation Received from\s#i';

  // e.g. "Donation Check # 1982 10/20/2026" or "Donation Check 5 Checks 10/20/2026".
  private const CHECK_PATTERN = '#^Donation Check\s+(?:\#\s*(\S+)|(\d+)\s+Checks)\s+(\d{1,2})/(\d{1,2})/(\d{4})#i';

  private const ENDOWMENT_FILENAME_MARKER = 'END';

  private const ENDOWMENT_SUFFIX = 'endowment';

  private const BATCH_DATE_FORMAT = 'm/d/Y';

  private const BATCH_STATUS = 'total_verified';

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
    // extractDataRows() finds the header row - this makes the map screen show the headers.
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
    $form->addRule('uploadFile', ts('The file must be of type XLS, XLSX or CSV.'), 'financeSpreadsheet');
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
    return in_array($file_type, ['Xls', 'Xlsx', 'Csv'], TRUE);
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
    catch (\Throwable $e) {
      // Broad catch - an unreadable file can throw something other than a
      // ReaderException, which would otherwise surface as a bare
      // wizard-session error.
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
    $objReader = IOFactory::createReader(IOFactory::identify($fileName));
    $objReader->setReadDataOnly(TRUE);
    $spreadsheet = $objReader->load($fileName);

    $foundHeaders = FALSE;
    $dataRows = [];
    foreach ($spreadsheet->getAllSheets() as $sheet) {
      // formatData=FALSE - so dates come back as Excel serials rather than
      // strings in whatever locale the cell carries.
      $sheetRows = $this->extractDataRows($sheet->toArray(NULL, TRUE, FALSE, FALSE));
      if ($sheetRows !== NULL) {
        $foundHeaders = TRUE;
        $dataRows = array_merge($dataRows, $sheetRows);
      }
    }
    if (!$foundHeaders) {
      throw new \CRM_Core_Exception(ts('Could not find a header row with a "Memo/Description" column in the uploaded spreadsheet.'));
    }
    $dataRows = $this->addGatewayAccounts($dataRows, $this->isEndowmentFile($fileName));
    $columns = $this->getColumnNamesFromHeaders(self::CALCULATED_HEADERS);
    $tableName = $this->createTempTableFromColumns($columns);

    $sql = [];
    foreach ($dataRows as $row) {
      $values = [];
      foreach (self::CALCULATED_HEADERS as $header) {
        $values[] = \CRM_Core_DAO::escapeString($row[$header]);
      }
      $sql[] = "('" . implode("', '", $values) . "')";
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
      'number_of_columns' => count($columns),
      'column_headers' => self::CALCULATED_HEADERS,
    ];
  }

  /**
   * Get the batch rows, keyed by header, from one sheet's raw rows.
   *
   * @param array $sheetRows
   *
   * @return array[]|null
   *   NULL if this sheet has no header row.
   */
  private function extractDataRows(array $sheetRows): ?array {
    $columns = NULL;
    $rows = [];
    foreach ($sheetRows as $sheetRow) {
      $row = array_values(array_map(fn($value): string => self::trimWhitespace((string) ($value ?? '')), $sheetRow));
      if ($columns === NULL) {
        $columns = $this->getColumns($row);
        continue;
      }
      $batchRow = $this->getCalculatedValues($row, $columns);
      if ($batchRow !== NULL) {
        $rows[] = $batchRow;
      }
    }
    return $columns === NULL ? NULL : $rows;
  }

  /**
   * Get the column indexes if this is the header row - the one with Memo/Description.
   *
   * @param string[] $row
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   *   If the header row is missing a column we need.
   */
  private function getColumns(array $row): ?array {
    // Older exports have 'Memo / Description'.
    $columns = array_flip(array_map(fn(string $header): string => strtolower(str_replace(' / ', '/', $header)), $row));
    if (!isset($columns['memo/description'])) {
      return NULL;
    }
    $missing = [];
    foreach (['Doc dt.', 'Txn amt', 'Curr'] as $header) {
      if (!isset($columns[strtolower($header)])) {
        $missing[] = $header;
      }
    }
    if ($missing) {
      throw new \CRM_Core_Exception(ts('The spreadsheet is missing the column(s): %1.', [1 => implode(', ', $missing)]));
    }
    return $columns;
  }

  /**
   * Get the calculated values, keyed by header, with the gateway from the memo.
   *
   * The reference & gateway account are added once the gateway is known -
   * see addGatewayAccounts().
   *
   * @param string[] $row
   * @param array $columns
   *
   * @return array<string, string>|null
   *   NULL if the row is not a batch to import.
   */
  private function getCalculatedValues(array $row, array $columns): ?array {
    $title = $row[$columns['memo/description']] ?? '';
    $itemCount = '1';
    if (preg_match(self::CITIBANK_DONATION_PATTERN, $title)) {
      $account = 'citibank-public2';
    }
    elseif (preg_match('/^ACH-/i', $title)) {
      $account = 'ach';
    }
    elseif (preg_match('/^WIRE FROM\b/i', $title)) {
      $account = 'wire';
    }
    elseif (preg_match(self::CHECK_PATTERN, $title, $matches)) {
      // The check number alone is not unique, so title it {number}-{check date}
      // - or just the date where the row covers more than one check.
      [, $checkNumber, $checkCount, $month, $day, $year] = $matches;
      $account = 'bankcheck';
      $date = sprintf('%d%02d%02d', $year, $month, $day);
      $title = $checkNumber !== '' ? $checkNumber . '-' . $date : $date;
      $itemCount = $checkCount !== '' ? $checkCount : '1';
    }
    elseif (preg_match('/^Engage Deposited Check Return\b/i', $title)) {
      $account = 'engage';
    }
    elseif (preg_match('/^CCK\b/i', $title)) {
      $account = 'cck';
    }
    elseif (preg_match('/\bStock Donations\b/i', $title)) {
      $account = 'boa';
    }
    else {
      return NULL;
    }
    $amount = $this->getAmount($row, $columns);
    return [
      self::REFERENCE_HEADER => '',
      self::GATEWAY_HEADER => $account,
      self::GATEWAY_ACCOUNT_HEADER => '',
      self::SETTLEMENT_DATE_HEADER => $this->formatDate($row[$columns['doc dt.']] ?? ''),
      self::TITLE_HEADER => $title,
      self::TOTAL_HEADER => $amount,
      self::CURRENCY_HEADER => $row[$columns['curr']] ?? '',
      self::ITEM_COUNT_HEADER => $itemCount,
      self::STATUS_HEADER => self::BATCH_STATUS,
      // Importing the batch is verifying its total.
      self::VERIFIED_DATE_HEADER => date(self::BATCH_DATE_FORMAT . ' H:i:s'),
    ];
  }

  /**
   * Get the amount - Amount $ if present, else Txn amt.
   *
   * @param string[] $row
   * @param array $columns
   *
   * @return string
   */
  private function getAmount(array $row, array $columns): string {
    $value = fn(string $header): string => isset($columns[$header]) ? str_replace(',', '', $row[$columns[$header]] ?? '') : '';
    foreach (self::AMOUNT_HEADERS as $amountHeader) {
      if ($value($amountHeader) !== '') {
        return $value($amountHeader);
      }
    }
    return $value('txn amt');
  }

  /**
   * Format the date as m/d/Y.
   *
   * xls/xlsx dates are Excel serials (see uploadToTable()) and the Citibank
   * export has 2 digit years, e.g. 10/2/26.
   *
   * @param string $value
   *
   * @return string
   */
  private function formatDate(string $value): string {
    if (is_numeric($value)) {
      return ExcelDate::excelToDateTimeObject((float) $value)->format(self::BATCH_DATE_FORMAT);
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $value, $matches)) {
      return sprintf('%02d/%02d/%d', $matches[1], $matches[2], strlen($matches[3]) === 2 ? 2000 + $matches[3] : $matches[3]);
    }
    return $value;
  }

  /**
   * Add the gateway account - the endowment one for an endowment file - and
   * the batch reference, {gateway account}_{title}_{currency}, which is
   * also the title.
   *
   * @param array[] $rows
   * @param bool $isEndowment
   *
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  private function addGatewayAccounts(array $rows, bool $isEndowment): array {
    $accountIDs = [];
    foreach ($rows as &$row) {
      $accountName = $row[self::GATEWAY_HEADER] . ($isEndowment ? self::ENDOWMENT_SUFFIX : '');
      if (!isset($accountIDs[$accountName])) {
        $accountIDs[$accountName] = GatewayAccount::get(FALSE)
          ->addWhere('name', '=', $accountName)
          ->addSelect('id')
          ->execute()->first()['id'] ?? NULL;
        if (!$accountIDs[$accountName]) {
          throw new \CRM_Core_Exception(ts('No GatewayAccount named "%1" found.', [1 => $accountName]));
        }
      }
      $row[self::GATEWAY_HEADER] = $accountName;
      $row[self::GATEWAY_ACCOUNT_HEADER] = (string) $accountIDs[$accountName];
      $row[self::REFERENCE_HEADER] = $row[self::TITLE_HEADER] = implode('_', [$accountName, $row[self::TITLE_HEADER], $row[self::CURRENCY_HEADER]]);
    }
    return $rows;
  }

  /**
   * Is this file for the Endowment (rather than the Foundation)?
   *
   * Signalled by "END" (case-sensitive) or "endowment" in the filename,
   * e.g. "September END 9.01.26 to 9.15.26.xlsx".
   *
   * @param string $fileName
   *
   * @return bool
   */
  private function isEndowmentFile(string $fileName): bool {
    return str_contains($fileName, self::ENDOWMENT_FILENAME_MARKER)
      || str_contains(strtolower($fileName), 'endowment');
  }

}
