<?php

namespace Civi\Test;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Civi\WMFAudit\BaseAuditTestCase;
use SmashPig\Core\Context;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\Responses\ApprovePaymentResponse;
use SmashPig\PaymentProviders\Responses\CreatePaymentResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingDatabase;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;
use Civi\WMFQueueTrait;

/**
 * Tests for Fundraiseup migration token charge
 *
 * @todo - remove this soon - should be obsolete now? or during last 3 months of 2025.
 * @group SmashPig
 * @group headless
 */
class FundraiseupMigrationTokenChargeTest extends BaseAuditTestCase {
  use WMFQueueTrait;

  /**
   * @var PHPUnit_Framework_MockObject_MockObject
   */
  private $gravyProvider;

  /**
   * @var \SmashPig\PaymentProviders\Responses\CreatePaymentResponse*/
  private $createPaymentResponse;

  /**
   * @var \SmashPig\PaymentProviders\Responses\ApprovePaymentResponse*/
  private $approvePaymentResponse;

  protected $processorName = 'gravy';

  /**
   * Setup for test.
   *
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    $this->gateway = '';
    parent::setUp();

    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);

    $ctx = TestingContext::get();

    $providerConfig = TestingProviderConfiguration::createForProvider(
      'gravy', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;

    $this->gravyProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\Gravy\PaymentProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance('payment-provider/cc', $this->gravyProvider);
  }

  /**
   * Post test cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    // Reset some SmashPig-specific things
    TestingDatabase::clearStatics();
    // Nullify the context for next run.
    Context::set();
    parent::tearDown();
  }

  /**
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function testRecurringChargeJobFirstPaymentAfterTokenImports(): void {
    \Civi::settings()->set(
      'smashpig_recurring_use_queue', '0'
    );
    \Civi::settings()->set(
      'smashpig_recurring_catch_up_days', '1'
    );

    $contribution = $this->createFundraiseupContribution();

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', $contribution['subscr_id'])
      ->execute()->first();
    $token = 'abc123-456zyx-test12';
    $buyerId = 'random-buyer';
    $tokenObj = PaymentToken::create(FALSE)
      ->addValue('token', $token)
      ->addValue('payment_processor_id.name', 'gravy')
      ->addValue('contact_id', $contributionRecur['contact_id'])
      ->addValue('ip_address', '12.34.56.78')
      ->execute()->first();
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $contributionRecur['id'])
      ->addValue('payment_token_id', $tokenObj['id'])
      ->addValue('contribution_recur_smashpig.processor_contact_id', $buyerId)
      ->addValue('next_sched_contribution_date', '2025-09-14 01:32:00PM')
      ->execute();

    $this->gravyProvider->expects($this->once())
      ->method('createPayment')
      ->with(
        $this->callback(function($params) use ($tokenObj, $buyerId) {
            $this->assertEquals($params['order_id'], '1.1');
            $this->assertEquals($params['recurring_payment_token'], $tokenObj['token']);
            $this->assertEquals($params['processor_contact_id'], $buyerId);
            return TRUE;
        })
      )
      ->willReturn(
        (new CreatePaymentResponse())
          ->setGatewayTxnId('000000850010000188130000200001')
          ->setStatus(FinalStatus::PENDING_POKE)
          ->setSuccessful(TRUE)
      );
    $this->gravyProvider->expects($this->once())
      ->method('approvePayment')

      ->willReturn(
        (new ApprovePaymentResponse())
          ->setGatewayTxnId('000000850010000188130000200001')
          ->setStatus(FinalStatus::COMPLETE)
          ->setSuccessful(TRUE)
      );
    $result = civicrm_api3('Job', 'process_smashpig_recurring', [
      'debug' => 1,
      'contribution_recur_id' => $contributionRecur['id'],
    ]);

    $contributions = Contribution::get(FALSE)
      ->addWhere('contribution_recur_id', '=', $contributionRecur['id'])
      ->addOrderBy('receive_date', 'ASC')
      ->execute();

    $this->assertEquals(2, count($contributions));
    $this->assertEquals(
      $contributions[1]['invoice_id'],
      '1.1'
    );
  }

  /**
   * @param array $contributionRecur
   * @param array $overrides
   *
   * @return array
   */
  protected function createFundraiseupContribution(): array {
    $fundraiseupImport = [
      'gateway' => 'fundraiseup',
      'gross' => '5.64',
      'currency' => 'USD',
      'order_id' => 'DQZQFCJS',
      'gateway_txn_id' => 'ch_3NrmZLJaRQOHTfEW0zGlJw1Z',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'date' => 1695063200,
      'user_ip' => '127.0.0.1',
      'first_name' => 'Jimmy',
      'last_name' => 'Mouse',
      'street_address' => '',
      'city' => '',
      'country' => 'GB',
      'email' => 'jwales@example.org',
      'external_identifier' => 'SUBJJCQA',
      'invoice_id' => 'DQZQFCJS',
      'gateway_account' => 'Wikimedia Foundation',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'original_currency' => 'GBP',
      'original_gross' => '4.60',
      'fee' => 0.61,
      'recurring' => '1',
      'subscr_id' => 'RCGCEFBA',
      'start_date' => '2023-09-18T18:53:20.676Z',
      'employer' => '',
      'street_number' => '',
      'postal_code' => '',
      'state_province' => '',
      'language' => 'en-US',
      'utm_medium' => 'spontaneous',
      'utm_source' => 'fr-redir',
      'utm_campaign' => 'spontaneous',
      'no_thank_you' => 'Fundraiseup import',
      'type' => 'donations',
    ];
    $this->processDonationMessage($fundraiseupImport);
    return $fundraiseupImport;
  }

}
