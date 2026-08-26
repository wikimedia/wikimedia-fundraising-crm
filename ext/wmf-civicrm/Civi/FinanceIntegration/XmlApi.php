<?php

namespace Civi\FinanceIntegration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class XmlApi {

  private const ENDPOINT = 'https://api.intacct.com/ia/xml/xmlgw.phtml';

  private Client $client;

  private array $credentials;

  public function __construct(array $credentials, ?Client $client = NULL) {
    $this->credentials = $credentials;
    $this->client = $client ?? new Client();
  }

  /**
   * Execute an XML API request.
   *
   * @param string $content
   *
   * @return string
   *
   * @throws GuzzleException
   * @throws \CRM_Core_Exception
   */
  public function execute(string $content): string {
    $xml = $this->buildRequest($content);

    try {
      $response = $this->client->post(self::ENDPOINT, [
        'body' => $xml,
        'headers' => [
          'Content-Type' => 'application/xml',
          'Accept' => 'application/xml',
        ],
      ]);

      return (string) $response->getBody();
    }
    catch (RequestException $e) {
      $status = $e->hasResponse()
        ? $e->getResponse()->getStatusCode()
        : NULL;

      $errorBody = $e->hasResponse()
        ? (string) $e->getResponse()->getBody()
        : $e->getMessage();

      throw new \CRM_Core_Exception(
        'Intacct XML API request failed'
        . ($status ? " (HTTP $status)" : '')
        . ': ' . $errorBody
      );
    }
  }

  /**
   * Create an object.
   *
   * @param string $objectName
   * @param array $fields
   * @param string|null $controlId
   *
   * @return string
   */
  public function createObject(
    string $objectName,
    array $fields,
    ?string $controlId = NULL
  ): string {
    $controlId ??= 'create-' . strtolower($objectName) . '-' . time();

    $fieldXml = '';

    foreach ($fields as $field => $value) {
      $fieldXml .= sprintf(
        '<%s>%s</%s>',
        htmlspecialchars($field, ENT_XML1),
        htmlspecialchars((string) $value, ENT_XML1),
        htmlspecialchars($field, ENT_XML1)
      );
    }

    return sprintf(
      '<function controlid="%s">
    <create>
        <%s>
            %s
        </%s>
    </create>
</function>',
      htmlspecialchars($controlId, ENT_XML1),
      htmlspecialchars($objectName, ENT_XML1),
      $fieldXml,
      htmlspecialchars($objectName, ENT_XML1)
    );
  }

  /**
   * Convenience wrapper for creating a process_log record.
   *
   * @param array $fields
   *
   * @return string
   */
  public function createProcessLog(array $fields): string {
    return $this->createObject('process_log', $fields, 'create-process-log');
  }

  /**
   * Query an object.
   *
   * @param string $objectName
   * @param array $fields
   * @param int $pageSize
   * @param string|null $controlId
   *
   * @return string
   */
  public function queryObject(
    string $objectName,
    array $fields,
    int $pageSize = 20,
    ?string $controlId = NULL
  ): string {
    $controlId ??= 'query-' . strtolower($objectName);

    $select = implode(
      "\n",
      array_map(
        fn(string $field) => '<field>'
          . htmlspecialchars($field, ENT_XML1)
          . '</field>',
        $fields
      )
    );

    return sprintf(
      '<function controlid="%s">
    <query>
        <object>%s</object>
        <select>
            %s
        </select>
        <pagesize>%d</pagesize>
    </query>
</function>',
      htmlspecialchars($controlId, ENT_XML1),
      htmlspecialchars($objectName, ENT_XML1),
      $select,
      $pageSize
    );
  }

  /**
   * Inspect an object.
   *
   * @param string $objectName
   * @param string|null $controlId
   *
   * @return string
   */
  public function inspectObject(
    string $objectName,
    ?string $controlId = NULL
  ): string {
    $controlId ??= 'inspect-' . strtolower($objectName);

    return sprintf(
      '<function controlid="%s">
    <inspect>
        <object>%s</object>
    </inspect>
</function>',
      htmlspecialchars($controlId, ENT_XML1),
      htmlspecialchars($objectName, ENT_XML1)
    );
  }

  /**
   * List all available objects.
   *
   * @return string
   */
  public function listObjects(): string {
    return '<function controlid="list-objects">
    <inspect>
        <object>*</object>
    </inspect>
</function>';
  }

  /**
   * Build a complete XML Gateway request.
   *
   * @param string $content
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  private function buildRequest(string $content): string {
    $senderId = $this->getCredential('sender_id');
    $senderPassword = $this->getCredential('sender_password');
    $userId = $this->getCredential('username');
    $userPassword = $this->getCredential('password');
    $companyId = $this->getCredential('company_id');

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<request>
  <control>
    <senderid>{$senderId}</senderid>
    <password>{$senderPassword}</password>
    <controlid>donation-log-{$this->getControlId()}</controlid>
    <uniqueid>false</uniqueid>
    <dtdversion>3.0</dtdversion>
  </control>
  <operation>
    <authentication>
      <login>
        <userid>{$userId}</userid>
        <companyid>{$companyId}</companyid>
        <password>{$userPassword}</password>
      </login>
    </authentication>
    <content>
      {$content}
    </content>
  </operation>
</request>
XML;
  }

  /**
   * Get a credential and XML-escape it.
   *
   * @param string $key
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  private function getCredential(string $key): string {
    if (!isset($this->credentials[$key])) {
      throw new \CRM_Core_Exception(
        'Missing Intacct XML API credential: ' . $key
      );
    }

    return $this->escapeXml((string) $this->credentials[$key]);
  }

  /**
   * XML-escape a value.
   *
   * @param string $value
   *
   * @return string
   */
  private function escapeXml(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }

  /**
   * Generate a control ID.
   *
   * @return string
   */
  private function getControlId(): string {
    return (string) time();
  }

}
