<?php

use wmf_communication\TestMailer;

/**
 * @group LargeDonation
 */
class LargeDonationTest extends BaseWmfDrupalPhpUnitTestCase {

  function setUp() {
    parent::setUp();
    civicrm_initialize();

    TestMailer::setup();

    $this->threshold = 100;

    db_delete('large_donation_notification')
      ->execute();

    db_insert('large_donation_notification')
      ->fields(array(
        'addressee' => 'notifee@localhost.net',
        'threshold' => $this->threshold,
        'financial_types_excluded' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift')
      ))
      ->execute();

    $result = $this->callAPISuccess('Contact', 'create', array(
      'contact_type' => 'Individual',
      'first_name' => 'Testes',
    ));
    $this->contact_id = $result['id'];
    // https://api.drupal.org/api/drupal/includes%21bootstrap.inc/function/drupal_static_reset/7.x
    drupal_static_reset('large_donation_get_minimum_threshold');
    drupal_static_reset('large_donation_get_notification_thresholds');
  }

  function tearDown() {
    db_delete('large_donation_notification')
      ->execute();
    parent::tearDown();
  }

  function testUnderThreshold() {
    civicrm_api3('Contribution', 'create', array(
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $this->threshold - 0.01,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
    ));

    $this->assertEquals(0, TestMailer::countMailings());
  }

  function testAboveThreshold() {
    $amount = $this->threshold + 0.01;
    $this->callAPISuccess('Contribution', 'create', array(
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $amount,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      'source' => 'EUR 2020',
    ));

    $this->assertEquals(1, TestMailer::countMailings());

    $mailing = TestMailer::getMailing(0);
    $this->assertEquals(1, preg_match("/{$amount}/", $mailing['html']),
      'Found amount in the notification email body.');
    }

  /**
   * Test no mailing is sent for this smaller type.
   */
  function testAboveThresholdExcludedType() {
    $amount = $this->threshold + 0.01;
    $this->callAPISuccess('Contribution', 'create', array(
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Endowment Gift',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $amount,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      'source' => 'EUR 2020',
    ) );

    $this->assertEquals( 0, TestMailer::countMailings());
  }
}
