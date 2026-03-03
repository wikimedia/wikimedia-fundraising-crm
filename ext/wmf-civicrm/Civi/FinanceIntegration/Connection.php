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

  public function __construct($instance = 'wmf', $isStaging = TRUE) {
    $this->instance = $instance;
    $this->isStaging = $isStaging;
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
