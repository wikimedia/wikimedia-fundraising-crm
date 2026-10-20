<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi\Api4\WMFAudit;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * WMF Audit test.
 *
 * The Audit test class is responsible for processing the message onto the correct queue.
 */
class AuditTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  public function testAuditRefundMessage(): void {
    $message = [
      'contribution_tracking_id' => '43992337',
      'city' => 'asdf',
      'country' => 'US',
      'currency' => 'USD',
      'date' => 1487484651,
      'email' => 'mouse@wikimedia.org',
      'fee' => 0.24,
      'first_name' => 'asdf',
      'gateway' => 'adyen',
      'gateway_account' => 'TestMerchant',
      'gateway_txn_id' => '5364893193133131',
      'gross' => '1.00',
      'invoice_id' => '43992337.0',
      'language' => 'en',
      'last_name' => 'asdff',
      'order_id' => '43992337.0',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'postal_code' => '11111',
      'recurring' => '',
      'state_province' => 'AK',
      'street_address' => 'asdf',
      'user_ip' => '77.177.177.77',
      'utm_campaign' => 'C13_en.wikipedia.org',
      'utm_medium' => 'sidebar',
      'utm_source' => '..cc',
      'settled_gross' => '0.76',
      'settled_currency' => 'USD',
      'settled_fee' => 0.24,
      'tracking_date' => '2017-02-19 06:10:51',
    ];
    $audit = WMFAudit::audit(FALSE)
      ->setValues($message)->execute()->first();
    $this->assertEquals(TRUE, $audit['is_missing']);

  }

  /**
   * A Gravy recurring signup's first capture can reach the audit before its
   * initial Contribution does (e.g. contribution creation failed or is
   * still pending) - leaving a ContributionRecur whose own trxn_id is this
   * transaction's gateway_txn_id but with nothing linked to it yet.
   *
   * Gravy audit rows never carry subscr_id or recurring_payment_token (see
   * GravyAudit::$fieldMappings), so AuditMessage::getContributionRecurID()
   * has to find this ContributionRecur by trxn_id instead - otherwise the
   * row is queued as missing and a duplicate recurring record collides
   * with the one that already exists.
   */
  public function testAuditGravyRecurringSignupMissingInitialContribution(): void {
    $gatewayTxnID = '11111111-2222-3333-4444-555555555555';
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->createIndividual(),
      'amount' => 2.75,
      'currency' => 'USD',
      'payment_processor_id:name' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'processor_id' => $gatewayTxnID,
      'trxn_id' => $gatewayTxnID,
    ], 'recur');

    $message = [
      'contribution_tracking_id' => '99999999',
      'currency' => 'USD',
      'date' => time(),
      'email' => 'mouse@wikimedia.org',
      'first_name' => 'Danger',
      'last_name' => 'Mouse',
      'gateway' => 'gravy',
      'gateway_account' => 'WikimediaDonations',
      'gateway_txn_id' => $gatewayTxnID,
      'backend_processor' => 'stripe',
      'backend_processor_txn_id' => 'pi_' . $gatewayTxnID,
      'gross' => '2.75',
      'invoice_id' => '99999999.0',
      'order_id' => '99999999.0',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'recurring' => '1',
      'user_ip' => '77.177.177.77',
    ];

    $audit = WMFAudit::audit(FALSE)
      ->setValues($message)->execute()->first();

    // No Contribution exists yet for this transaction, so it's still 'missing'.
    $this->assertTrue($audit['is_missing']);
    // But the already-existing ContributionRecur, found by trxn_id, should
    // be identified and carried onto the queued message rather than left
    // for a duplicate to be created downstream.
    $this->assertEquals(
      $this->ids['ContributionRecur']['recur'],
      $audit['message']['contribution_recur_id']
    );
  }

}
