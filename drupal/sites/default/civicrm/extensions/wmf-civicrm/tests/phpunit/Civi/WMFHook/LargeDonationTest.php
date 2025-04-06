<?php

namespace Civi\WMFHook;

use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use CRM_Core_PseudoConstant;
use PHPUnit;

/**
 * @group LargeDonation
 */
class LargeDonationTest extends PHPUnit\Framework\TestCase {
  use WMFEnvironmentTrait;
  use Api3TestTrait;
  use EntityTrait;

  protected $threshold;

  protected $threshold_high;

  protected $contact_id;

  public function setUp(): void {
    parent::setUp();
    civicrm_initialize();
    $this->setUpWMFEnvironment();

    $this->threshold = 100;
    $this->threshold_high = 1000;

    $endowmentType = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution',
      'financial_type_id',
      'Endowment Gift'
    );

    \Civi::settings()->set('large_donation_notifications', [
      [
        'threshold' => $this->threshold,
        'addressee' => 'notifee@localhost.net',
        'financial_types_excluded' => $endowmentType
      ],
      [
        'threshold' => $this->threshold_high,
        'addressee' => 'highrollingnotifee@localhost.net',
        'financial_types_excluded' => $endowmentType
      ],
    ] );

    $contactID = $this->createTestContact( [
      'contact_type' => 'Individual',
      'first_name' => 'Testes',
    ] );
    $this->contact_id = $contactID;
  }

  public function tearDown(): void {
    $this->tearDownWMFEnvironment();
    \Civi::settings()->set('large_donation_notifications', []);
    parent::tearDown();
  }

  public function testUnderThreshold(): void {
    civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $this->threshold - 0.01,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
    ]);

    $this->assertEquals( 0, $this->getMailingCount() );
  }

  public function testAboveThreshold(): void {
    $amount = $this->threshold + 0.01;
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $amount,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      'source' => 'EUR 2020',
    ]);

    $this->assertEquals(1, $this->getMailingCount());

    $mailing = $this->getMailing(0);
    $this->assertMatchesRegularExpression(
      "/{$amount}/",
      $mailing['html'],
      'Found amount in the notification email body.'
    );
    $this->assertEquals('notifee@localhost.net', $mailing['to']);
  }

  public function testAboveHighThreshold(): void {
    $amount = $this->threshold_high + 0.01;
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $amount,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      'source' => 'EUR 2020',
    ]);

    $this->assertEquals( 2, $this->getMailingCount() );

    $mailing = $this->getMailing(0);
    $mailing2 = $this->getMailing(1);
    $this->assertEquals( [
      'notifee@localhost.net', 'highrollingnotifee@localhost.net'
    ], [
      $mailing['to'], $mailing2['to']
    ] );
  }

  /**
   * Test no mailing is sent for this smaller type.
   */
  public function testAboveThresholdExcludedType(): void {
    $amount = $this->threshold + 0.01;
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contact_id,
      'financial_type_id' => 'Endowment Gift',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => $amount,
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      'source' => 'EUR 2020',
    ]);

    $this->assertEquals( 0, $this->getMailingCount() );
  }


  /**
   * Create a test contact and store the id to the $ids array.
   *
   * @param array $params
   *
   * @return int
   */
  public function createTestContact( $params ): int {
    $id = (int) $this->createTestEntity( 'Contact', $params )['id'];
    $this->ids['Contact'][$id] = $id;
    return $id;
  }

}
