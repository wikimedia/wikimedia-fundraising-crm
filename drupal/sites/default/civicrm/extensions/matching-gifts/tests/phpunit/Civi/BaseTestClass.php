<?php

namespace Civi;

use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;

/**
 * @group MatchingGifts
 */
class BaseTestClass extends TestCase {

  /**
   * @var array
   */
  protected array $requests;

  protected function setUpMockResponse(array $responseBodies): void {
    $this->requests = [];
    $history = Middleware::history($this->requests);
    $responses = [];
    foreach ($responseBodies as $responseBody) {
      $responses[] = new Response(200, [], $responseBody);
    }
    $mock = new MockHandler($responses);
    $handler = HandlerStack::create($mock);
    $handler->push($history);
    $httpClient = new Client(['handler' => $handler]);
    \CRM_MatchingGifts_SsbinfoProvider::setClient($httpClient);
  }

  public function getResponseContents(string $fileName) : string {
    $directory = __DIR__ . '/../CRM/MatchingGifts/Responses/';
    return file_get_contents($directory . $fileName);
  }

}
