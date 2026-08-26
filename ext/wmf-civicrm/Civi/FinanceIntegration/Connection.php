<?php

namespace Civi\FinanceIntegration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class Connection {
  /**
   * @var null|Client
   */
  protected ?Client $tokenClient = NULL;

  protected ?Client $apiClient = NULL;

  protected string $tokenURL = 'https://api.intacct.com/ia/api/v1/oauth2/token';

  private ?string $accessToken = NULL;
  private ?int $tokenExpiry = NULL;

  private string $instance;

  private bool $isStaging;

  private static ?Client $testClient = NULL;

  private static ?Client $testXmlClient = NULL;

  public function __construct($instance = 'wmf', $isStaging = TRUE) {
    $this->instance = $instance;
    $this->isStaging = $isStaging;
  }

  public static function setTestClient(?Client $client): void {
    self::$testClient = $client;
  }

  public static function resetTestClient(): void {
    self::$testClient = NULL;
  }

  /**
   * Set a test client for the XML gateway (process_log) connection.
   *
   * Kept separate from setTestClient() - that one backs the REST journal
   * API, and sharing a single mock queue between the two transports causes
   * XML calls to silently consume responses meant for the REST mock (e.g.
   * in GenerateBatchTest), desyncing the queue.
   */
  public static function setTestXmlClient(?Client $client): void {
    self::$testXmlClient = $client;
  }

  public static function resetTestXmlClient(): void {
    self::$testXmlClient = NULL;
  }


  /**
   * @return Client
   * @throws GuzzleException
   * @throws \CRM_Core_Exception
   */
  public function getApiClient(): Client {
    if (self::$testClient) {
      return self::$testClient;
    }
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

  private ?XmlApi $xmlApi = NULL;

  /**
   * @return XmlApi
   * @throws \CRM_Core_Exception
   */
  public function getXmlApi(): XmlApi {
    if (!$this->xmlApi) {
      $this->xmlApi = new XmlApi($this->getCredentials(), self::$testXmlClient);
    }

    return $this->xmlApi;
  }

  /**
   * Username these credentials authenticate to Intacct as.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getUsername(): string {
    return (string) ($this->getCredentials()['username'] ?? '');
  }

  /**
   * @return string
   * @throws GuzzleException
   * @throws \CRM_Core_Exception
   */
  private function getBearerToken(): string {
    if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $credentials = $this->getCredentials();

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
        'Token request failed for ' . $this->instance . ' ' . ($this->isStaging ? 'Staging' : 'Prod') . ($status ? " (HTTP $status)" : '') . ': ' . $errorBody
      );
    }

    return $this->accessToken;
  }

  /**
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  private function getCredentials(): array {
    if (self::$testXmlClient) {
      return [
        'client_id' => 'test-client-id',
        'secret' => 'test-secret',
        'username' => 'test-user',
        'company_id' => 'test-company',
        'sender_id' => 'test-sender',
        'sender_password' => 'test-sender-password',
        'password' => 'test-password',
      ];
    }
    if ($this->isStaging) {
      if ($this->instance === 'endowment') {
        $key = 'STAGING_ENDOWMENT_FINANCE_OAUTH';
      }
      else {
        $key = 'STAGING_WMF_FINANCE_OAUTH';
      }
    }
    else {
      if ($this->instance === 'endowment') {
        $key = 'ENDOWMENT_FINANCE_OAUTH';
      }
      else {
        $key = 'WMF_FINANCE_OAUTH';
      }
    }
    $credentials = \CRM_Utils_Constant::value($key);
    if (!$credentials) {
      throw new \CRM_Core_Exception('No FINANCE_OAUTH credentials provided');
    }
    return $credentials;
  }

}
