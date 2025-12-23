<?php

namespace Civi\Api4\Action\FinanceIntegration;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use League\Csv\Exception;
use League\Csv\Reader;

/**
 * @method $this setJournalFile(string $fileName)
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

  /**
   * File of journals to push.
   *
   * @required
   *
   * @var string $journalFile
   */
  protected $journalFile;

  public function _run( Result $result ) {
    if (!$this->tokenClient) {
      $this->tokenClient = new Client([
        'base_uri' => $this->tokenURL,
      ]);
    }

    try {
      foreach ($this->buildJournalEntryPayloadsFromCsv() as $entry) {
        // Endpoint name can vary by version/tenant; many Intacct REST objects are under /objects/...
        // If you use Bulk/Batch later, you’ll swap this out.
        $resp = $this->getApiClient()->post('objects/general-ledger/journal-entry', [
          'json' => $entry,
        ]);
        $result[] = json_decode((string)$resp->getBody(), true);
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
        // Most OAuth token endpoints expect application/x-www-form-urlencoded
        'form_params' => $payload,
        'headers' => [
          'Accept' => 'application/json',
        ],
        // Optional but often helpful:
        // 'timeout' => 15,
      ]);

      $body = (string) $response->getBody();
      $data = json_decode($body, true);
      $this->accessToken = $data['access_token'];
      $this->tokenExpiry = time() + ((int)$data['expires_in'] - 60);
      $this->apiClient = NULL;

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \CRM_Core_Exception('Token response was not valid JSON: ' . json_last_error_msg());
      }
    }
    catch (RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
      $errorBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();

      throw new \CRM_Core_Exception(
        'Token request failed' . ($status ? " (HTTP $status)" : '') . ': ' . $errorBody
      );
    }

    return $data['access_token'];
  }

  /**
   * @param string $lineId
   * @return mixed
   * @throws GuzzleException
   * @throws \CRM_Core_Exception
   */
  public function getLine(string $lineId): mixed {
    $resp = $this->getApiClient()->get("objects/general-ledger/journal-entry-line/{$lineId}");
    return json_decode((string)$resp->getBody(), true);
  }

  private function buildJournalEntryPayloadsFromCsv() : array {
    $csvPath = $this->journalFile;
    if (!file_exists($csvPath)) {
      throw new \CRM_Core_Exception("CSV not found: $csvPath");
    }

    try {
      $csv = Reader::createFromPath($csvPath, 'r');
      $csv->setHeaderOffset(0); // first row is the header
    }
    catch (Exception $e) {
      throw new \CRM_Core_Exception("Unable to open CSV: $csvPath");
    }

    $batches = [];
    foreach ($csv->getRecords() as $row) {
      // $row is already an associative array keyed by header names

      // Skip “DONOTIMPORT” rows if present
      if (!empty($row['DONOTIMPORT'])) {
        continue;
      }
      $batches[$row['DOCUMENT']][] = $row;
    }

    $entries = [];
    foreach ($batches as $batchName => $groupRows) {
      $first = $groupRows[0];

      // Convert mm/dd/yyyy -> yyyy-mm-dd (adjust if your CSV differs)
      $postingDate = \DateTime::createFromFormat('m/d/Y', $first['DATE'])
        ?: \DateTime::createFromFormat('n/j/Y', $first['DATE']);
      if (!$postingDate) {
        throw new \CRM_Core_Exception('Bad DATE format: ' . ($first['DATE'] ?? ''));
      }
      $entry = [
        'glJournal' => ['id' => $first['JOURNAL']],
        'postingDate' => $postingDate->format('Y-m-d'),
        'description' => $first['DESCRIPTION'] ?: null,
        'state'       => 'draft',
        'referenceNumber' => $first['DOCUMENT'],
        'lines' => [],
      ];

      foreach ($groupRows as $row) {
        $debit  = (float)($row['DEBIT'] ?? 0);
        $credit = (float)($row['CREDIT'] ?? 0);

        $txnType   = $debit > 0 ? 'debit' : 'credit';
        $txnAmount = $debit > 0 ? $debit : $credit;

        $line = [
          'txnType'   => $txnType,
          'txnAmount' => (string) $txnAmount,
          'glAccount' => ['id' => (string)$row['ACCT_NO']],
          'documentId'=> $row['DOCUMENT'] ?: null,
          'description' => $row['MEMO'] ?: ($row['DESCRIPTION'] ?: null),

          'dimensions' => array_filter([
            'location'   => !empty($row['LOCATION_ID']) ? ['id' => $row['LOCATION_ID']] : null,
            'department' => !empty($row['DEPT_ID'])     ? ['id' => $row['DEPT_ID']]     : null,
            'vendor'     => !empty($row['GLENTRY_VENDORID']) ? ['id' => $row['GLENTRY_VENDORID']] : null,

            // Only include if you map to KEY
            'nsp::funding' => ['key' => (string) ($row['GLDIMFUNDING'] === 'Unrestricted' ? 10004 : 10005)],
          ]),
        ];

        $entry['lines'][] = $line;
      }

      $entries[] = $entry;
    }

    return $entries;
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

}
