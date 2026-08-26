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
class PushProcessLogTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;

  private array $requestHistory = [];

  public function tearDown(): void {
    Connection::resetTestXmlClient();
    $this->tearDownWMFEnvironment();
    parent::tearDown();
  }

  /**
   * A fully populated call should send all fields through to the XML API
   * and report success.
   */
  public function testPushProcessLogSendsSuppliedFields(): void {
    $this->mockClient(new Response(200, [], '<response><status>success</status></response>'));

    $result = FinanceIntegration::pushProcessLog(FALSE)
      ->setName('SmashPig Test Import')
      ->setDescription('Test process log created via XML API')
      ->setFileFormat('Journal')
      ->setStatus('Complete')
      ->setSummary('Test journal import: 123 records, 0 errors')
      ->setComment('Created by SmashPig XML API test')
      ->setProcessType('SmashPig')
      ->setJobType('Journal Import')
      ->setProcessedPercentage(100)
      ->setUser('smashpig')
      ->setDocid('12345')
      ->setResultsUrl('https://example.org/results/12345')
      ->execute();

    $this->assertCount(1, $this->requestHistory);
    $body = (string) $this->requestHistory[0]['request']->getBody();

    $this->assertStringContainsString('<name>SmashPig Test Import</name>', $body);
    $this->assertStringContainsString('<description>Test process log created via XML API</description>', $body);
    $this->assertStringContainsString('<file_format>Journal</file_format>', $body);
    $this->assertStringContainsString('<status>Complete</status>', $body);
    $this->assertStringContainsString('<summary>Test journal import: 123 records, 0 errors</summary>', $body);
    $this->assertStringContainsString('<comment>Created by SmashPig XML API test</comment>', $body);
    $this->assertStringContainsString('<process_type>SmashPig</process_type>', $body);
    $this->assertStringContainsString('<job_type>Journal Import</job_type>', $body);
    $this->assertStringContainsString('<processed_percentage>100</processed_percentage>', $body);
    $this->assertStringContainsString('<user>smashpig</user>', $body);
    $this->assertStringContainsString('<docid>12345</docid>', $body);
    $this->assertStringContainsString('<results_url>https://example.org/results/12345</results_url>', $body);

    $this->assertTrue($result[0]['success']);
    $this->assertSame('<response><status>success</status></response>', $result[0]['response']);
  }

  /**
   * Optional fields left unset should not appear in the XML at all
   * (rather than being sent as empty tags), while the defaults for
   * status/process_type/job_type/processed_percentage still get sent, and
   * 'user' falls back to the connection's own credentials.
   */
  public function testUnsetOptionalFieldsAreOmitted(): void {
    $this->mockClient(new Response(200, [], '<response/>'));

    FinanceIntegration::pushProcessLog(FALSE)
      ->setName('adyen_338_USD')
      ->execute();

    $body = (string) $this->requestHistory[0]['request']->getBody();

    $this->assertStringNotContainsString('<description>', $body);
    $this->assertStringNotContainsString('<file_format>', $body);
    $this->assertStringNotContainsString('<summary>', $body);
    $this->assertStringNotContainsString('<comment>', $body);
    $this->assertStringNotContainsString('<docid>', $body);
    $this->assertStringNotContainsString('<results_url>', $body);
    $this->assertStringContainsString('<status>Complete</status>', $body);
    $this->assertStringContainsString('<process_type>SmashPig</process_type>', $body);
    $this->assertStringContainsString('<job_type>Journal Import</job_type>', $body);
    $this->assertStringContainsString('<processed_percentage>100</processed_percentage>', $body);
    // No explicit user set - falls back to the (dummy test) connection credentials.
    $this->assertStringContainsString('<user>test-user</user>', $body);
  }

  /**
   * An explicitly set user should override the one from the connection's
   * credentials.
   */
  public function testExplicitUserOverridesConnectionCredentials(): void {
    $this->mockClient(new Response(200, [], '<response/>'));

    FinanceIntegration::pushProcessLog(FALSE)
      ->setName('adyen_338_USD')
      ->setUser('smashpig-bot')
      ->execute();

    $body = (string) $this->requestHistory[0]['request']->getBody();
    $this->assertStringContainsString('<user>smashpig-bot</user>', $body);
  }

  /**
   * A failed push (status = Failed) should be sendable with just a name,
   * status and comment, as used for logging Intacct push failures.
   */
  public function testFailedStatusIsSent(): void {
    $this->mockClient(new Response(200, [], '<response/>'));

    FinanceIntegration::pushProcessLog(FALSE)
      ->setName('adyen_338_USD')
      ->setStatus('Failed')
      ->setSummary('Journal push to Intacct failed')
      ->setComment('Intacct journal POST failed (HTTP 500): boom')
      ->execute();

    $body = (string) $this->requestHistory[0]['request']->getBody();

    $this->assertStringContainsString('<status>Failed</status>', $body);
    $this->assertStringContainsString('<comment>Intacct journal POST failed (HTTP 500): boom</comment>', $body);
  }

  private function mockClient(Response $response): void {
    $mock = new MockHandler([$response]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($this->requestHistory));
    Connection::setTestXmlClient(new Client(['handler' => $handlerStack]));
  }

}
