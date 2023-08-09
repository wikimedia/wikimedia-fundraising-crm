<?php

use Civi\Omnimail\MailFactory;

/**
 * @group LargeDonation
 */
class LargeDonationTest extends BaseWmfDrupalPhpUnitTestCase {

  protected $threshold;

  protected $threshold_high;

  protected $contact_id;

  public function setUp(): void {
    parent::setUp();
    civicrm_initialize();

    $this->threshold = 100;
    $this->threshold_high = 1000;

    db_delete('large_donation_notification')
      ->execute();

    db_insert('large_donation_notification')
      ->fields(array(
        'addressee' => 'notifee@localhost.net',
        'threshold' => $this->threshold,
        'financial_types_excluded' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift')
      ))
      ->execute();

    db_insert('large_donation_notification')
      ->fields(array(
        'addressee' => 'highrollingnotifee@localhost.net',
        'threshold' => $this->threshold_high,
        'financial_types_excluded' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift')
      ))
      ->execute();

    $contactID = $this->createTestContact([
      'contact_type' => 'Individual',
      'first_name' => 'Testes',
    ]);
    $this->contact_id = $contactID;
    // https://api.drupal.org/api/drupal/includes%21bootstrap.inc/function/drupal_static_reset/7.x
    drupal_static_reset('large_donation_get_minimum_threshold');
    drupal_static_reset('large_donation_get_notification_thresholds');
  }

  public function tearDown(): void {
    db_delete('large_donation_notification')
      ->execute();
    drupal_static_reset('large_donation_get_minimum_threshold');
    drupal_static_reset('large_donation_get_notification_thresholds');
    parent::tearDown();
  }

  public function testUnderThreshold(): void {
    civicrm_api3('Contribution', 'create', array(
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $this->threshold - 0.01,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
    ));

    $this->assertEquals(0, $this->getMailingCount());
  }

  public function testAboveThreshold(): void {
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

    $this->assertEquals(1, $this->getMailingCount());

    $mailing = $this->getMailing(0);
    $this->assertMatchesRegularExpression("/{$amount}/", $mailing['html'], 'Found amount in the notification email body.');
    $this->assertEquals('notifee@localhost.net', $mailing['to']);
  }

  public function testAboveHighThreshold(): void {
    $amount = $this->threshold_high + 0.01;
    $this->callAPISuccess('Contribution', 'create', array(
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $amount,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      'source' => 'EUR 2020',
    ));

    $this->assertEquals(2, $this->getMailingCount());

    $mailing = $this->getMailing(0);
    $mailing2 = $this->getMailing(1);
    $this->assertEquals([
      'notifee@localhost.net', 'highrollingnotifee@localhost.net'
    ], [
      $mailing['to'], $mailing2['to']
    ]);
  }

  /**
   * Test no mailing is sent for this smaller type.
   */
  public function testAboveThresholdExcludedType(): void {
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

    $this->assertEquals( 0, $this->getMailingCount());
  }
}
