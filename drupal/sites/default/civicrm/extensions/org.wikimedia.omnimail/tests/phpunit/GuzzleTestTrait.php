<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;
use GuzzleHttp\Client;

/**
 * Class GuzzleTestTrait
 *
 * This trait defines a number of helper functions for testing guzzle.
 *
 * So far it's experimental - trying to figure out what helpers are most
 * useful but later it might be upstreamed.
 *
 *
 * This trait is intended for use with PHPUnit-based test cases.
 */
trait GuzzleTestTrait {

  /**
   * @var MockHandler
   */
  protected $mockHandler;

  /**
   * @var string
   */
  protected $baseUri;

  /** Array containing guzzle history of requests and responses.
   *
   * @var array
   */
  protected $container;

  /**
   * @return array
   */
  public function getContainer(): array {
    return $this->container;
  }

  /**
   * @param array $container
   */
  public function setContainer($container) {
    $this->container = $container;
  }

  /**
   * @return \GuzzleHttp\Client
   */
  public function getGuzzleClient(): Client {
    return $this->guzzleClient;
  }

  /**
   * @param \GuzzleHttp\Client $guzzleClient
   */
  public function setGuzzleClient($guzzleClient) {
    $this->guzzleClient = $guzzleClient;
  }

  /**
   * @var Client
   */
  protected $guzzleClient;

  /**
   * @return mixed
   */
  public function getBaseUri() {
    return $this->baseUri;
  }

  /**
   * @param mixed $baseUri
   */
  public function setBaseUri($baseUri) {
    $this->baseUri = $baseUri;
  }

  /**
   * @return \GuzzleHttp\Handler\MockHandler
   */
  public function getMockHandler(): MockHandler {
    return $this->mockHandler;
  }

  /**
   * @param \GuzzleHttp\Handler\MockHandler $mockHandler
   */
  public function setMockHandler($mockHandler) {
    $this->mockHandler = $mockHandler;
  }

  /**
   * @param $responses
   */
  protected function createMockHandler($responses) {
    $mocks = [];
    foreach ($responses as $response) {
      $mocks[] = new Response(200, [], $response);
    }
    $this->setMockHandler(new MockHandler($mocks));
  }

  /**
   * @param $files
   */
  protected function createMockHandlerForFiles($files) {
    $body = [];
    foreach ($files as $file) {
      $body[] = trim(file_get_contents(__DIR__ . $file));
    }
    $this->createMockHandler($body);
  }

  /**
   * Set up a guzzle client with a history container.
   *
   * After you have run the requests you can inspect $this->container
   * for the outgoing requests and incoming responses.
   *
   * If $this->mock is defined then no outgoing http calls will be made
   * and the responses configured on the handler will be returned instead
   * of replies from a remote provider.
   */
  protected function setUpClientWithHistoryContainer() {
    $this->container = [];
    $history = Middleware::history($this->container);
    $handler = HandlerStack::create($this->mockHandler);
    $handler->push($history);
    $this->guzzleClient = new Client(['base_uri' => $this->baseUri, 'handler' => $handler]);
  }

  /**
   * Get the bodies of the requests sent via Guzzle.
   *
   * @return array
   */
  protected function getRequestBodies(): array {
    $requests = [];
    foreach ($this->getContainer() as $guzzle) {
      $requests[] = (string) $guzzle['request']->getBody();
    }
    return $requests;
  }

  /**
   * Get the bodies of the requests sent via Guzzle.
   *
   * @return array
   */
  protected function getRequestUrls(): array {
    $requests = [];
    foreach ($this->getContainer() as $guzzle) {
      $requests[] = (string) $guzzle['request']->getUri();
    }
    return $requests;
  }

  /**
   * Get the bodies of the responses returned via Guzzle.
   *
   * @return array
   */
  protected function getResponseBodies(): array {
    $responses = [];
    foreach ($this->getContainer() as $guzzle) {
      $responses[] = (string) $guzzle['response']->getBody();
    }
    return $responses;
  }

}
