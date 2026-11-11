<?php

namespace Civi\Api4\Action\FinanceIntegration;

use Civi\Api4\FinanceIntegration;
use Civi\FinanceIntegration\Connection;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class GetFieldsTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;

  private array $requests = [];

  public function tearDown(): void {
    Connection::resetTestClient();
    parent::tearDown();
  }

  /**
   * Without loadOptions only the dimension list is fetched.
   */
  public function testDimensionsAsFields(): void {
    $this->mockResponses([$this->dimensionsResponse()]);

    $fields = (array) FinanceIntegration::getFields(FALSE)->execute()->indexBy('name');

    $this->assertEquals(['department', 'location', 'funding', 'account'], array_keys($fields));
    $this->assertEquals('Location', $fields['location']['title']);
    $this->assertEquals('Entity', $fields['location']['label']);
    $this->assertEquals('Select', $fields['location']['input_type']);
    $this->assertTrue($fields['location']['options']);
    $this->assertTrue($fields['funding']['is_user_defined']);
    $this->assertEquals('/objects/platform-apps/nsp::funding', $fields['funding']['dimension_endpoint']);
    $this->assertEquals('/objects/general-ledger/account', $fields['account']['dimension_endpoint']);
    $this->assertCount(1, $this->requests);
  }

  /**
   * Options are only fetched for the requested fields - paged, with
   * accounts sorted by number and others by label.
   */
  public function testLoadOptions(): void {
    $this->mockResponses([
      $this->dimensionsResponse(),
      $this->jsonResponse([['id' => 'D2', 'name' => 'zebra'], ['id' => 'D1', 'name' => 'Aardvark']]),
      $this->jsonResponse([['id' => '4100', 'name' => 'Revenue'], ['id' => '11000', 'name' => 'Asset']], ['next' => 3]),
      $this->jsonResponse([['id' => '1100', 'name' => 'Cash']]),
    ]);

    $fields = (array) FinanceIntegration::getFields(FALSE)
      ->setLoadOptions(['id', 'label'])
      ->addWhere('name', 'IN', ['department', 'account'])
      ->execute()->indexBy('name');

    $this->assertEquals(['department', 'account'], array_keys($fields));
    $this->assertEquals([['id' => 'D1', 'label' => 'Aardvark'], ['id' => 'D2', 'label' => 'zebra']], $fields['department']['options']);
    $this->assertEquals(['1100', '4100', '11000'], array_column($fields['account']['options'], 'id'));

    $this->assertCount(4, $this->requests);
    $this->assertEquals(['object' => 'company-config/department', 'fields' => ['id', 'name'], 'start' => 1, 'size' => 4000], $this->getRequestBody(1));
    $this->assertEquals('general-ledger/account', $this->getRequestBody(2)['object']);
    $this->assertEquals(3, $this->getRequestBody(3)['start']);
  }

  private function dimensionsResponse(): Response {
    return $this->jsonResponse([
      ['dimensionName' => 'DEPARTMENT', 'dimensionLabel' => 'Department', 'termName' => 'Department', 'isUserDefinedDimension' => FALSE, 'dimensionEndpoint' => '/objects/company-config/department', 'dimensionKey' => 5002],
      ['dimensionName' => 'LOCATION', 'dimensionLabel' => 'Location', 'termName' => 'Entity', 'isUserDefinedDimension' => FALSE, 'dimensionEndpoint' => '/objects/company-config/location', 'dimensionKey' => 5003],
      ['dimensionName' => 'FUNDING', 'dimensionLabel' => 'Funding', 'termName' => 'Funding', 'isUserDefinedDimension' => TRUE, 'dimensionEndpoint' => '/objects/platform-apps/nsp::funding', 'dimensionKey' => 10002],
    ]);
  }

  private function mockResponses(array $responses): void {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->requests));
    Connection::setTestClient(new Client(['handler' => $stack]));
  }

  private function getRequestBody(int $index): array {
    return json_decode((string) $this->requests[$index]['request']->getBody(), TRUE);
  }

  private function jsonResponse(array $result, array $meta = []): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['ia::result' => $result, 'ia::meta' => $meta]));
  }

}
