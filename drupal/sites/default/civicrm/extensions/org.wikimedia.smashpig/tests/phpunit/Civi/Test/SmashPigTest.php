<?php

namespace Civi\Test;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Psr\Log\LogLevel;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\PaymentError;
use SmashPig\Core\UtcDate;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentData\ErrorCode;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\ApprovePaymentResponse;
use SmashPig\PaymentProviders\Responses\CreatePaymentResponse;
use SmashPig\PaymentProviders\Responses\CreatePaymentWithProcessorRetryResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;
use Civi\Api4\Activity;
use Civi;

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
class SmashPigTest extends SmashPigBaseTestClass {

  private $oldSettings = [];

  private $oldPromPath;

  /**
   * @var PHPUnit_Framework_MockObject_MockObject
   */
  private $hostedCheckoutProvider;

  /** @var \SmashPig\PaymentProviders\Responses\CreatePaymentResponse */
  private $createPaymentResponse;

  /** @var \SmashPig\PaymentProviders\Responses\CreatePaymentResponse */
  private $createPaymentResponse2;

  /** @var \SmashPig\PaymentProviders\Responses\ApprovePaymentResponse */
  private $approvePaymentResponse;

  /** @var \SmashPig\PaymentProviders\Responses\ApprovePaymentResponse */
  private $approvePaymentResponse2;

  /**
   * Setup for test.
   *
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    parent::setUp();
    $this->createPaymentResponse = (new CreatePaymentResponse())
      ->setGatewayTxnId('000000850010000188130000200001')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE);
    $this->createPaymentResponse2 = (new CreatePaymentResponse())
      ->setGatewayTxnId('000000850010000188140000200001')
      ->setStatus(FinalStatus::PENDING_POKE)
      ->setSuccessful(TRUE);
    $this->approvePaymentResponse = (new ApprovePaymentResponse())
      ->setGatewayTxnId('000000850010000188130000200001')
      ->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE);
    $this->approvePaymentResponse2 = (new ApprovePaymentResponse())
      ->setGatewayTxnId('000000850010000188140000200001')
      ->setStatus(FinalStatus::COMPLETE)
      ->setSuccessful(TRUE);

    $this->oldPromPath = \Civi::settings()->get('metrics_reporting_prometheus_path');
    \Civi::settings()->set('metrics_reporting_prometheus_path', '/tmp/');
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

  /**
   * Post test cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    foreach ($this->oldSettings as $setting => $value) {
      \Civi::settings()->set(
        $setting, $value
      );
    }
    \Civi::settings()->set('metrics_reporting_prometheus_path', $this->oldPromPath);
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    Context::set(); // Nullify the context for next run.
    parent::tearDown();
  }

  /**
   * Test that doDirectPayment makes the right API calls
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \CRM_Core_Exception
   */
  public function testDoDirectPayment(): void {
    $processor = Civi\Payment\System::singleton()
      ->getById($this->getPaymentProcessorID());
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
    $this->assertEquals('Completed', $result['payment_status']);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function testRecurringChargeJob(): void {
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
    $giftData = [
      // Restrictions field
      'Gift_Data.Fund' => 'Unrestricted_General',
      // Gift Source field.
      'Gift_Data.Campaign' => 'Online Gift',
      // Direct Mail Appeal field
      'Gift_Data.Appeal' => 'Mobile Giving',
    ];
    $contribution = $this->createContribution($contributionRecur, $giftData);

    [, $expectedInvoiceId] = $this->getExpectedIds($contribution);

    $expectedDescription = $this->getExpectedDescription();

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 12.34,
        'country' => 'US',
        'currency' => 'USD',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'order_id' => $expectedInvoiceId,
        'installment' => 'recurring',
        'description' => $expectedDescription,
        'processor_contact_id' => $contributionRecur['invoice_id'],
        'fiscal_number' => '1122334455',
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
    $contributions = Contribution::get(FALSE)
      ->addSelect('*', 'Gift_Data.*', 'contribution_status_id:name')
      ->addWhere('contribution_recur_id', '=', $contributionRecur['id'])
      ->addOrderBy('id')->execute();
    $this->assertCount(2, $contributions);
    $newContribution = $contributions[1];

    foreach ([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'total_amount' => '12.34',
      'trxn_id' => '000000850010000188130000200001',
      'contribution_status_id:name' => 'Completed',
      'invoice_id' => $expectedInvoiceId,
       // https://phabricator.wikimedia.org/T345920
       // Restrictions field
       'Gift_Data.Fund' => 'Unrestricted_General',
       // Gift Source field.
       'Gift_Data.Campaign' => 'Online Gift',
       // Direct Mail Appeal field
       'Gift_Data.Appeal' => 'Mobile Giving',
     ] as $key => $value) {
      $this->assertEquals($value, $newContribution[$key]);
    }
    // Check the updated date is at least 28 days further along
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $dateDiff = date_diff(
      new \DateTime($contributionRecur['next_sched_contribution_date']),
      new \DateTime($newContributionRecur['next_sched_contribution_date'])
    );
    $this->assertGreaterThanOrEqual(27, $dateDiff->days);
    $this->assertEquals(
      'In Progress',
      \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
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
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function testRecurringChargeJobFirstPayment(): void {
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

    [, $expectedInvoiceId] = $this->getExpectedIds($contribution);

    $expectedDescription = $this->getExpectedDescription();

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 9.00,
        'country' => 'US',
        'currency' => 'USD',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'order_id' => $expectedInvoiceId,
        'installment' => 'recurring',
        'description' => $expectedDescription,
        'processor_contact_id' => $contributionRecur['invoice_id'],
        'fiscal_number' => '1122334455',
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
    $newContribution = $contributions['values'][$contributionIds[0]];
    foreach ([
               'contact_id' => $contact['id'],
               'currency' => 'USD',
               'total_amount' => '9.00',
               'trxn_id' => '000000850010000188130000200001',
               'contribution_status' => 'Completed',
               'invoice_id' => $expectedInvoiceId,
             ] as $key => $value) {
      $this->assertEquals($value, $newContribution[$key]);
    }
    // Check the updated date is at least 28 days further along
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $dateDiff = date_diff(
      new \DateTime($contributionRecur['next_sched_contribution_date']),
      new \DateTime($newContributionRecur['next_sched_contribution_date'])
    );
    $this->assertGreaterThanOrEqual(27, $dateDiff->days);
    $this->assertEquals(
      'In Progress',
      \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
  }

  /**
   * Confirm that a recurring payment job can find the previous contribution
   * using either contribution_recur_id or invoice_id. In some scenarios only
   * one of these values is present e.g. upsell vs standard recurring
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   *
   * @see sites/default/civicrm/extensions/org.wikimedia.smashpig/CRM/Core/Payment/SmashPigRecurringProcessor.php:233
   */
  public function testRecurringChargeJobPreviousContributionLookupFallback(): void {
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

    [
      $ctId,
      $firstInvoiceId,
      $secondInvoiceId,
    ] = $this->getExpectedIds($contribution);

    $expectedDescription = $this->getExpectedDescription();

    $this->hostedCheckoutProvider->expects($this->exactly(2))
      ->method('createPayment')
      ->withConsecutive([
        [
          'recurring_payment_token' => 'abc123-456zyx-test12',
          'amount' => 9.00,
          'country' => 'US',
          'currency' => 'USD',
          'first_name' => 'Harry',
          'last_name' => 'Henderson',
          'email' => 'harry@hendersons.net',
          'order_id' => $firstInvoiceId,
          'installment' => 'recurring',
          'description' => $expectedDescription,
          'processor_contact_id' => $contributionRecur['invoice_id'],
          'fiscal_number' => '1122334455',
          'recurring' => TRUE,
          'user_ip' => '12.34.56.78',
        ],
      ], [
        [
          'recurring_payment_token' => 'abc123-456zyx-test12',
          'amount' => 9.00,
          'country' => 'US',
          'currency' => 'USD',
          'first_name' => 'Harry',
          'last_name' => 'Henderson',
          'email' => 'harry@hendersons.net',
          'order_id' => $secondInvoiceId,
          'installment' => 'recurring',
          'description' => $expectedDescription,
          'processor_contact_id' => $contributionRecur['invoice_id'],
          'fiscal_number' => '1122334455',
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
        [
          [
            'amount' => 9.00,
            'currency' => 'USD',
            'gateway_txn_id' => '000000850010000188130000200001',
          ],
        ],
        [
          [
            'amount' => 9.00,
            'currency' => 'USD',
            'gateway_txn_id' => '000000850010000188140000200001',
          ],
        ])
      ->will(
        $this->onConsecutiveCalls(
          $this->approvePaymentResponse,
          $this->approvePaymentResponse2
        )
      );

    // trigger the recurring payment job to create the first payment
    // for the new subscription. this first payment will use `invoice_id`
    // internally to find the original contribution.
    $result = $this->callAPISuccess('Job', 'process_smashpig_recurring', ['debug' => 1]);
    $this->assertEquals(
      ['ids' => [$contributionRecur['id']]],
      $result['values']['success']
    );

    // update the contribution_recur record to take the next payment now which
    // will confirm the previous contribution lookup by  kicks in.
    $params = [
      'id' => $contributionRecur['id'],
      'payment_processor_id.name' => $this->processorName,
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
    $result = $this->callAPISuccess('Job', 'process_smashpig_recurring', ['debug' => 1]);
    $this->assertEquals(
      ['ids' => [$contributionRecur['id']]],
      $result['values']['success']
    );

    // confirm we have two successful contributions relating to the
    // recurring subscription
    $contributions = $this->callAPISuccess('Contribution', 'get', [
      'contribution_recur_id' => $contributionRecur['id'],
      'options' => ['sort' => 'id ASC'],
    ]);

    $this->assertCount(2, $contributions['values']);
    $contributionIds = array_keys($contributions['values']);

    $latestContribution = $contributions['values'][$contributionIds[1]];
    foreach ([
               'contact_id' => $contact['id'],
               'currency' => 'USD',
               'total_amount' => '9.00',
               'trxn_id' => '000000850010000188140000200001',
               'contribution_status' => 'Completed',
               'invoice_id' => $secondInvoiceId,
             ] as $key => $value) {
      $this->assertEquals($value, $latestContribution[$key]);
    }

    // check the next contribution date is at least 28 days further along
    $newContributionRecur = $this->callAPISuccess('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $dateDiff = date_diff(
      new \DateTime($contributionRecur['next_sched_contribution_date']),
      new \DateTime($newContributionRecur['next_sched_contribution_date'])
    );
    $this->assertGreaterThanOrEqual(27, $dateDiff->days);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \SmashPig\Core\ConfigurationKeyException
   */
  public function testRecurringChargeJobQueue(): void {
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
    [$ctId, $expectedInvoiceId] = $this->getExpectedIds($contribution);
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
    $this->callAPISuccess('Job', 'process_smashpig_recurring', []);
    $queue = QueueWrapper::getQueue('donations');
    $contributionMessage = $queue->pop();
    $this->assertNull($queue->pop(), 'Queued too many donations!');
    SourceFields::removeFromMessage($contributionMessage);
    $expectedDate = UtcDate::getUtcTimestamp();
    $actualDate = $contributionMessage['date'];
    $this->assertLessThan(100, abs($actualDate - $expectedDate));
    unset($contributionMessage['date']);
    $financialType = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift - Cash");

    $this->assertEquals([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'gross' => 12.34,
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $expectedInvoiceId,
      'financial_type_id' => $financialType,
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
      'restrictions' => NULL,
      'gift_source' => NULL,
      'direct_mail_appeal' => NULL,
    ], $contributionMessage);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringChargeJobFirstPaymentJobQueue(): void {
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


    [$ctId, $expectedInvoiceId, $next] = $this->getExpectedIds($contribution);
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
    $this->callAPISuccess('Job', 'process_smashpig_recurring', []);
    $queue = QueueWrapper::getQueue('donations');
    $contributionMessage = $queue->pop();
    $this->assertNull($queue->pop(), 'Queued too many donations!');
    SourceFields::removeFromMessage($contributionMessage);
    $expectedDate = UtcDate::getUtcTimestamp();
    $actualDate = $contributionMessage['date'];
    $this->assertLessThan(100, abs($actualDate - $expectedDate));
    unset($contributionMessage['date']);
    $financialType = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift");

    $this->assertEquals([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'gross' => '9.00',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $expectedInvoiceId,
      'financial_type_id' => $financialType,
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
      'restrictions' => NULL,
      'gift_source' => NULL,
      'direct_mail_appeal' => NULL,
    ], $contributionMessage);
  }

  /**
   * Ensure that Initial Scheme Transaction Id is passed to payment from custom
   * field
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function testRecurringChargeJobInitialSchemeId() {
    // Use the queue rather than table insert to simplify cleanup
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '1'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token, [
      'contribution_recur_smashpig.initial_scheme_transaction_id' => 'ABC123YouAndMe',
    ]);
    $contribution = $this->createContribution($contributionRecur);

    [$ctId, $expectedInvoiceId, $next] = $this->getExpectedIds($contribution);

    $expectedDescription = $this->getExpectedDescription();

    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 12.34,
        'country' => 'US',
        'currency' => 'USD',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'order_id' => $expectedInvoiceId,
        'installment' => 'recurring',
        'description' => $expectedDescription,
        'processor_contact_id' => $contributionRecur['invoice_id'],
        'fiscal_number' => '1122334455',
        'recurring' => TRUE,
        'user_ip' => '12.34.56.78',
        'initial_scheme_transaction_id' => 'ABC123YouAndMe',
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
  }


  /**
   * @throws \CRM_Core_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   */
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
    [$ctId, $expectedInvoiceId, $next] = $this->getExpectedIds($contribution);
    $expectedDescription = $this->getExpectedDescription();
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 11.22,
        'country' => 'US',
        'currency' => 'EUR',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'order_id' => $expectedInvoiceId,
        'installment' => 'recurring',
        'description' => $expectedDescription,
        'processor_contact_id' => $contributionRecur['invoice_id'],
        'fiscal_number' => '1122334455',
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
    $this->callAPISuccess('Job', 'process_smashpig_recurring', []);
    $queue = QueueWrapper::getQueue('donations');
    $contributionMessage = $queue->pop();
    $this->assertNull($queue->pop(), 'Queued too many donations!');
    SourceFields::removeFromMessage($contributionMessage);
    $expectedDate = UtcDate::getUtcTimestamp();
    $actualDate = $contributionMessage['date'];
    $this->assertLessThan(100, abs($actualDate - $expectedDate));
    unset($contributionMessage['date']);
    $financialType = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift - Cash");
    $this->assertEquals([
      'contact_id' => $contact['id'],
      'currency' => 'EUR',
      'gross' => '11.22',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $expectedInvoiceId,
      'financial_type_id' => $financialType,
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
      'restrictions' => NULL,
      'gift_source' => NULL,
      'direct_mail_appeal' => NULL,
    ], $contributionMessage);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   */
  public function testPaymentFails() {
    $contributionRecur = $this->setupRecurring();
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED,
        'No doughnuts for you!',
        LogLevel::ERROR
      )
    )->setSuccessful(FALSE);
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn(
        $response
      );
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
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
      \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
    $this->assertEquals('1', $newContributionRecur['failure_count']);
  }

  /**
   * When we get a DO_NOT_RETRY error code, we should cancel the subscription
   * immediately without waiting for 3 retries.
   *
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \CRM_Core_Exception
   */
  public function testPaymentFailsNoRetry() {
    $contributionRecur = $this->setupAndFailRecurring();
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
      \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
  }

  /**
   * When we use adyen auto rescue, and get additionalInfo response with
   * retry.rescueScheduled false, we should cancel the subscription immediately
   * without count failed retries.
   *
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \CRM_Core_Exception
   */
  public function testPaymentAutoRescueFailed() {
    $contributionRecur = $this->setupRecurring();
    $orderId = $contributionRecur['invoice_id'];
    $msg = [
        'merchantReference' => $orderId,
        'pspReference' => 'testPspReference',
        'resultCode' => 'Refused',
        'success' => false,
        'refusalReason' => 'Issuer Suspected Fraud',
        'additionalData' => [
          'retry.rescueScheduled' => 'false',
          'retry.rescueReference' => null,
      ],
    ];
    $response = (new CreatePaymentWithProcessorRetryResponse())
      ->setRawResponse($msg)
      ->setSuccessful(FALSE)
      ->addErrors(
        new PaymentError(
          ErrorCode::DECLINED,
          'Issuer Suspected Fraud',
          LogLevel::ERROR
        ))
      ->setProcessorRetryRefusalReason('Issuer Suspected Fraud')
      ->setIsProcessorRetryScheduled(filter_var( $msg['additionalData']['retry.rescueScheduled'], FILTER_VALIDATE_BOOLEAN ));
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn($response);

    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();
    $queue = QueueWrapper::getQueue('donations');
    $this->assertNull($queue->pop(), 'Should not have queued a donation!');
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);
    $expectedCancelDate = UtcDate::getUtcTimestamp();
    $this->assertEquals(UtcDate::getUtcTimestamp(
      $contributionRecur['next_sched_contribution_date']
    ), UtcDate::getUtcTimestamp(
      $newContributionRecur['next_sched_contribution_date']
    ));
    $cancelDate = UtcDate::getUtcTimestamp(
      $newContributionRecur['cancel_date']
    );
    $this->assertEquals('Payment cannot be rescued: Issuer Suspected Fraud', $newContributionRecur['cancel_reason']);
    $this->assertLessThan(100, abs($cancelDate - $expectedCancelDate));
    $this->assertEquals(
      'Cancelled',
      \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $newContributionRecur['contribution_status_id'])
    );
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testFailureEmailNotSentOnFirstFailedPayment() {
    Civi::settings()->set('smashpig_recurring_send_failure_email', 1);

    // add contact
    $contact = $this->createContact();

    // add recurring charge that's about to fail for the first time
    $token = $this->createToken((int) $contact['id']);
    $contributionRecur = $this->createContributionRecur($token, [
      'failure_count' => 0,
    ]);
    $this->createContribution($contributionRecur);

    // set up our fail response
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED,
        "That's your first declined payment!",
        LogLevel::ERROR
      )
    )->setSuccessful(FALSE);
    $this->hostedCheckoutProvider->expects($this->any())
      ->method('createPayment')
      ->willReturn(
        $response
      );

    // run the recurring processor job
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();

    $contributionRecurRecord = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);

    // check the recurring record is now set as failing
    $this->assertEquals(
      'Failing',
      \CRM_Core_PseudoConstant::getName(
        'CRM_Contribute_BAO_ContributionRecur',
        'contribution_status_id',
        $contributionRecurRecord['contribution_status_id']
      )
    );

    // check the failure count was increased
    $this->assertEquals('1', $contributionRecurRecord['failure_count']);

    // confirm no email was sent by checking the activity log
    $activity = $this->getLatestFailureMailActivity((int) $contributionRecurRecord['id']);
    $this->assertNull($activity);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testFailureEmailNotSentOnSecondFailedPayment(): void {
    Civi::settings()->set('smashpig_recurring_send_failure_email', 1);

    // add contact
    $contact = $this->createContact();

    // add recurring charge that's about to fail for the second time
    $token = $this->createToken((int) $contact['id']);
    $contributionRecur = $this->createContributionRecur($token, [
      'failure_count' => 1,
    ]);
    $this->createContribution($contributionRecur);

    // set up our fail response
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED,
        "That's your second declined payment!",
        LogLevel::ERROR
      )
    )->setSuccessful(FALSE);
    $this->hostedCheckoutProvider->expects($this->any())
      ->method('createPayment')
      ->willReturn(
        $response
      );

    // run the recurring processor job
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();

    $contributionRecurRecord = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);

    // check the recurring record is now set as failing
    $this->assertEquals(
      'Failing',
      \CRM_Core_PseudoConstant::getName(
        'CRM_Contribute_BAO_ContributionRecur',
        'contribution_status_id',
        $contributionRecurRecord['contribution_status_id']
      )
    );

    // check the failure count was increased
    $this->assertEquals('2', $contributionRecurRecord['failure_count']);

    // confirm no email was sent by checking the activity log
    $activity = $this->getLatestFailureMailActivity((int) $contributionRecurRecord['id']);
    $this->assertNull($activity);
  }

  /**
   * Recurring failure emails should be sent after three consecutive
   * failed payment attempts.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testFailureEmailSentOnThirdFailedPayment() {
    Civi::settings()->set('smashpig_recurring_send_failure_email', 1);

    // let's overwrite the failure email template to make it easier to
    // compare the email body contents when checking for the sent email later.
    $this->setupFailureTemplate();

    // add contact
    $contact = $this->createContact();

    // add recurring charge that's about to fail for the third time
    $token = $this->createToken((int) $contact['id']);
    $contributionRecur = $this->createContributionRecur($token, [
      'failure_count' => 2,
    ]);
    $this->createContribution($contributionRecur);

    // set up our fail response
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED_DO_NOT_RETRY,
        "That's your third declined payment!",
        LogLevel::ERROR
      )
    )->setSuccessful(FALSE);
    $this->hostedCheckoutProvider->expects($this->any())
      ->method('createPayment')
      ->willReturn(
        $response
      );

    // run the recurring processor job
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();

    $contributionRecurRecord = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur['id'],
    ]);

    // check the recurring record is now set as Cancelled
    $this->assertEquals(
      'Cancelled',
      \CRM_Core_PseudoConstant::getName(
        'CRM_Contribute_BAO_ContributionRecur',
        'contribution_status_id',
        $contributionRecurRecord['contribution_status_id']
      )
    );

    // check the failure count was increased to three
    $this->assertEquals('3', $contributionRecurRecord['failure_count']);

    // confirm email was SENT by checking the activity log
    $activity = $this->getLatestFailureMailActivity((int) $contributionRecurRecord['id']);
    $month = date('F');
    $expectedMessage = "Dear Harry,
      We cancelled your recur of USD $12.34
      and we are sending you this at harry@hendersons.net
      this month of $month
      $12.34";
    $this->assertEquals($expectedMessage, $activity['details']);
  }

  /**
   *
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \CRM_Core_Exception
   */
  public function testFailureEmailNotSentIfOtherActiveRecurringExists(): void {
    Civi::settings()->set('smashpig_recurring_send_failure_email', 1);

    // add contact
    $contact = $this->createContact();

    // add old recurring that's about to be charged
    $token1 = $this->createToken((int) $contact['id']);
    $contributionRecur1 = $this->createContributionRecur($token1, [
      'start_date' => gmdate('Y-m-d H:i:s', strtotime('-6 month')),
      'create_date' => gmdate('Y-m-d H:i:s', strtotime('-6 month')),
      // set this to 1 month in the past so it doesn't get run
      'next_sched_contribution_date' => gmdate('Y-m-d H:i:s', strtotime('-12 hours')),
    ]);
    $this->createContribution($contributionRecur1);

    // add second (newer) recurring that's already been charged
    $this->createToken((int) $contact['id']);
    $contributionRecur2 = $this->createContributionRecur($token1, [
      'start_date' => gmdate('Y-m-d H:i:s', strtotime('-14 days')),
      'create_date' => gmdate('Y-m-d H:i:s', strtotime('-14 days')),
      'next_sched_contribution_date' => gmdate('Y-m-d H:i:s', strtotime('+14 days')),
      'trxn_id' => 3456789,
    ]);
    $this->createContribution($contributionRecur2);

    // set up our fail DO NOT RETRY response for when our old recurring
    // is charged. We could see this when a card is stopped due to
    // fraud or has expired
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED_DO_NOT_RETRY,
        'Better not try me again!',
        LogLevel::ERROR
      )
    )->setSuccessful(FALSE);
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn(
        $response
      );

    // run the recurring processor job
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();

    // confirm the charge failed and was cancelled
    $checkContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $contributionRecur1['id'],
    ]);
    $this->assertEquals('(auto) un-retryable card decline reason code', $checkContributionRecur['cancel_reason']);
    $this->assertEquals(
      'Cancelled',
      \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $checkContributionRecur['contribution_status_id'])
    );

    // confirm no email was sent by checking the activity log
    // Note: typically an email WOULD be sent but as we have
    // another active recurring donation we suppress the email and confirm it here
    $activity = Activity::get()->setCheckPermissions(FALSE)
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('subject', 'LIKE', 'Recur fail message : %')
      ->addWhere('source_record_id', '=', $contributionRecur1['id'])
      ->addOrderBy('activity_date_time', 'DESC')
      ->execute()->first();

    $this->assertNull($activity);

  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testCancelMessage() {
    $this->setupFailureTemplate();
    $contributionRecur = $this->setupAndFailRecurring();
    $activity = $this->getLatestFailureMailActivity((int) $contributionRecur['id']);
    $month = date('F');
    $expectedMessage = "Dear Harry,
      We cancelled your recur of USD $12.34
      and we are sending you this at harry@hendersons.net
      this month of $month
      $12.34";
    $this->assertEquals($expectedMessage, $activity['details']);
  }

  /**
   * Test that the recurring is cancelled after maximum retries.
   *
   * @throws \CRM_Core_Exception
   *
   * @throws \PHPQueue\Exception\JobNotFoundException
   */
  public function testMaxFailures(): void {
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $statuses = \CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $failedStatus = \CRM_Utils_Array::key('Failed', $statuses);
    $cancelledStatus = \CRM_Utils_Array::key('Cancelled', $statuses);
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
    )->setSuccessful(FALSE);
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn(
        $response
      );
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
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
   *
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \CRM_Core_Exception
   */
  public function testDuplicateOrderId(): void {
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $contribution = $this->createContribution($contributionRecur);
    [
      $ctId,
      $expectedInvoiceId,
      $nextInvoiceId,
    ] = $this->getExpectedIds($contribution);
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DUPLICATE_ORDER_ID,
        '{"code":"300620","requestId":"9126465":"message":"MERCHANTREFERENCE ' .
        $expectedInvoiceId . ' ALREADY EXISTS","httpStatusCode":409',
        LogLevel::ERROR
      )
    )->setSuccessful(FALSE);
    $expectedDescription = $this->getExpectedDescription();
    $firstCallParams = [
      'recurring_payment_token' => 'abc123-456zyx-test12',
      'amount' => '12.34',
      'country' => 'US',
      'currency' => 'USD',
      'first_name' => 'Harry',
      'last_name' => 'Henderson',
      'email' => 'harry@hendersons.net',
      'order_id' => $expectedInvoiceId,
      'installment' => 'recurring',
      'description' => $expectedDescription,
      'processor_contact_id' => $contributionRecur['invoice_id'],
      'fiscal_number' => '1122334455',
      'recurring' => TRUE,
      'user_ip' => '12.34.56.78',
    ];
    $secondCallParams = [
      'recurring_payment_token' => 'abc123-456zyx-test12',
      'amount' => '12.34',
      'country' => 'US',
      'currency' => 'USD',
      'first_name' => 'Harry',
      'last_name' => 'Henderson',
      'email' => 'harry@hendersons.net',
      'order_id' => $nextInvoiceId,
      'installment' => 'recurring',
      'description' => $expectedDescription,
      'processor_contact_id' => $contributionRecur['invoice_id'],
      'fiscal_number' => '1122334455',
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
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();
    $queue = QueueWrapper::getQueue('donations');
    $contributionMessage = $queue->pop();
    $this->assertNull($queue->pop(), 'Queued too many donations!');
    SourceFields::removeFromMessage($contributionMessage);
    unset($contributionMessage['date']);
    $financialType = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift - Cash");
    $this->assertEquals([
      'contact_id' => $contact['id'],
      'currency' => 'USD',
      'gross' => '12.34',
      'gateway_txn_id' => '000000850010000188130000200001',
      'invoice_id' => $nextInvoiceId,
      'financial_type_id' => $financialType,
      'payment_instrument_id' => '4',
      'gateway' => 'testSmashPig',
      'payment_method' => 'cc',
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_tracking_id' => $ctId,
      'recurring' => TRUE,
      'restrictions' => NULL,
      'gift_source' => NULL,
      'direct_mail_appeal' => NULL,
    ], $contributionMessage);
  }

  /**
   * When a payment sent to the merchant fails, check that the amount of
   * failures are taken into account when generating the next InvoiceID
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringChargeWithPreviousFailedAttempts(): void {
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '0'
    );
    $contact = $this->createContact();
    $token = $this->createToken($contact['id']);

    // Have one previous payment failed
    $overrides['failure_count'] = 1;

    $contributionRecur = $this->createContributionRecur($token, $overrides);
    $contribution = $this->createContribution($contributionRecur);

    // Get the expected invoice ids taking into account the failures
    [
      $ctId,
      $expectedInvoiceId,
      $next,
    ] = $this->getExpectedIds($contribution, $contributionRecur['failure_count']);

    $expectedDescription = $this->getExpectedDescription();
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->with([
        'recurring_payment_token' => 'abc123-456zyx-test12',
        'amount' => 12.34,
        'country' => 'US',
        'currency' => 'USD',
        'first_name' => 'Harry',
        'last_name' => 'Henderson',
        'email' => 'harry@hendersons.net',
        'order_id' => $expectedInvoiceId,
        'installment' => 'recurring',
        'description' => $expectedDescription,
        'processor_contact_id' => $contributionRecur['invoice_id'],
        'fiscal_number' => '1122334455',
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
    $result = $this->callAPISuccess('Job', 'process_smashpig_recurring', []);
    $this->assertEquals(
      ['ids' => [$contributionRecur['id']]],
      $result['values']['success']
    );
    $contributions = $this->callAPISuccess('Contribution', 'get', [
      'contribution_recur_id' => $contributionRecur['id'],
      'options' => ['sort' => 'id ASC'],
    ]);
    $this->assertCount(2, $contributions['values']);
    $contributionIds = array_keys($contributions['values']);
    $newContribution = $contributions['values'][$contributionIds[1]];
    foreach ([
               'contact_id' => $contact['id'],
               'currency' => 'USD',
               'total_amount' => '12.34',
               'trxn_id' => '000000850010000188130000200001',
               'contribution_status' => 'Completed',
               'invoice_id' => $expectedInvoiceId,
             ] as $key => $value) {
      $this->assertEquals($value, $newContribution[$key]);
    }

    // Check the invoice Ids
    $this->assertEquals($expectedInvoiceId, $newContribution['invoice_id']);
  }

  /**
   * @param $contribution
   * @param $failures
   *
   * @return array
   */
  protected function getExpectedIds($contribution, $failures = 0): array {
    $originalInvoiceId = $contribution['invoice_id'];
    $parts = explode('|', $originalInvoiceId);
    [$ctId, $sequence] = explode('.', $parts[0]);
    $sequence += $failures;
    $expectedInvoiceId = $ctId . '.' . ($sequence + 1);
    $nextInvoiceId = $ctId . '.' . ($sequence + 2);
    return [$ctId, $expectedInvoiceId, $nextInvoiceId];
  }

  /**
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getExpectedDescription(): string {
    return $settings = Civi::settings()->get(
      'smashpig_recurring_charge_descriptor'
    );
  }

  /**
   * Set up a recurring payment and process a failed payment.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function setupAndFailRecurring(): array {
    $contributionRecur = $this->setupRecurring();
    $response = (new CreatePaymentResponse())->addErrors(
      new PaymentError(
        ErrorCode::DECLINED_DO_NOT_RETRY,
        'Better not try me again!',
        LogLevel::ERROR
      )
    )->setSuccessful(FALSE);
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn(
        $response
      );
    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();
    return $contributionRecur;
  }

  /**
   * We add recurring payment information to the pending queue when auto rescue
   * transaction is initialised
   *
   * @throws \PHPQueue\Exception\JobNotFoundException
   * @throws \CRM_Core_Exception
   */
  public function testPaymentAutoRescueInitialised(): void {
    $contributionRecur = $this->setupRecurring();
    $orderId = $contributionRecur['invoice_id'];
    $pspReference = 'testPspReference';
    $response = (new CreatePaymentWithProcessorRetryResponse())->setRawResponse(
      [
        'merchantReference' => $orderId,
        'pspReference' => $pspReference,
        'resultCode' => 'Refused',
        'refusalReason' => 'NOT_ENOUGH_BALANCE',
        'additionalData' => [
          'retry.rescueScheduled' => 'true',
          'retry.rescueReference' => 'testRescueReference',
        ],
      ]
    )->setGatewayTxnId($pspReference)
      ->setIsProcessorRetryScheduled(TRUE)
      ->setSuccessful(FALSE)
      ->addErrors(
        new PaymentError(
          ErrorCode::DECLINED,
          'Not Enough Balance',
          LogLevel::ERROR
        ));
    $this->hostedCheckoutProvider->expects($this->once())
      ->method('createPayment')
      ->willReturn($response);

    $processor = new \CRM_Core_Payment_SmashPigRecurringProcessor(
      TRUE, 1, 3, 1, 1, $this->getExpectedDescription()
    );
    $processor->run();

    $updatedRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contributionRecur['id'])
      ->setSelect([
        'contribution_status_id:name',
        'next_sched_contribution_date'
      ])->execute()->first();
    $this->assertEquals('Pending', $updatedRecur['contribution_status_id:name']);
    $this->assertGreaterThanOrEqual(
      new \DateTime('+10 days'),
      new \DateTime($updatedRecur['next_sched_contribution_date'])
    );
  }

}
