<?php

/**
 * @file
 * Silverpop engage class.
 */

require_once dirname(__FILE__) . '/Validation.php';

class Engage {

  protected $apiHost = null;
  protected $username = null;
  protected $password = null;
  protected $sessionId = null;
  protected $lastRequest = null;
  protected $lastResponse = null;
  protected $lastFault = null;

  public function __construct($apiHost) {
    $this->apiHost = $apiHost;
  }

  public function execute($request) {
    if ($request instanceof SimpleXMLElement) {
      $requestXml = $request->asXML();
    } else {
      $requestXml = "<?xml version=\"1.0\"?>\n<Envelope><Body>{$request}</Body></Envelope>";
    }

    if (!Validation::isValidUTF8($requestXml)) {
      $requestXml = utf8_encode($requestXml);
    }

    $this->lastRequest = $requestXml;
    $this->lastResponse = null;
    $this->lastFault = null;

    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $this->getApiUrl());
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $requestXml);
    curl_setopt($curl, CURLINFO_HEADER_OUT, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8', 'Content-Length: ' . strlen($requestXml)));
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
    curl_setopt($curl, CURLOPT_TIMEOUT, 180);

    $responseXml = @curl_exec($curl);

    if ($responseXml === false) {
      throw new Exception('CURL error: ' . curl_error($curl));
    }

    curl_close($curl);

    if ($responseXml === true || !trim($responseXml)) {
      throw new Exception('Empty response from Engage');
    }

    $this->lastResponse = $responseXml;

    if (!Validation::isValidUTF8($responseXml)) {
      $responseXml = utf8_encode($responseXml);
    }

    $response = @simplexml_load_string('<?xml version="1.0"?>' . $responseXml);

    if ($response === false) {
      throw new Exception('Invalid XML response from Engage');
    }

    if (!isset($response->Body)) {
      throw new Exception('Engage response contains no Body');
    }

    $response = $response->Body;

    $this->checkResult($response);

    return $response;
  }

  public function getApiUrl() {
    $url = "https://{$this->apiHost}/XMLAPI";

    if ($this->sessionId !== null) {
      $url .= ';jsessionid=' . urlencode($this->sessionId);
    }

    return $url;
  }

  public function checkResult($xml) {
    if (!isset($xml->RESULT)) {
      throw new Exception('Engage XML response body does not contain RESULT');
    }

    if (!isset($xml->RESULT->SUCCESS)) {
      throw new Exception('Engage XML response body does not contain RESULT/SUCCESS');
    }

    $success = strtoupper($xml->RESULT->SUCCESS);

    if (in_array($success, array('TRUE', 'SUCCESS'))) {
      return true;
    }

    if ($xml->Fault) {
      $this->lastFault = $xml->Fault;
      $code = (string) $xml->Fault->FaultCode;
      $error = (string) $xml->Fault->FaultString;
      throw new Exception("Engage fault '{$error}'" . ($code ? "(code: {$code})" : ''));
    }

    throw new Exception('Unrecognized Engage API response');
  }

  public function getLastRequest() {
    return $this->lastRequest;
  }

  public function getLastResponse() {
    return $this->lastResponse;
  }

  public function getLastFault() {
    return $this->lastFault;
  }

  public function login($username, $password) {
    $this->username = $username;
    $this->password = $password;
    $this->sessionId = null;

    $request = "<Login><USERNAME><![CDATA[{$username}]]></USERNAME><PASSWORD><![CDATA[{$password}]]></PASSWORD></Login>";

    try {
      $response = $this->execute($request);
    } catch (Exception $e) {
      throw new Exception('Login failed: ' . $e->getMessage());
    }

    if (!isset($response->RESULT->SESSIONID)) {
      throw new Exception('Login response did not include SESSIONID');
    }

    $this->sessionId = $response->RESULT->SESSIONID;
  }

}
