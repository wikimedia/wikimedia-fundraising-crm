<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;

/**
 * Tests for SmashPig payment processor extension
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or
 * test****() functions will rollback automatically -- as long as you don't
 * manipulate schema or truncate tables. If this test needs to manipulate
 * schema or truncate tables, then either: a. Do all that using setupHeadless()
 * and Civi\Test. b. Disable TransactionalInterface, and handle all
 * setup/teardown yourself.
 *
 * @group SmashPig
 * @group headless
 */
class CRM_SmashPigTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, TransactionalInterface {

  private $oldSettings = [];

  /**
   * @var PHPUnit_Framework_MockObject_MockObject
   */
  private $hostedCheckoutProvider;

  private $processorName = 'testSmashPig';

  private $processorId;

  private $deleteThings = [
    'Contribution' => [],
    'ContributionRecur' => [],
    'PaymentToken' => [],
    'PaymentProcessor' => [],
    'Contact' => [],
  ];

  private $createPaymentResponse = [
    'creationOutput' => [
      'additionalReference' => '123455.2',
      'externalReference' => '123455.2',
    ],
    'payment' => [
      'id' => '000000850010000188130000200001',
      'paymentOutput' => [
        'amountOfMoney' => [
          'amount' => 1234,
          'currencyCode' => 'USD',
        ],
        'references' => [
          'merchantReference' => '123455.2',
          'paymentReference' => '0',
        ],
        'paymentMethod' => 'card',
        'cardPaymentMethodSpecificOutput' => [
          'paymentProductId' => 1,
          'authorisationCode' => '726747',
          'card' => [
            'cardNumber' => '************7977',
            'expiryDate' => '1220',
          ],
          'fraudResults' => [
            'avsResult' => '0',
            'cvvResult' => '0',
            'fraudServiceResult' => 'no-advice',
          ],
        ],
      ],
      'status' => 'PENDING_APPROVAL',
      'statusOutput' => [
        'isCancellable' => TRUE,
        'statusCode' => 600,
        'statusCodeChangeDateTime' => '20180522154830',
        'isAuthorized' => TRUE,
      ],
    ],
  ];

  private $approvePaymentResponse = [
    'payment' => [
      'id' => '000000850010000188130000200001',
      'paymentOutput' => [
        'amountOfMoney' => [
          'amount' => 1234,
          'currencyCode' => 'USD',
        ],
        'references' => [
          'paymentReference' => '0',
        ],
        'paymentMethod' => 'card',
        'cardPaymentMethodSpecificOutput' => [
          'paymentProductId' => 1,
          'authorisationCode' => '123456',
          'card' => [
            'cardNumber' => '************7977',
            'expiryDate' => '1220',
          ],
          'fraudResults' => [
            'avsResult' => '0',
            'cvvResult' => 'M',
            'fraudServiceResult' => 'no-advice',
          ],
        ],
      ],
      'status' => 'CAPTURE_REQUESTED',
      'statusOutput' => [
        'isCancellable' => FALSE,
        'statusCode' => 800,
        'statusCodeChangeDateTime' => '20180627140735',
        'isAuthorized' => TRUE,
      ],
    ],
  ];

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    if (!isset($GLOBALS['_PEAR_default_error_mode'])) {
      // This is simply to protect against e-notices if globals have been reset by phpunit.
      $GLOBALS['_PEAR_default_error_mode'] = NULL;
      $GLOBALS['_PEAR_default_error_options'] = NULL;
    }
    civicrm_initialize();
    $smashPigSettings = civicrm_api3('setting', 'getfields', [
      'filters' => ['group' => 'smashpig'],
    ]);
    foreach ($smashPigSettings['values'] as $setting) {
      $this->oldSettings[$setting['name']] = \Civi::settings()
        ->get($setting['name']);
    }
    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);

    $existing = civicrm_api3(
      'PaymentProcessor', 'get', ['name' => $this->processorName]
    );
    if ($existing['values']) {
      $this->processorId = $existing['id'];
    }
    else {
      $processor = $this->createPaymentProcessor();
      $this->processorId = $processor['id'];
    }
    $ctx = TestingContext::get();

    $providerConfig = TestingProviderConfiguration::createForProvider(
      'ingenico', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    $this->hostedCheckoutProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\Ingenico\HostedCheckoutProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance('payment-provider/cc', $this->hostedCheckoutProvider);
  }

  public function tearDown() {
    foreach ($this->deleteThings as $type => $ids) {
      foreach ($ids as $id) {
        civicrm_api3($type, 'delete', ['id' => $id]);
      }
    }
    foreach ($this->oldSettings as $setting => $value) {
      \Civi::settings()->set(
        $setting, $value
      );
    }
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    Context::set(); // Nullify the context for next run.
    parent::tearDown();
  }

  private function createPaymentProcessor() {
    $typeRecord = civicrm_api3(
      'PaymentProcessorType', 'getSingle', ['name' => 'smashpig_ingenico']
    );
    $accountType = key(CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL,
      " AND v.name = 'Asset' "));
    $query = "
        SELECT id
        FROM   civicrm_financial_account
        WHERE  is_default = 1
        AND    financial_account_type_id = {$accountType}
      ";
    $financialAccountId = CRM_Core_DAO::singleValueQuery($query);
    $params = [];
    $params['payment_processor_type_id'] = $typeRecord['id'];
    $params['name'] = $this->processorName;
    $params['domain_id'] = CRM_Core_Config::domainID();
    $params['is_active'] = TRUE;
    $params['financial_account_id'] = $financialAccountId;
    $result = civicrm_api3('PaymentProcessor', 'create', $params);
    $this->deleteThings['PaymentProcessor'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  private function createToken($contactId) {
    $result = civicrm_api3('PaymentToken', 'create', [
      'contact_id' => $contactId,
      'payment_processor_id' => $this->processorId,
      'token' => 'abc123-456zyx-test12',
      'ip_address' => '12.34.56.78',
    ]);
    $this->deleteThings['PaymentToken'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  private function createContact() {
    $result = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Harry',
      'last_name' => 'Henderson',
      'email' => 'harry@hendersons.net',
      'preferred_language' => 'en_US',
    ]);
    $this->deleteThings['Contact'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  private function createContributionRecur($token, $overrides = []) {
    gmdate('Y-m-d H:i:s', strtotime('-12 hours'));
    $params = $overrides + [
      'contact_id' => $token['contact_id'],
      'amount' => 12.34,
      'currency' => 'USD',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'installments' => 1,
      'start_date' => gmdate('Y-m-d H:i:s', strtotime('-1 month')),
      'create_date' => gmdate('Y-m-d H:i:s', strtotime('-1 month')),
      'payment_token_id' => $token['id'],
      'cancel_date' => NULL,
      'cycle_day' => gmdate('d', strtotime('-12 hours')),
      'payment_processor_id' => $this->processorId,
      'next_sched_contribution_date' => gmdate('Y-m-d H:i:s', strtotime('-12 hours')),
      'trxn_id' => 'RECURRING INGENICO ' . mt_rand(10000, 100000000),
      'contribution_status_id' => 'Completed',
    ];
    $result = civicrm_api3('ContributionRecur', 'create', $params);
    $this->deleteThings['ContributionRecur'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  private function createContribution($contributionRecur, $overrides = []) {
    $params = $overrides + [
      'contact_id' => $contributionRecur['contact_id'],
      'currency' => 'USD',
      'total_amount' => 12.34,
      'contribution_recur_id' => $contributionRecur['id'],
      'receive_date' => date('Y-m-d H:i:s', strtotime('-1 month')),
      'trxn_id' => $contributionRecur['trxn_id'],
      'financial_type_id' => 1,
      'invoice_id' => mt_rand(10000, 10000000) . '.' . mt_rand(1, 20) . '|recur-' . mt_rand(100000, 100000000),
      'skipRecentView' => 1,
    ];
    $result = civicrm_api3('Contribution', 'create', $params);
    $this->deleteThings['Contribution'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  /**
   * Test that doDirectPayment makes the right API calls
   */
  public function testDoDirectPayment() {
    $processor = Civi\Payment\System::singleton()
      ->getById($this->processorId);
    $processor->setPaymentProcessor(civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $this->processorId]));
    $params = [
      'amount' => 12.34,
      'currency' => 'USD',
      'invoice_id' => '123455.2',
      'is_recur' => TRUE,
      'description' => 'wonderful happy fun money',
      'token' => 'abc123-456zyx-test12',
      'installment' => 'recurring',
      'ip_address' => '33.22.33.11',
    ];
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 12.34,
        'currency' => 'USD',
        'order_id' => '123455.2',
        'installment' => 'recurring',
        'description' => 'wonderful happy fun money',
        'recurring' => TRUE,
        'user_ip' => '33.22.33.11',
      ])
      ->willReturn(
        $this->createPaymentResponse
      );
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->with('000000850010000188130000200001')
      ->willReturn(
        $this->approvePaymentResponse
      );
    $result = $processor->doPayment($params);
    $this->assertEquals(
      '000000850010000188130000200001', $result['trxn_id']
    );
    $status = CRM_Contribute_PseudoConstant::contributionStatus($result['payment_status_id']);
    $this->assertEquals('Completed', $status);
  }

  public function testRecurringChargeJob() {
    // First test it directly inserting the new contribution
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '0'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $contribution = $this->createContribution($contributionRecur);

    list($ctId, $expectedInvoiceId, $next) = $this->getExpectedIds($contribution);

    $expectedDescription = $this->getExpectedDescription();

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 12.34,
        'currency' => 'USD',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'order_id' => $expectedInvoiceId,
        'installment' => 'recurring',
        'description' => $expectedDescription,
        'recurring' => TRUE,
        'user_ip' => '12.34.56.78',
      ])
      ->willReturn(
        $this->createPaymentResponse
      );
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->with('000000850010000188130000200001')
      ->willReturn(
        $this->approvePaymentResponse
      );
    $result = civicrm_api3('Job', 'process_smashpig_recurring', []);
    $this->assertEquals(
      ['ids' => [$contributionRecur['id']]],
      $result['values']['success']
    );
    $contributions = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $contributionRecur['id'],
      'options' => ['sort' => 'id ASC'],
    ]);
    $this->assertEquals(2, count($contributions['values']));
    $contributionIds = array_keys($contributions['values']);
    $this->deleteThings['Contribution'][] = $contributionIds[1];
    $newContribution = $contributions['values'][$contributionIds[1]];
    $this->assertArraySubset([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'total_amount' => '12.34',
      'trxn_id' => '000000850010000188130000200001',
      'contribution_status' => 'Completed',
      'invoice_id' => $expectedInvoiceId,
    ], $newContribution);
    // Check the updated date is at least 28 days further along
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $dateDiff = date_diff(
      new DateTime($contributionRecur['next_sched_contribution_date']),
      new DateTime($newContributionRecur['next_sched_contribution_date'])
    );
    $this->assertGreaterThanOrEqual(27, $dateDiff->days);
    $this->assertEquals(2, $newContributionRecur['installments']);
    $this->assertEquals(
      $contributionRecur['contribution_status_id'],
      $newContributionRecur['contribution_status_id']
    );
  }

  public function testRecurringChargeJobQueue() {
    // Now test it sending the donation to the queue
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '1'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $contribution = $this->createContribution($contributionRecur);
    list($ctId, $expectedInvoiceId, $next) = $this->getExpectedIds($contribution);
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn(
        $this->createPaymentResponse
      );
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn(
        $this->approvePaymentResponse
      );
    civicrm_api3('Job', 'process_smashpig_recurring', []);
    $queue = QueueWrapper::getQueue('donations');
    $contributionMessage = $queue->pop();
    $this->assertNull($queue->pop(), 'Queued too many donations!');
    SourceFields::removeFromMessage($contributionMessage);
    $expectedDate = UtcDate::getUtcTimestamp();
    $actualDate = $contributionMessage['date'];
    $this->assertLessThan(100, abs($actualDate - $expectedDate));
    unset($contributionMessage['date']);
    $this->assertEquals([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'gross' => '12.34',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $expectedInvoiceId,
      'effort_id' => 2,
      'financial_type_id' => '1',
      'contribution_type_id' => '1',
      'payment_instrument_id' => '4',
      'gateway' => 'ingenico',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
    ], $contributionMessage);
  }

  public function testRecurringChargeNonUsd() {
    // Make sure we charge in original currency
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '1'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    // Recurring records gets original-currency amount
    $contributionRecur = $this->createContributionRecur($token, [
      'currency' => 'EUR',
      'amount' => '11.22',
    ]);
    // Contribution table gets converted USD amount
    $contribution = $this->createContribution($contributionRecur);
    list($ctId, $expectedInvoiceId, $next) = $this->getExpectedIds($contribution);
    $expectedDescription = $this->getExpectedDescription();
    $createResponse = $this->createPaymentResponse;
    $createResponse['payment']['paymentOutput']['amountOfMoney'] = [
      'amount' => 1122,
      'currencyCode' => 'EUR',
    ];
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 11.22,
        'currency' => 'EUR',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'order_id' => $expectedInvoiceId,
        'installment' => 'recurring',
        'description' => $expectedDescription,
        'recurring' => TRUE,
        'user_ip' => '12.34.56.78',
      ])
      ->willReturn(
        $createResponse
      );
    $approveResponse = $this->approvePaymentResponse;
    $approveResponse['payment']['paymentOutput']['amountOfMoney'] = [
      'amount' => 1122,
      'currencyCode' => 'EUR',
    ];
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn(
        $approveResponse
      );
    civicrm_api3('Job', 'process_smashpig_recurring', []);
    $queue = QueueWrapper::getQueue('donations');
    $contributionMessage = $queue->pop();
    $this->assertNull($queue->pop(), 'Queued too many donations!');
    SourceFields::removeFromMessage($contributionMessage);
    $expectedDate = UtcDate::getUtcTimestamp();
    $actualDate = $contributionMessage['date'];
    $this->assertLessThan(100, abs($actualDate - $expectedDate));
    unset($contributionMessage['date']);
    $this->assertEquals([
      'contact_id' => $contact['id'],
      'currency' => 'EUR',
      'gross' => '11.22',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $expectedInvoiceId,
      'effort_id' => 2,
      'financial_type_id' => '1',
      'contribution_type_id' => '1',
      'payment_instrument_id' => '4',
      'gateway' => 'ingenico',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
    ], $contributionMessage);
  }

  public function testPaymentFails() {
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $this->createContribution($contributionRecur);
    $response = $this->createPaymentResponse;
    $response['errors'] = [
      'blahblah',
    ];
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn(
        $response
      );
    $processor = new CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1
    );
    $processor->run();
    $queue = QueueWrapper::getQueue('donations');
    $this->assertNull($queue->pop(), 'Should not have queued a donation!');
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $expectedRetryDate = UtcDate::getUtcTimestamp('+1 days');
    $retryDate = UtcDate::getUtcTimestamp(
      $newContributionRecur['next_sched_contribution_date']
    );
    $this->assertLessThan(100, abs($retryDate - $expectedRetryDate));
    $statuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $failedStatus = CRM_Utils_Array::key('Failed', $statuses);
    $this->assertEquals(
      $failedStatus, $newContributionRecur['contribution_status_id']
    );
    $this->assertEquals('1', $newContributionRecur['failure_count']);
  }

  public function testMaxFailures() {
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $statuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $failedStatus = CRM_Utils_Array::key('Failed', $statuses);
    $cancelledStatus = CRM_Utils_Array::key('Cancelled', $statuses);
    civicrm_api3('ContributionRecur', 'create', [
      'id' => $contributionRecur['id'],
      'contribution_status_id' => $failedStatus,
      'failure_count' => 2,
    ]);
    $this->createContribution($contributionRecur);
    $response = $this->createPaymentResponse;
    $response['errors'] = [
      'blahblah',
    ];
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn(
        $response
      );
    $processor = new CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1
    );
    $processor->run();
    $queue = QueueWrapper::getQueue('donations');
    $this->assertNull($queue->pop(), 'Should not have queued a donation!');
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $expectedCancelDate = UtcDate::getUtcTimestamp();
    $cancelDate = UtcDate::getUtcTimestamp(
      $newContributionRecur['cancel_date']
    );
    $this->assertLessThan(100, abs($cancelDate - $expectedCancelDate));
    $this->assertEquals(
      $cancelledStatus, $newContributionRecur['contribution_status_id']
    );
    $this->assertEquals('3', $newContributionRecur['failure_count']);
  }

  public function testError300620() {
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $contribution = $this->createContribution($contributionRecur);
    list($ctId, $expectedInvoiceId, $nextInvoiceId) = $this->getExpectedIds($contribution);
    $response = $this->createPaymentResponse;
    $response['errors'] = [
      [
        'code' => '300620',
        'requestId' => '9126465',
        'message' => "MERCHANTREFERENCE $expectedInvoiceId ALREADY EXISTS",
        'httpStatusCode' => 409,
      ],
    ];
    $expectedDescription = $this->getExpectedDescription();
    $firstCallParams = [
      'recurring_payment_token' => 'abc123-456zyx-test12',
      'amount' => '12.34',
      'currency' => 'USD',
      'first_name' => 'Harry',
      'last_name' => 'Henderson',
      'email' => 'harry@hendersons.net',
      'order_id' => $expectedInvoiceId,
      'installment' => 'recurring',
      'description' => $expectedDescription,
      'recurring' => TRUE,
      'user_ip' => '12.34.56.78',
    ];
    $secondCallParams = [
      'recurring_payment_token' => 'abc123-456zyx-test12',
      'amount' => '12.34',
      'currency' => 'USD',
      'first_name' => 'Harry',
      'last_name' => 'Henderson',
      'email' => 'harry@hendersons.net',
      'order_id' => $nextInvoiceId,
      'installment' => 'recurring',
      'description' => $expectedDescription,
      'recurring' => TRUE,
      'user_ip' => '12.34.56.78',
    ];
    $this->hostedCheckoutProvider->expects($this->exactly(2))
      ->method('createPayment')
      ->withConsecutive(
        [$firstCallParams],
        [$secondCallParams]
      )
      ->willReturn(
        $response,
        $this->createPaymentResponse
      );
    $processor = new CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1
    );
    $processor->run();
    $queue = QueueWrapper::getQueue('donations');
    $contributionMessage = $queue->pop();
    $this->assertNull($queue->pop(), 'Queued too many donations!');
    SourceFields::removeFromMessage($contributionMessage);
    unset($contributionMessage['date']);
    $this->assertEquals([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'gross' => '12.34',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $nextInvoiceId,
      'effort_id' => 2,
      'financial_type_id' => '1',
      'contribution_type_id' => '1',
      'payment_instrument_id' => '4',
      'gateway' => 'ingenico',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
    ], $contributionMessage);
  }

  /**
   * @param $contribution
   *
   * @return array
   */
  protected function getExpectedIds($contribution) {
    $originalInvoiceId = $contribution['invoice_id'];
    $parts = explode('|', $originalInvoiceId);
    list($ctId, $sequence) = explode('.', $parts[0]);
    $expectedInvoiceId = $ctId . '.' . ($sequence + 1);
    $nextInvoiceId = $ctId . '.' . ($sequence + 2);
    return [$ctId, $expectedInvoiceId, $nextInvoiceId];
  }

  /**
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getExpectedDescription() {
    $domain = CRM_Core_BAO_Domain::getDomain();
    $expectedDescription = "Monthly donation to $domain->name";
    return $expectedDescription;
  }
}
