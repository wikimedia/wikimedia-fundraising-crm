<?php

namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use PHPUnit\Framework\TestCase;
use SmashPig\PaymentProviders\Responses\CancelSubscriptionResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingProviderConfiguration;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 */
class CancelInactivesTest extends TestCase {

  /**
   * The tearDown() method is executed after the test was executed (optional).
   *
   * This can be used for cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    Contact::delete(FALSE)->addWhere('display_name', '=', 'Walter White')->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /**
   * Test inactive recurring contributions are cancelled.
   */
  public function testCancelInactive(): void {
    $contactID = Contact::create(FALSE)
      ->setValues(['first_name' => 'Walter', 'last_name' => 'White', 'contact_type' => 'Individual'])
      ->execute()->first()['id'];
    $contributionRecurID = ContributionRecur::create(FALSE)
      ->setValues([
        'amount' => 60,
        'contact_id' => $contactID,
        'start_date' => '100 days ago',
        'next_sched_contribution_date' => '70 days ago',
        'contribution_status_id:name' => 'Pending',
        'payment_processor_id:name' => 'paypal',
        'trxn_id' => 'ABCD1234'
      ])->execute()->first()['id'];

    $paymentProvider = $this->createMock('SmashPig\PaymentProviders\PayPal\PaymentProvider');
    $paymentProvider->expects($this->once())
      ->method('cancelSubscription')
      ->with(['subscr_id' => 'ABCD1234'])
      ->willReturn((new CancelSubscriptionResponse())->setSuccessful(TRUE));

    // Initialize SmashPig with a fake context object
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);
    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider(
      'paypal', $globalConfig
    );
    $ctx->providerConfigurationOverride = $providerConfig;
    $providerConfig->overrideObjectInstance('payment-provider/paypal', $paymentProvider);

    ContributionRecur::cancelInactives(FALSE)->execute();

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contributionRecurID)
      ->addSelect('contribution_status_id:name', 'cancel_reason', 'cancel_date')->execute()->first();

    $this->assertEquals('Automatically cancelled for inactivity', $contributionRecur['cancel_reason']);
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);
  }

}
