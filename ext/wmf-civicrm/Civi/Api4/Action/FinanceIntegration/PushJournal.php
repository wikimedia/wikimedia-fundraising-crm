<?php

namespace Civi\Api4\Action\FinanceIntegration;

use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Brick\Math\RoundingMode;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use League\Csv\Exception;
use League\Csv\Reader;

/**
 * @method $this setJournalFile(string $fileName)
 * @method $this setIsDryRun(bool $isDryRun)
 */
class PushJournal extends AbstractAction {

  /**
   * @var null|Client
   */
  protected ?Client $tokenClient = NULL;

  protected ?Client $apiClient = NULL;

  protected $tokenURL = 'https://api.intacct.com/ia/api/v1/oauth2/token';

  private ?string $accessToken = NULL;
  private ?int $tokenExpiry = NULL;
  private array $batches = [];
  /**
   * Is this a dry run (if so do not push to the api).
   *
   * @var bool
   */
  protected bool $isDryRun = FALSE;

  /**
   * File of journals to push.
   *
   * @required
   *
   * @var string $journalFile
   */
  protected string $journalFile;
  private array $log = [];

  public function _run(Result $result) {
    if (!$this->tokenClient) {
      $this->tokenClient = new Client([
        'base_uri' => $this->tokenURL,
      ]);
    }

    try {
      $this->buildJournalEntries();
      foreach ($this->batches as $batchName => $batch) {
        $record = [
          'name' => $batchName,
        ];
        if (!$this->isDryRun && !empty($batch['journal_entry'])) {
          $response = $this->getApiClient()->post('objects/general-ledger/journal-entry', [
            'json' => $batch['journal_entry'],
          ]);
          $remoteResponse = json_decode((string) $response->getBody(), TRUE);
          $record['remote_journal_id'] = $remoteResponse['ia::result']['id'];
          // Do an extra journal fetch to populate the Web Url.
          $this->getExistingJournal($batchName);
          $this->validateExistingBatch($record['remote_journal_id'], $batchName);
        }
        $record += $this->batches[$batchName];
        $record['log'] = $this->log[$batchName] ?? [];
        unset($record['rows'], $record['journal_entry']);
        $result[] = $record;
      }
    }
    catch (GuzzleException $e) {
      $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
      $body   = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();

      throw new \CRM_Core_Exception(
        'Intacct journal POST failed' . ($status ? " (HTTP $status)" : '') . ': ' . $body
      );
    }
  }

  /**
   * @return string
   * @throws GuzzleException
   * @throws \CRM_Core_Exception
   */
  public function getBearerToken(): string {
    if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $credentials = \CRM_Utils_Constant::value('FINANCE_OAUTH');
    if (!$credentials) {
      throw new \CRM_Core_Exception('No FINANCE_OAUTH credentials provided');
    }

    $payload = [
      'grant_type' => 'client_credentials',
      'client_id' => $credentials['client_id'],
      'client_secret' => $credentials['secret'],
      'username' => $credentials['username'] . '@' . $credentials['company_id'],
    ];

    try {
      $this->tokenClient = new Client();
      $response = $this->tokenClient->post($this->tokenURL, [
        'form_params' => $payload,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);

      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \CRM_Core_Exception('Token response was not valid JSON: ' . json_last_error_msg());
      }

      $this->accessToken = (string) ($data['access_token'] ?? '');
      $expiresIn = (int) ($data['expires_in'] ?? 0);

      if (!$this->accessToken || !$expiresIn) {
        throw new \CRM_Core_Exception('Token response missing access_token and/or expires_in');
      }

      // Refresh 60s early.
      $this->tokenExpiry = time() + ($expiresIn - 60);
      $this->apiClient = NULL;
    }
    catch (RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
      $errorBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();

      throw new \CRM_Core_Exception(
        'Token request failed' . ($status ? " (HTTP $status)" : '') . ': ' . $errorBody
      );
    }

    return $this->accessToken;
  }

  /**
   * @return Client
   * @throws GuzzleException
   * @throws \CRM_Core_Exception
   */
  public function getApiClient(): Client {
    $accessToken = $this->getBearerToken();

    if (!isset($this->apiClient)) {
      $this->apiClient = new Client([
        'base_uri' => 'https://api.intacct.com/ia/api/v1/',
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
      ]);
    }

    return $this->apiClient;
  }

  /**
   * Validate + build payloads; skip those already present in Intacct with matching totals.
   *
   * @return array
   * @throws GuzzleException
   * @throws MoneyMismatchException
   * @throws UnknownCurrencyException
   * @throws \CRM_Core_Exception
   */
  private function buildJournalEntries(): void {
    $csvPath = $this->journalFile;

    if (!file_exists($csvPath)) {
      throw new \CRM_Core_Exception("CSV not found: $csvPath");
    }

    try {
      $this->throwIfNotInAllowedFolder($csvPath);
      $csv = Reader::createFromPath($csvPath, 'r');
      $csv->setHeaderOffset(0);
    }
    catch (Exception $e) {
      throw new \CRM_Core_Exception("Unable to open CSV: $csvPath");
    }

    $this->batches = [];
    foreach ($csv->getRecords() as $row) {
      if (!empty($row['DONOTIMPORT'])) {
        continue;
      }
      $this->batches[$row['DOCUMENT']]['rows'][] = $row;
    }

    foreach ($this->batches as $batchName => $batch) {
      $rows = $batch['rows'];
      $first = $rows[0];

      $currency = (string) ($first['CURRENCY'] ?? '');
      $this->batches[$batchName]['currency'] = (string) ($first['CURRENCY'] ?? '');

      // Validate batch is valid (ie debits must match credits).
      $this->batches[$batchName]['csvTotals'] = $this->validateCsvBatch($batchName, $rows, $currency);

      // If it already exists in Intacct, then check the totals match so it can be closed.
      $exists = $this->getExistingJournal($batchName);
      if ($exists) {
        $existingId = (string) ($exists['ia::result'][0]['id'] ?? '');
        $this->validateExistingBatch($existingId, $batchName);
        continue;
      }

      $postingDate = \DateTime::createFromFormat('m/d/Y', $first['DATE'])
        ?: \DateTime::createFromFormat('n/j/Y', $first['DATE']);

      if (!$postingDate) {
        throw new \CRM_Core_Exception('Bad DATE format: ' . (string) ($first['DATE'] ?? '') . ' for batch ' . $batchName);
      }

      $entry = [
        'glJournal' => ['id' => $first['JOURNAL']],
        'postingDate' => $postingDate->format('Y-m-d'),
        'description' => $first['DESCRIPTION'] ?: null,
        'state'       => 'draft',
        'referenceNumber' => $first['DOCUMENT'],
        'lines' => [],
      ];

      foreach ($rows as $row) {
        $debitAmount  = (string) ($row['DEBIT'] ?? '0');
        $creditAmount = (string) ($row['CREDIT'] ?? '0');

        $debitMoney  = Money::of($debitAmount, $currency, null, RoundingMode::HALF_UP);
        $creditMoney = Money::of($creditAmount, $currency, null, RoundingMode::HALF_UP);

        $transactionType = !$debitMoney->isZero() ? 'debit' : 'credit';
        $transactionMoney = !$debitMoney->isZero() ? $debitMoney : $creditMoney;

        $line = [
          'txnType'   => $transactionType,
          'txnAmount' => (string) $transactionMoney->getAmount(),
          'glAccount' => ['id' => (string) $row['ACCT_NO']],
          'documentId'=> $row['DOCUMENT'] ?: null,
          'description' => $row['MEMO'] ?: ($row['DESCRIPTION'] ?: null),
          'dimensions' => array_filter([
            'location'   => !empty($row['LOCATION_ID']) ? ['id' => $row['LOCATION_ID']] : null,
            'department' => !empty($row['DEPT_ID'])     ? ['id' => $row['DEPT_ID']]     : null,
            'vendor'     => !empty($row['GLENTRY_VENDORID']) ? ['id' => $row['GLENTRY_VENDORID']] : null,
            'nsp::funding' => ['key' => (string) ($row['GLDIMFUNDING'] === 'Unrestricted' ? 10004 : 10005)],
          ]),
        ];

        $entry['lines'][] = $line;
      }

      // Defensive: Intacct requires >= 2 lines
      if (count($entry['lines']) < 2) {
        throw new \CRM_Core_Exception("Batch {$batchName} has fewer than 2 lines - cannot create a journal entry");
      }

      $this->batches[$batchName]['journal_entry'] = $entry;
    }
  }

  /**
   * Validate a CSV batch before attempting to POST.
   *
   * Rules:
   * - At least 2 lines
   * - Exactly one of DEBIT or CREDIT populated per row
   * - Batch is balanced (debit == credit)
   *
   * @param string $batchName
   * @param array $rows
   * @param string $currency
   * @return array{debit:Money,credit:Money,net:Money}
   * @throws MoneyMismatchException
   * @throws \CRM_Core_Exception|UnknownCurrencyException
   */
  protected function validateCsvBatch(string $batchName, array $rows, string $currency): array {
    if (count($rows) < 2) {
      throw new \CRM_Core_Exception("Batch {$batchName} has fewer than 2 lines");
    }

    $debit  = Money::zero($currency);
    $credit = Money::zero($currency);
    foreach ($rows as $rowIndex => $row) {
      $debitAmount  = (string) ($row['DEBIT'] ?? '0');
      $creditAmount = (string) ($row['CREDIT'] ?? '0');

      $debitMoney  = Money::of($debitAmount, $currency, null, RoundingMode::HALF_UP);
      $creditMoney = Money::of($creditAmount, $currency, null, RoundingMode::HALF_UP);

      $hasDebit  = !$debitMoney->isZero();
      $hasCredit = !$creditMoney->isZero();

      if (($hasDebit && $hasCredit) || (!$hasDebit && !$hasCredit)) {
        $lineNo = (string) ($row['LINE_NO'] ?? ($rowIndex + 1));
        throw new \CRM_Core_Exception(
          "Batch {$batchName} line {$lineNo} must have exactly one of DEBIT or CREDIT populated"
        );
      }

      if ($hasDebit) {
        $debit = $debit->plus($debitMoney);
      }
      else {
        $credit = $credit->plus($creditMoney);
      }
    }

    if (!$debit->isEqualTo($credit)) {
      throw new \CRM_Core_Exception(
        "Batch {$batchName} is not balanced. Debit " . (string) $debit->getAmount() .
        " does not equal Credit " . (string) $credit->getAmount() .
        " (currency " . $currency . ")"
      );
    }

    $this->log("Batch {$batchName} is balanced", $batchName);
    return [
      'debit'  => $debit,
      'credit' => $credit,
      'net'    => $credit->minus($debit),
    ];
  }

  /**
   * Get existing journal-entry by referenceNumber.
   *
   * @return array|false
   * @throws GuzzleException
   */
  protected function getExistingJournal(string $batchName): array|false {
    $resp = $this->getApiClient()->post('services/core/query', [
      'json' => [
        'object' => 'general-ledger/journal-entry',
        'fields' => ['id', 'referenceNumber', 'description', 'postingDate', 'state', 'webURL'],
        'filters' => [
          [
            '$eq' => [
              'referenceNumber' => $batchName,
            ],
          ],
        ],
      ],
    ]);
    $data = json_decode((string) $resp->getBody(), TRUE);
    $this->batches[$batchName]['url'] = $data['ia::result'][0]['webURL'];
    $this->log('url for the journal is ' . $this->batches[$batchName]['url'], $batchName);
    $count = (int) ($data['ia::meta']['totalCount'] ?? 0);

    return $count > 0 ? $data : false;
  }

  /**
   * Query JE lines by journalEntry.id.
   *
   * @throws GuzzleException
   */
  protected function getJournalEntryLines(string $journalEntryId): array {
    $resp = $this->getApiClient()->post('services/core/query', [
      'json' => [
        'object' => 'general-ledger/journal-entry-line',
        'fields' => ['id', 'txnType', 'txnAmount', 'journalEntry.id'],
        'filters' => [
          [
            '$eq' => [
              'journalEntry.id' => $journalEntryId,
            ],
          ],
        ],
      ],
    ]);

    $data = json_decode((string) $resp->getBody(), TRUE);
    return $data['ia::result'] ?? [];
  }

  /**
   * Sum Intacct JE lines into debit/credit/net totals.
   *
   * @return array{debit:Money,credit:Money,net:Money}
   */
  protected function sumIntacctLinesMoney(array $lines, string $currency): array {
    $debit  = Money::zero($currency);
    $credit = Money::zero($currency);

    foreach ($lines as $line) {
      $transactionType = strtolower((string) ($line['txnType'] ?? ''));
      $amount          = (string) ($line['txnAmount'] ?? '0');

      if ($amount === '0' || $amount === '0.00' || $amount === '') {
        continue;
      }

      $money = Money::of($amount, $currency, null, RoundingMode::HALF_UP);

      if ($transactionType === 'debit') {
        $debit = $debit->plus($money);
      }
      elseif ($transactionType === 'credit') {
        $credit = $credit->plus($money);
      }
    }

    return [
      'debit'  => $debit,
      'credit' => $credit,
      'net'    => $credit->minus($debit),
    ];
  }

  /**
   * Exact match comparison (no tolerance).
   */
  protected function totalsMatchMoney(array $csvTotals, array $intacctTotals): bool {
    return
      $csvTotals['debit']->isEqualTo($intacctTotals['debit']) &&
      $csvTotals['credit']->isEqualTo($intacctTotals['credit']) &&
      $csvTotals['net']->isEqualTo($intacctTotals['net']);
  }

  private function log(string $string, $batchName): void {
    $this->log[$batchName][] = date('Y-m-d-m-Y-H-i-s') . ' ' . $string;
    \Civi::log('finance_integration')->info($string);
  }

  protected function throwIfNotInAllowedFolder(string $csvFile): void {
    foreach (\Civi::settings()->get('intacct_allowed_upload_folders') as $folder) {
      if (\CRM_Utils_File::isChildPath($folder, $csvFile)) {
        return;
      }
    }
    throw new \CRM_Core_Exception(
      "The csv file '$csvFile' is not in one of the allowed folders. " .
      'Please check intacct_allowed_upload_folders in CiviCRM settings'
    );
  }

  /**
   * @param string $existingId
   * @param string $batchName

   * @throws GuzzleException
   * @throws \CRM_Core_Exception
   */
  protected function validateExistingBatch(string $existingId, string $batchName): void {
    if ($existingId === '') {
      throw new \CRM_Core_Exception("Found existing journal for {$batchName} but missing id");
    }
    $csvTotals = $this->batches[$batchName]['csvTotals'];
    $currency = $this->batches[$batchName]['currency'];
    $existingLines = $this->getJournalEntryLines($existingId);
    $intacctTotals = $this->sumIntacctLinesMoney($existingLines, $currency);
    if ($this->totalsMatchMoney($csvTotals, $intacctTotals)) {
      // already posted and matches -> skip
      $this->batches[$batchName]['status'] = 'Valid Remotely';
      $this->log($batchName . ' exists in Intacct and the total in Intacct is correct - it is ready to close', $batchName);
      return;
    }

    throw new \CRM_Core_Exception(
      'Existing Intacct journal does not match CSV totals for referenceNumber ' .
      $batchName .
      '. CSV debit/credit/net = ' .
      (string) $csvTotals['debit']->getAmount() . ' / ' .
      (string) $csvTotals['credit']->getAmount() . ' / ' .
      (string) $csvTotals['net']->getAmount() .
      '; Intacct debit/credit/net = ' .
      (string) $intacctTotals['debit']->getAmount() . ' / ' .
      (string) $intacctTotals['credit']->getAmount() . ' / ' .
      (string) $intacctTotals['net']->getAmount()
    );
  }

}
