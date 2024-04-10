<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\WMFException\WMFException;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;
use Civi\WMFHelper\ContributionRecur as RecurHelper;

/**
 * @group Queue2Civicrm
 */
class RefundQueueTest extends BaseQueueTestCase {

  protected string $queueName = 'refund';

  protected string $queueConsumer = 'Refund';

  public function testRefund(): void {
    $donation_message = $this->getDonationMessage([], ['USD' => 1, '*' => 3]);
    $refund_message = $this->getRefundMessage(['gateway_parent_id' => $donation_message['gateway_txn_id']]);

    $this->processMessage($donation_message, 'Donation', 'test');
    $this->assertOneContributionExistsForMessage($donation_message);

    $this->processMessage($refund_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  public function testRefundNoPredecessor(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::MISSING_PREDECESSOR);
    $this->processMessageWithoutQueuing($this->getRefundMessage());
  }

  public function testRefundEmptyRequiredField(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::CIVI_REQ_FIELD);
    $refund_message = $this->getRefundMessage(['gross' => '']);
    $this->processMessageWithoutQueuing($refund_message);
  }

  /**
   * Test refunding a mismatched amount.
   *
   * Note that we were checking against an exception - but it turned out the
   * exception could be thrown in this fn $this->queueConsumer->processMessage
   * if the exchange rate does not exist - which is not what we are testing
   * for.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRefundMismatched(): void {
    $donation_message = $this->getDonationMessage(['gateway' => 'test_gateway']);

    $this->processMessage($donation_message, 'Donation', 'test');
    $this->assertOneContributionExistsForMessage($donation_message);

    $this->processMessage($this->getRefundMessage([
      'gross' => $donation_message['original_gross'] + 1,
      'gateway_parent_id' => $donation_message['gateway_txn_id'],
      'gateway' => 'test_gateway',
    ]));
    $contribution = $this->getContributionForMessage($donation_message);
    $this->assertEquals(
      'Chargeback',
      $contribution['contribution_status_id:name']
    );
    $adjustmentContribution = Contribution::get(FALSE)->addWhere('contact_id', '=', $contribution['contact_id'])
      ->addWhere('id', '<>', $contribution['id'])
      ->execute()->single();

    $this->assertEquals(-.5, $adjustmentContribution['total_amount']);
  }

  /**
   * Refunds raised by Paypal do not indicate whether the initial
   * payment was taken using the paypal express checkout (paypal_ec) integration or
   * the legacy paypal integration (paypal). We try to work this out by checking for
   * the presence of specific values in messages sent over, but it appears this
   * isn't watertight as we've seen refunds failing due to incorrect mappings
   * on some occasions.
   *
   * To mitigate this we now fall back to the alternative gateway if no match is
   * found for the gateway supplied.
   */
  public function testPaypalExpressFallback(): void {
    // add a paypal_ec donation
    $donation_message = $this->getDonationMessage(['gateway' => 'paypal_ec']);
    $this->processMessage($donation_message, 'Donation', 'test');

    // simulate a mis-mapped paypal legacy refund
    $this->processMessage($this->getRefundMessage(
      [
        'gateway' => 'paypal',
        'gateway_parent_id' => $donation_message['gateway_txn_id'],
        'gross' => $donation_message['original_gross'] + 1,
      ]
    ));
    $this->assertOneContributionExistsForMessage($donation_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  /**
   * @see testPaypalExpressFallback
   */
  public function testPaypalLegacyFallback(): void {
    // add a paypal legacy donation
    $donation_message = $this->getDonationMessage(['gateway' => 'paypal']);
    $this->processMessage($donation_message, 'Donation', 'test');

    // simulate a mis-mapped paypal_ec refund
    $this->processMessage($this->getRefundMessage(
      [
        'gateway' => 'paypal_ec',
        'gateway_parent_id' => $donation_message['gateway_txn_id'],
        'gross' => $donation_message['original_gross'] + 1,
      ]
    ));
    $this->assertOneContributionExistsForMessage($donation_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  /**
   * Ensure that Civi core code (CRM_Contribute_BAO_ContributionRecur::updateOnTemplateUpdated)
   * does not edit contribution_recur rows to match the currency and amount of an associated
   * contribution when the contribution is edited.
   *
   * We used to implement a hook to run interference on core
   * behaviour but the core behaviour is now fixed so we are testing that.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRefundDoesNotChangeRecurCurrency(): void {
    $initialDonation = [
      'gateway_txn_id' => 'TEST-1234',
      'contribution_tracking_id' => 13,
      'utm_source' => '..rcc',
      'language' => 'en',
      'email' => 'jwales@example.com',
      'first_name' => 'Jimmy',
      'last_name' => 'Wales',
      'country' => 'US',
      'gateway' => 'adyen',
      'order_id' => '13.1',
      'recurring' => '1',
      'payment_method' => 'cc',
      'payment_submethod' => 'discover',
      'currency' => 'EUR',
      'gross' => '10.00',
      'user_ip' => '172.18.0.1',
      'recurring_payment_token' => 'DB44P92T43M84H82',
      'processor_contact_id' => '13.1',
      'date' => 1669082766,
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
    ];
    $this->setExchangeRates(1669082766, [
      'USD' => 1,
      'EUR' => 1.1,
    ]);

    $this->processMessage($initialDonation, 'Donation', 'test');

    // Import will convert the contribution to USD but leave the contribution_recur as EUR
    $contribution = $this->getContributionForMessage($initialDonation);
    $this->assertEquals('USD', $contribution['currency']);
    $this->assertEquals('EUR', $contribution['contribution_recur_id.currency']);
    $refundMessage = [
      'type' => 'refund',
      'date' => 1669082866,
      'gateway' => 'adyen',
      'gateway_parent_id' => 'TEST-1234',
      'gateway_refund_id' => 'TEST-1234',
      'gross' => 10.00,
      'gross_currency' => 'EUR',
    ];
    $this->processMessage($refundMessage);
    // Make sure that the recurring record's currency is unchanged
    $newRecurRecord = ContributionRecur::get(FALSE)->addWhere('id', '=', $contribution['contribution_recur_id'])->execute()->single();
    $this->assertEquals('EUR', $newRecurRecord['currency']);
  }

  /**
   * Test refunding a mismatched refund currency.
   */
  public function testRefundMismatchedRefundCurrency(): void {
    $donation_message = $this->getDonationMessage(['gateway' => 'test_gateway']);
    $this->processMessage($donation_message, 'Donation', 'test');
    $refund_message = $this->getRefundMessage([
      'gateway' => 'test_gateway',
      'gateway_parent_id' => $donation_message['gateway_txn_id'],
      'gross' => $donation_message['original_gross'] * 0.5,
      'gross_currency' => 'USD',
    ]);

    $this->processMessage($refund_message);
    $this->assertOneContributionExistsForMessage($donation_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  /**
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \CRM_Core_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   */
  public function testChargebackRecurring(): void {
    $signupMessage = $this->getRecurringSignupMessage();
    $this->processMessage($signupMessage, 'Recurring', 'recurring');
    $recurRecord = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', $signupMessage['subscr_id'])
      ->execute()->single();
    $this->ids['ContributionRecur'][] = $recurRecord['id'];
    $donationMessage = $this->getDonationMessage([
      'gateway' => 'test_gateway',
      'contribution_recur_id' => $recurRecord['id'],
    ], []);
    $this->processMessage($donationMessage, 'Donation', 'test');

    $refundMessage = $this->getRefundMessage([
      'gateway' => 'test_gateway',
      'gateway_parent_id' => $donationMessage['gateway_txn_id'],
      'type' => 'chargeback',
      'gross' => $donationMessage['original_gross'],
      'gross_currency' => $donationMessage['original_currency'],
    ]);
    $this->processMessage($refundMessage);
    $cancelMessage = QueueWrapper::getQueue('recurring')->pop();
    SourceFields::removeFromMessage($cancelMessage);
    $this->assertArrayHasKey('payment_instrument_id', $cancelMessage);
    unset($cancelMessage['payment_instrument_id']);
    $this->assertEquals(
      [
        'contribution_recur_id' => $recurRecord['id'],
        'txn_type' => 'subscr_cancel',
        'cancel_reason' => 'Automatically cancelling because we received a chargeback',
      ],
      $cancelMessage
    );
  }

  /**
   * @param array $values
   *
   * @return array
   */
  public function getRefundMessage(array $values = []): array {
    $donation_message = $this->getDonationMessage([], []);
    return array_merge($this->loadMessage('refund'),
      [
        'gateway' => $donation_message['gateway'],
        'gateway_parent_id' => $donation_message['gateway_txn_id'],
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message['gross'],
        'gross_currency' => $donation_message['original_currency'],
      ], $values
    );
  }

}
