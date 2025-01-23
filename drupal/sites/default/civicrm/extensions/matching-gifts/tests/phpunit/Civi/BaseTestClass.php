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

  protected array $originalSettings = [];

  protected function tearDown(): void {
    foreach ($this->originalSettings as $key => $value) {
      \Civi::settings()->set($key, $value);
    }
    parent::tearDown();
  }

  protected function setSetting(string $key, $value): void {
    $this->originalSettings[$key] = \Civi::settings()->get($key);
    \Civi::settings()->set($key, $value);
  }

  public function setDataFilePath(): void {
    $this->setSetting('matching_gifts_employer_data_file_path', sys_get_temp_dir() . '/employers.csv');
    if (file_exists(sys_get_temp_dir() . '/employers.csv')) {
      unlink(sys_get_temp_dir() . '/employers.csv');
    }
  }

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
