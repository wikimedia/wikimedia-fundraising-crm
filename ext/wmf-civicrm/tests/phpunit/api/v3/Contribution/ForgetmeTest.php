<?php

use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use SmashPig\Core\ApiException;
use SmashPig\Core\PaymentError;
use SmashPig\PaymentData\ErrorCode;
use SmashPig\PaymentProviders\Adyen\CardPaymentProvider;
use SmashPig\PaymentProviders\Responses\DeleteDataResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingProviderConfiguration;

class api_v3_Contribution_ForgetmeTest extends TestCase {
  use EntityTrait;
  use WMFEnvironmentTrait;

  /**
   * Adyen data is deleted per contribution, failures send one failmail without aborting, not-found is listed separately.
   */
  public function testForgetmeDeletesAdyenDataAndAlertsOnFailure(): void {
    $ctx = TestingContext::get();
    $providerConfig = TestingProviderConfiguration::createForProvider('adyen', $ctx->getGlobalConfiguration());
    $adyenProvider = $this->getMockBuilder(CardPaymentProvider::class)->disableOriginalConstructor()->getMock();
    $providerConfig->overrideObjectInstance('payment-provider/cc', $adyenProvider);
    $ctx->providerConfigurationOverride = $providerConfig;

    $adyenProvider->expects($this->exactly(6))
      ->method('deleteDataForPayment')
      ->willReturnCallback(function (string $gatewayTxnId) {
        if ($gatewayTxnId === 'FAILS') {
          throw new ApiException('Adyen is down');
        }
        if ($gatewayTxnId === 'NOT_FOUND') {
          return (new DeleteDataResponse())->setSuccessful(FALSE)->addErrors(
            new PaymentError(ErrorCode::TRANSACTION_NOT_FOUND, 'PSP reference NOT_FOUND not found at Adyen', LogLevel::ERROR)
          );
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
    $this->createContribution([
      'contact_id' => $contactID,
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 'NOT_FOUND',
    ], 'not_found');

    $result = civicrm_api3('Contribution', 'forgetme', ['contact_id' => $contactID]);

    $this->assertEquals(1, $result['count']);
    $this->assertEquals([$this->ids['Contribution']['not_found']], $result['not_found']);
    $this->assertLoggedAlertThatContains("contribution {$this->ids['Contribution']['fails']} (contact $contactID, FAILS): Adyen is down");

    $result = civicrm_api3('Contact', 'forgetme', ['id' => $contactID]);

    $this->assertArrayHasKey('Contribution' . $this->ids['Contribution']['not_found'], $result['not_found']);
    $this->assertArrayHasKey('Contribution' . $this->ids['Contribution']['succeeds'], $result['values']);
    $this->assertArrayNotHasKey('Contribution' . $this->ids['Contribution']['not_found'], $result['values']);
  }

}
