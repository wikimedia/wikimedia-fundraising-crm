<?php

namespace Civi\FinanceIntegration\XmlApi;

use Civi\FinanceIntegration\XmlApi;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\TestCase;

class XmlApiTest extends TestCase {

  private array $credentials = [
    'sender_id' => 'test-sender',
    'sender_password' => 'test-sender-password',
    'username' => 'test-user',
    'password' => 'test-password',
    'company_id' => 'test-company',
  ];

  public function testCreateObject(): void {
    $xmlApi = new XmlApi($this->credentials);

    $xml = $xmlApi->createObject('DONATIONS_API_LOG', [
      'RUN_ID' => 'run_123',
      'INTEGRATION_NAME' => 'Donations API',
      'STATUS' => 'Running',
    ], 'create-test-log');

    $expected = <<<XML
<function controlid="create-test-log">
    <create>
        <DONATIONS_API_LOG>
            <RUN_ID>run_123</RUN_ID>
            <INTEGRATION_NAME>Donations API</INTEGRATION_NAME>
            <STATUS>Running</STATUS>
        </DONATIONS_API_LOG>
    </create>
</function>
XML;

    $this->assertXmlStringEqualsXmlString($expected, $xml);
  }

  public function testCreateObjectEscapesValues(): void {
    $xmlApi = new XmlApi($this->credentials);

    $xml = $xmlApi->createObject('process_log', [
      'name' => 'Test & Import',
      'description' => '<test>',
    ], 'create-test');

    $expected = <<<XML
<function controlid="create-test">
    <create>
        <process_log>
            <name>Test &amp; Import</name>
            <description>&lt;test&gt;</description>
        </process_log>
    </create>
</function>
XML;

    $this->assertXmlStringEqualsXmlString($expected, $xml);
  }

  public function testCreateProcessLog(): void {
    $xmlApi = new XmlApi($this->credentials);

    $fields = [
      'name' => 'SmashPig Test Import',
      'description' => 'Test process log created via XML API',
      'status' => 'Complete',
      'summary' => 'Test journal import: 123 records, 0 errors',
      'comment' => 'Created by SmashPig XML API test',
      'process_type' => 'SmashPig',
      'job_type' => 'Journal Import',
    ];

    $xml = $xmlApi->createProcessLog($fields);

    // createProcessLog() should just be a convenience wrapper
    // around createObject().
    $expected = $xmlApi->createObject(
      'process_log',
      $fields,
      'create-process-log'
    );

    $this->assertXmlStringEqualsXmlString($expected, $xml);
  }

  public function testQueryObject(): void {
    $xmlApi = new XmlApi($this->credentials);

    $xml = $xmlApi->queryObject('process_log', [
      'id',
      'name',
      'status',
      'createdAt',
    ]);

    $expected = <<<XML
<function controlid="query-process_log">
    <query>
        <object>process_log</object>
        <select>
            <field>id</field>
            <field>name</field>
            <field>status</field>
            <field>createdAt</field>
        </select>
        <pagesize>20</pagesize>
    </query>
</function>
XML;

    $this->assertXmlStringEqualsXmlString($expected, $xml);
  }

  public function testQueryObjectSupportsCustomPageSizeAndControlId(): void {
    $xmlApi = new XmlApi($this->credentials);

    $xml = $xmlApi->queryObject(
      'process_log',
      ['id', 'name'],
      100,
      'my-query'
    );

    $expected = <<<XML
<function controlid="my-query">
    <query>
        <object>process_log</object>
        <select>
            <field>id</field>
            <field>name</field>
        </select>
        <pagesize>100</pagesize>
    </query>
</function>
XML;

    $this->assertXmlStringEqualsXmlString($expected, $xml);
  }

  public function testInspectObject(): void {
    $xmlApi = new XmlApi($this->credentials);

    $xml = $xmlApi->inspectObject('process_log');

    $expected = <<<XML
<function controlid="inspect-process_log">
    <inspect>
        <object>process_log</object>
    </inspect>
</function>
XML;

    $this->assertXmlStringEqualsXmlString($expected, $xml);
  }

  public function testInspectObjectSupportsCustomControlId(): void {
    $xmlApi = new XmlApi($this->credentials);

    $xml = $xmlApi->inspectObject(
      'process_log',
      'inspect-test'
    );

    $this->assertStringContainsString(
      'controlid="inspect-test"',
      $xml
    );
  }

  public function testListObjects(): void {
    $xmlApi = new XmlApi($this->credentials);

    $xml = $xmlApi->listObjects();

    $expected = <<<XML
<function controlid="list-objects">
    <inspect>
        <object>*</object>
    </inspect>
</function>
XML;

    $this->assertXmlStringEqualsXmlString($expected, $xml);
  }

  public function testExecutePostsXmlAndReturnsResponse(): void {
    $mock = new MockHandler([
      new Response(
        200,
        [],
        '<response><status>success</status></response>'
      ),
    ]);

    $client = new Client([
      'handler' => HandlerStack::create($mock),
    ]);

    $xmlApi = new XmlApi($this->credentials, $client);

    $response = $xmlApi->execute(
      '<function controlid="test"><query/></function>'
    );

    $this->assertSame(
      '<response><status>success</status></response>',
      $response
    );

    $request = $mock->getLastRequest();

    $this->assertNotNull($request);
    $this->assertSame('POST', $request->getMethod());

    $this->assertSame(
      'https://api.intacct.com/ia/xml/xmlgw.phtml',
      (string) $request->getUri()
    );

    $this->assertSame(
      'application/xml',
      $request->getHeaderLine('Content-Type')
    );

    $this->assertSame(
      'application/xml',
      $request->getHeaderLine('Accept')
    );

    $this->assertStringContainsString(
      '<function controlid="test"><query/></function>',
      (string) $request->getBody()
    );
  }

  public function testExecuteBuildsAuthenticatedRequest(): void {
    $mock = new MockHandler([
      new Response(200, [], '<response/>'),
    ]);

    $client = new Client([
      'handler' => HandlerStack::create($mock),
    ]);

    $xmlApi = new XmlApi($this->credentials, $client);

    $xmlApi->execute('<function controlid="test"/>');

    $request = $mock->getLastRequest();
    $body = (string) $request->getBody();

    $this->assertStringContainsString(
      '<senderid>test-sender</senderid>',
      $body
    );

    $this->assertStringContainsString(
      '<password>test-sender-password</password>',
      $body
    );

    $this->assertStringContainsString(
      '<userid>test-user</userid>',
      $body
    );

    $this->assertStringContainsString(
      '<companyid>test-company</companyid>',
      $body
    );

    $this->assertStringContainsString(
      '<password>test-password</password>',
      $body
    );

    $this->assertStringContainsString(
      '<function controlid="test"/>',
      $body
    );
  }

  public function testExecuteThrowsExceptionOnHttpError(): void {
    $mock = new MockHandler([
      new RequestException(
        'Bad request',
        new Request(
          'POST',
          'https://api.intacct.com/ia/xml/xmlgw.phtml'
        ),
        new Response(
          400,
          [],
          'Intacct error'
        )
      ),
    ]);

    $client = new Client([
      'handler' => HandlerStack::create($mock),
    ]);

    $xmlApi = new XmlApi($this->credentials, $client);

    $this->expectException(\CRM_Core_Exception::class);

    $this->expectExceptionMessage(
      'Intacct XML API request failed (HTTP 400): Intacct error'
    );

    $xmlApi->execute('<function controlid="test"/>');
  }

}
