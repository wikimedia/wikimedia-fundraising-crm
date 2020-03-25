<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use Psr\Log\LogLevel;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\PaymentError;
use SmashPig\Core\UtcDate;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentData\ErrorCode;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\ApprovePaymentResponse;
use SmashPig\PaymentProviders\CreatePaymentResponse;
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
class CRM_SmashPigTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  private $oldSettings = [];

  private $oldPromPath;

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

  /** @var \SmashPig\PaymentProviders\CreatePaymentResponse */
  private $createPaymentResponse;

  /** @var \SmashPig\PaymentProviders\CreatePaymentResponse */
  private $createPaymentResponse2;

  /** @var \SmashPig\PaymentProviders\ApprovePaymentResponse */
  private $approvePaymentResponse;

  /** @var \SmashPig\PaymentProviders\ApprovePaymentResponse */
  private $approvePaymentResponse2;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    $this->createPaymentResponse = (new CreatePaymentResponse())
      ->setGatewayTxnId('000000850010000188130000200001')
      ->setStatus(FinalStatus::PENDING_POKE);
    $this->createPaymentResponse2 = (new CreatePaymentResponse())
      ->setGatewayTxnId('000000850010000188140000200001')
      ->setStatus(FinalStatus::PENDING_POKE);
    $this->approvePaymentResponse = (new ApprovePaymentResponse())
      ->setGatewayTxnId('000000850010000188130000200001')
      ->setStatus(FinalStatus::COMPLETE);
    $this->approvePaymentResponse2 = (new ApprovePaymentResponse())
      ->setGatewayTxnId('000000850010000188140000200001')
      ->setStatus(FinalStatus::COMPLETE);

    civicrm_initialize();
    $this->oldPromPath = variable_get('metrics_reporting_prometheus_path');
    variable_set('metrics_reporting_prometheus_path', '/tmp/');
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
      'PaymentProcessor', 'get', ['name' => $this->processorName, 'is_test' => 0]
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
        civicrm_api3($type, 'delete', ['id' => $id, 'skip_undelete' => TRUE]);
      }
    }
    foreach ($this->oldSettings as $setting => $value) {
      \Civi::settings()->set(
        $setting, $value
      );
    }
    variable_set('metrics_reporting_prometheus_path', $this->oldPromPath);
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
    $processor_id = mt_rand(10000, 100000000);
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
      'trxn_id' => 'RECURRING INGENICO ' . $processor_id,
      'processor_id' => $processor_id,
      'invoice_id' => mt_rand(10000, 10000000) . '.' . mt_rand(1, 20) . '|recur-' . mt_rand(100000, 100000000),
      'contribution_status_id' => 'Pending',
    ];
    $result = $this->callAPISuccess('ContributionRecur', 'create', $params);
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
      'payment_instrument' => 'Credit Card: MasterCard',
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
      ->with([
        'amount' => 12.34,
        'currency' => 'USD',
        'gateway_txn_id' => '000000850010000188130000200001',
      ])
      ->willReturn(
        $this->approvePaymentResponse
      );
    $result = $processor->doPayment($params);
    $this->assertEquals(
      '000000850010000188130000200001', $result['processor_id']
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
      ->with([
        'amount' => 12.34,
        'currency' => 'USD',
        'gateway_txn_id' => '000000850010000188130000200001',
      ])
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
    $this->assertEquals(
      'In Progress',
      CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
  }

  /**
   * Confirm that the first payment of a newly created recurring subscription
   * is processed as expected. Initially, recurring subscription payments were
   * treated as second (follow-on) payments in the series after the initial
   * payment and matched the amount of the first payment. The code should now
   * support an independent recurring subscription with its own payment amount
   * unconstrained by an earlier "first" donation.
   *
   * @throws CRM_Core_Exception
   * @throws CiviCRM_API3_Exception
   */
  public function testRecurringChargeJobFirstPayment() {
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '0'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);

    $contributionRecur = $this->createContributionRecur($token, [
      'installments' => 0,
      // I think this means installments taken so far?
      'amount' => 9.00
      // this is deliberately different from the below contribution amount
    ]);

    $contribution = $this->createContribution($contributionRecur, [
      'invoice_id' => $contributionRecur['invoice_id'],
      'contribution_recur_id' => NULL,
      'amount' => 12.00,
    ]);

    list($ctId, $expectedInvoiceId, $next) = $this->getExpectedIds($contribution);

    $expectedDescription = $this->getExpectedDescription();

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 9.00,
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
      ->with([
        'amount' => 9.00,
        'currency' => 'USD',
        'gateway_txn_id' => '000000850010000188130000200001',
      ])
      ->willReturn(
        $this->approvePaymentResponse
      );
    $result = civicrm_api3('Job', 'process_smashpig_recurring', ['debug' => 1]);
    $this->assertEquals(
      ['ids' => [$contributionRecur['id']]],
      $result['values']['success']
    );
    $contributions = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $contributionRecur['id'],
      'options' => ['sort' => 'id ASC'],
    ]);
    $this->assertEquals(1, count($contributions['values']));
    $contributionIds = array_keys($contributions['values']);
    $this->deleteThings['Contribution'][] = $contributionIds[0];
    $newContribution = $contributions['values'][$contributionIds[0]];
    $this->assertArraySubset([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'total_amount' => '9.00',
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
    $this->assertEquals(
      'In Progress',
      CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
  }

  /**
   * Confirm that a recurring payment job can find the previous contribution
   * using either contribution_recur_id or invoice_id. In some scenarios only
   * one of these values is present e.g. upsell vs standard recurring
   *
   * @see sites/default/civicrm/extensions/org.wikimedia.smashpig/CRM/Core/Payment/SmashPigRecurringProcessor.php:233
   */
  public function testRecurringChargeJobPreviousContributionLookupFallback() {
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '0'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '30'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);

    // create the new recurring subscription
    $contributionRecur = $this->createContributionRecur($token, [
      'installments' => 0,
      // i think this means installments taken so far?
      'amount' => 9.00
      // this is deliberately different from the below contribution amount
    ]);

    // create the original contribution that relates to the recurring subscription
    $contribution = $this->createContribution($contributionRecur, [
      'invoice_id' => $contributionRecur['invoice_id'],
      'contribution_recur_id' => NULL,
      'amount' => 12.00,
    ]);

    list($ctId, $firstInvoiceId, $secondInvoiceId) = $this->getExpectedIds($contribution);

    $expectedDescription = $this->getExpectedDescription();

    $this->hostedCheckoutProvider->expects($this->exactly(2))
      ->method('createPayment')
      ->withConsecutive([
        [
          'recurring_payment_token' => 'abc123-456zyx-test12',
          'amount' => 9.00,
          'currency' => 'USD',
          'first_name' => 'Harry',
          'last_name' => 'Henderson',
          'email' => 'harry@hendersons.net',
          'order_id' => $firstInvoiceId,
          'installment' => 'recurring',
          'description' => $expectedDescription,
          'recurring' => TRUE,
          'user_ip' => '12.34.56.78',
        ],
      ], [
        [
          'recurring_payment_token' => 'abc123-456zyx-test12',
          'amount' => 9.00,
          'currency' => 'USD',
          'first_name' => 'Harry',
          'last_name' => 'Henderson',
          'email' => 'harry@hendersons.net',
          'order_id' => $secondInvoiceId,
          'installment' => 'recurring',
          'description' => $expectedDescription,
          'recurring' => TRUE,
          'user_ip' => '12.34.56.78',
        ],
      ])
      ->will(
        $this->onConsecutiveCalls(
          $this->createPaymentResponse,
          $this->createPaymentResponse2
        )
      );

    $this->hostedCheckoutProvider->expects($this->exactly(2))
      ->method('approvePayment')
      ->withConsecutive(
        [[
          'amount' => 9.00,
          'currency' => 'USD',
          'gateway_txn_id' => '000000850010000188130000200001',
        ]],
        [[
          'amount' => 9.00,
          'currency' => 'USD',
          'gateway_txn_id' => '000000850010000188140000200001',
        ]])
      ->will(
        $this->onConsecutiveCalls(
          $this->approvePaymentResponse,
          $this->approvePaymentResponse2
        )
      );

    // trigger the recurring payment job to create the first payment
    // for the new subscription. this first payment will use `invoice_id`
    // internally to find the original contribution.
    $result = civicrm_api3('Job', 'process_smashpig_recurring', ['debug' => 1]);
    $this->assertEquals(
      ['ids' => [$contributionRecur['id']]],
      $result['values']['success']
    );

    // update the contribution_recur record to take the next payment now which
    // will confirm the previous contribution lookup by  kicks in.
    $params = [
      'id' => $contributionRecur['id'],
      'payment_processor_id' => $this->processorId,
      // FIXME: We're putting this 28 days in the past to fool the too-soon
      // contribution filter. Instead we should set this to now and update
      // the contribution record created in the previous run to set it to
      // 28 days earlier.
      'next_sched_contribution_date' => gmdate('Y-m-d H:i:s', strtotime('-28  days')),
    ];
    $this->callAPISuccess('ContributionRecur', 'create', $params);


    // trigger the recurring payment job to create the second payment
    // this second payment will use `contribution_recur_id` internally to find
    // the previous contribution.
    $result = civicrm_api3('Job', 'process_smashpig_recurring', ['debug' => 1]);
    $this->assertEquals(
      ['ids' => [$contributionRecur['id']]],
      $result['values']['success']
    );

    // confirm we have two successful contributions relating to the
    // recurring subscription
    $contributions = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $contributionRecur['id'],
      'options' => ['sort' => 'id ASC'],
    ]);

    $this->assertEquals(2, count($contributions['values']));
    $contributionIds = array_keys($contributions['values']);
    $this->deleteThings['Contribution'][] = $contributionIds[0];
    $this->deleteThings['Contribution'][] = $contributionIds[1];

    $latestContribution = $contributions['values'][$contributionIds[1]];
    $this->assertArraySubset([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'total_amount' => '9.00',
      'trxn_id' => '000000850010000188140000200001',
      'contribution_status' => 'Completed',
      'invoice_id' => $secondInvoiceId,
    ], $latestContribution);


    // check the next contribution date is at least 28 days further along
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $dateDiff = date_diff(
      new DateTime($contributionRecur['next_sched_contribution_date']),
      new DateTime($newContributionRecur['next_sched_contribution_date'])
    );
    $this->assertGreaterThanOrEqual(27, $dateDiff->days);
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
      'financial_type_id' => '1',
      'contribution_type_id' => '1',
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
    ], $contributionMessage);
  }

  public function testRecurringChargeJobFirstPaymentJobQueue() {
    // Now test it sending the donation to the queue
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '1'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);

    $contributionRecur = $this->createContributionRecur($token, [
      'installments' => 0,
      //installments taken so far (in this context)?
      'amount' => 9.00
      // this is deliberately different from the below contribution amount
    ]);

    $contribution = $this->createContribution($contributionRecur, [
      'invoice_id' => $contributionRecur['invoice_id'],
      'contribution_recur_id' => NULL,
      'amount' => 12.00,
    ]);


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
      'gross' => '9.00',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $expectedInvoiceId,
      'financial_type_id' => '1',
      'contribution_type_id' => '1',
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
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
      'currency' => 'EUR',
      'gross' => '11.22',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $expectedInvoiceId,
      'financial_type_id' => '1',
      'contribution_type_id' => '1',
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
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
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED,
        'No doughnuts for you!',
        LogLevel::ERROR
      )
    );
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

    $this->assertEquals(
      'Failing',
      CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
    $this->assertEquals('1', $newContributionRecur['failure_count']);
  }

  /**
   * When we get a DO_NOT_RETRY error code, we should cancel the subscription
   * immediately without waiting for 3 retries.
   */
  public function testPaymentFailsNoRetry() {
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $this->createContribution($contributionRecur);
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED_DO_NOT_RETRY,
        'Better not try me again!',
        LogLevel::ERROR
      )
    );
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
    $this->assertEquals('(auto) un-retryable card decline reason code', $newContributionRecur['cancel_reason']);
    $this->assertLessThan(100, abs($cancelDate - $expectedCancelDate));
    $this->assertEquals(
      'Cancelled',
      CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
  }

  /**
   * Test that the recurring is cancelled after maximum retries.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   */
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
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED,
        'No doughnuts for you!',
        LogLevel::ERROR
      )
    );
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
    $this->assertEquals('(auto) maximum failures reached', $newContributionRecur['cancel_reason']);
    $this->assertLessThan(100, abs($cancelDate - $expectedCancelDate));
    $this->assertEquals(
      $cancelledStatus, $newContributionRecur['contribution_status_id']
    );
    $this->assertEquals('3', $newContributionRecur['failure_count']);
  }

  /**
   * If the payment processor rejects our attempt because we're repeating the
   * order ID (aka merchant reference or invoice ID), we should retry with a
   * new ID.
   */
  public function testDuplicateOrderId() {
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $contribution = $this->createContribution($contributionRecur);
    list($ctId, $expectedInvoiceId, $nextInvoiceId) = $this->getExpectedIds($contribution);
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DUPLICATE_ORDER_ID,
        '{"code":"300620","requestId":"9126465":"message":"MERCHANTREFERENCE ' .
        $expectedInvoiceId . ' ALREADY EXISTS","httpStatusCode":409',
        LogLevel::ERROR
      )
    );
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
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('approvePayment')
      ->willReturn(
        $this->approvePaymentResponse
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
      'financial_type_id' => '1',
      'contribution_type_id' => '1',
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
    ], $contributionMessage);
  }

  /**
   * When a payment sent to the merchant fails, check that the amount of
   * failures are taken into account when generating the next InvoiceID
   */
  public function testRecurringChargeWithPreviousFailedAttempts() {
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '0'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);

    // Have one previous payment failed
    $overrides['failure_count'] = 1;

    $contributionRecur = $this->createContributionRecur($token,$overrides);
    $contribution = $this->createContribution($contributionRecur);

    // Get the expected invoice ids taking into account the failures
    list($ctId, $expectedInvoiceId, $next) = $this->getExpectedIds($contribution,$contributionRecur['failure_count']);

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
      ->with([
        'amount' => 12.34,
        'currency' => 'USD',
        'gateway_txn_id' => '000000850010000188130000200001',
      ])
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

    // Check the invoice Ids
    $this->assertEquals($expectedInvoiceId, $newContribution['invoice_id']);
  }

  /**
   * @param $contribution
   * @param $failures
   *
   * @return array
   */
  protected function getExpectedIds($contribution,$failures = 0) {
    $originalInvoiceId = $contribution['invoice_id'];
    $parts = explode('|', $originalInvoiceId);
    list($ctId, $sequence) = explode('.', $parts[0]);
    $sequence = $sequence + $failures;
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
