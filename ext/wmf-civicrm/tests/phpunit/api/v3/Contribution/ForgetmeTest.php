<?php

use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\ApiException;
use SmashPig\PaymentProviders\Adyen\CardPaymentProvider;
use SmashPig\PaymentProviders\Responses\DeleteDataResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingProviderConfiguration;

class api_v3_Contribution_ForgetmeTest extends TestCase {
  use EntityTrait;
  use WMFEnvironmentTrait;

  /**
   * Adyen data is deleted per contribution and failures send one failmail without aborting.
   */
  public function testForgetmeDeletesAdyenDataAndAlertsOnFailure(): void {
    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider('adyen', $ctx->getGlobalConfiguration());
    $adyenProvider = $this->getMockBuilder(CardPaymentProvider::class)->disableOriginalConstructor()->getMock();
    $providerConfig->overrideObjectInstance('payment-provider/cc', $adyenProvider);
    $ctx->providerConfigurationOverride = $providerConfig;

    $adyenProvider->expects($this->exactly(2))
      ->method('deleteDataForPayment')
      ->willReturnCallback(function (string $gatewayTxnId) {
        if ($gatewayTxnId === 'FAILS') {
          throw new ApiException('Adyen is down');
        }
        return (new DeleteDataResponse())->setSuccessful(TRUE);
      });

    $contactID = $this->createIndividual();
    $this->createContribution([
      'contact_id' => $contactID,
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 'SUCCEEDS',
    ], 'succeeds');
    $this->createContribution([
      'contact_id' => $contactID,
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 'FAILS',
    ], 'fails');

    $result = civicrm_api3('Contribution', 'forgetme', ['contact_id' => $contactID]);

    $this->assertEquals(1, $result['count']);
    $this->assertLoggedAlertThatContains("contribution {$this->ids['Contribution']['fails']} (contact $contactID, FAILS): Adyen is down");
  }

}
