<?php

use Civi\WMFException\WMFException;

/**
 * @group Pipeline
 * @group WmfCivicrm
 * @group Refund
 */
class RefundTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Id of the contribution created in the setup function.
   *
   * @var int
   */
  protected $original_contribution_id;

  protected $gateway_txn_id;

  protected $contact_id;

  protected $original_currency;

  protected $original_amount;

  protected $trxn_id;

  /**
   * End year of the financial year description.
   *
   * e.g for year 2017-2018 this will be 2018.
   *
   * @var int
   **/
  protected $financialYearEnd;

  /**
   * Name of the totals field for current financial year.
   *
   * e.g if today is 2018-04-18 then the field name is total_2017_2018
   *
   * @var string
   */
  protected $financialYearTotalFieldName;

  public function setUp(): void {
    parent::setUp();

    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Es',
      'debug' => 1,
    ]);

    $this->original_currency = 'EUR';
    $this->original_amount = 1.23;
    $this->gateway_txn_id = 'E-I-E-I-O';
    $time = time();
    $this->trxn_id = "TEST_GATEWAY E-I-E-I-O {$time}";
    $this->setExchangeRates($time, array('USD' => 1, 'EUR' => 0.5, 'NZD' => 5));
    $this->setExchangeRates(strtotime('1 year ago'), array('USD' => 1, 'EUR' => 0.5, 'NZD' => 5));

    $results = $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => $this->original_amount,
      'contribution_source' => $this->original_currency . ' ' . $this->original_amount,
      'receive_date' => date('Y-m-d') . ' 04:05:06',
      'trxn_id' => $this->trxn_id,
      'contribution_xtra.gateway' => 'test_gateway',
      'contribution_xtra.gateway_txn_id' => 'E-I-E-I-O',
    ], 'original');
    $this->original_contribution_id = $results['id'];
    $this->financialYearEnd = (date('m') > 6) ? date('Y') + 1 : date('Y');
    $this->financialYearTotalFieldName = 'total_' . ($this->financialYearEnd - 1) . '_' . $this->financialYearEnd;
    $this->assertCustomFieldValues($this->ids['Contact']['default'], [
      'lifetime_usd_total' => 1.23,
      'last_donation_date' => date('Y-m-d'),
      'last_donation_amount' => 1.23,
      'last_donation_usd' => 1.23,
      $this->financialYearTotalFieldName => 1.23,
    ]);
  }

  /**
   * Assert custom values are as expected.
   *
   * @param int $contactID
   * @param array $expected
   *   Array in format name => value eg. ['total_2017_2018' => 50]
   *
   * @deprecated uses apiv3 - use assertContactValues()
   */
  protected function assertCustomFieldValues($contactID, $expected) {
    $return = [];
    foreach (array_keys($expected) as $key) {
      $return[] = wmf_civicrm_get_custom_field_name($key);
    }

    $contact = civicrm_api3('contact', 'getsingle', [
      'id' => $contactID,
      'return' => $return,
    ]);

    foreach ($expected as $key => $value) {
      if ($key === 'last_donation_date') {
        // Compare by date only.
        $this->assertEquals($value, substr($contact[wmf_civicrm_get_custom_field_name($key)], 0, 10));
      }
      else {
        $this->assertEquals($value, $contact[wmf_civicrm_get_custom_field_name($key)], "wrong value for $key");
      }
    }
  }

  /**
   * Check chargeback status exists.
   */
  public function testStatuses() {
    $options = $this->callAPISuccess('Contribution', 'getoptions', array('field' => 'contribution_status_id'));
    $this->assertTrue(in_array('Chargeback', $options['values']));
  }

  /**
   * Covers wmf_civicrm_mark_refund.
   */
  public function testMarkRefund() {
    $message = [
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => $this->original_currency,
      'gross' => $this->original_amount,
      'date' => '2015-09-09',
      'gateway' => 'test_gateway',
      'gateway_refund_id' => 'my_special_ref',
      'type' => 'refund',
    ];
    $this->processMessage($message, 'Refund', 'refund');

    $contribution = $this->getContribution('original');

    $this->assertEquals('Refunded', $contribution['contribution_status_id:name'], 'Contribution not refunded');

    $financialTransactions = civicrm_api3('EntityFinancialTrxn', 'get', array(
      'entity_id' => $this->original_contribution_id,
      'entity_table' => 'civicrm_contribution',
      'api.financial_trxn.get' => 1,
      'sequential' => TRUE,
    ));
    $this->assertEquals(2, $financialTransactions['count']);
    $transaction1 = $financialTransactions['values']['0']['api.financial_trxn.get']['values'][0];
    $transaction2 = $financialTransactions['values']['1']['api.financial_trxn.get']['values'][0];

    $this->assertEquals($this->trxn_id, $transaction1['trxn_id']);
    $this->assertEquals(strtotime('2015-09-09'), strtotime($transaction2['trxn_date']));
    $this->assertEquals('my_special_ref', $transaction2['trxn_id']);

    // With no valid donations we wind up with null not zero as no rows are selected
    // in the calculation query.
    // This seems acceptable. we would probably need a tricky union or extra IF to
    // force to NULL. Field defaults are ignored in INSERT ON DUPLICATE UPDATE,
    // seems an OK sacrifice. If one valid donation (in any year) exists we
    // will get zeros in other years so only non-donors will have NULL values.
    // not quite sure why some are zeros not null?
    $this->assertCustomFieldValues($contribution['contact_id'], [
      'lifetime_usd_total' => NULL,
      'last_donation_date' => NULL,
      'last_donation_amount' => 0.00,
      'last_donation_usd' => 0.00,
      $this->financialYearTotalFieldName => NULL,
    ]);
  }

  /**
   * Check that marking a contribution as refunded updates custom data
   * appropriately.
   */
  public function testMarkRefundCheckCustomData(): void {
    $nextYear = date('Y', strtotime('+1 year'));
    $yearAfterNext = date('Y', strtotime('+2 year'));
    $this->callAPISuccess('Contribution', 'create', [
      'version' => 4,
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => 50,
      'source' => 'USD 50',
      'receive_date' => "$nextYear-11-01",
    ]);
    // Create an additional negative contribution. This is how they were prior to Feb 2016.
    // We want to check it is ignored for the purpose of determining the most recent donation
    // although it should contribute to the lifetime total.
    $this->callAPISuccess('Contribution', 'create', array(
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id:name' => 'Cash',
      'version' => 4,
      'total_amount' => -10,
      'contribution_source' => 'USD -10',
      'receive_date' => "$nextYear-12-01",
    ));
    wmf_civicrm_mark_refund($this->original_contribution_id, 'Refunded', FALSE, "$nextYear-09-09", 'my_special_ref');


    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 40,
      'wmf_donor.last_donation_date' => "$nextYear-11-01 00:00:00",
      'wmf_donor.last_donation_amount' => 50,
      'wmf_donor.last_donation_usd' => 50,
      'wmf_donor.last_donation_currency' => 'USD',
      "wmf_donor.total_$nextYear" => 40,
      'wmf_donor.number_donations'  => 1,
      "wmf_donor.total_{$nextYear}_{$yearAfterNext}" => 40,
      'wmf_donor.' . $this->financialYearTotalFieldName => 0,
    ]);
  }

  /**
   * Make a refund with type set to "chargeback"
   */
  public function testMarkRefundWithType() {
    wmf_civicrm_mark_refund($this->original_contribution_id, 'Chargeback');

    $contribution = civicrm_api3('contribution', 'getsingle', array(
      'id' => $this->original_contribution_id,
    ));

    $this->assertEquals('Chargeback', $contribution['contribution_status'],
      'Refund contribution has correct type');
  }

  /**
   * Make a refund for less than the original amount.
   *
   * The original contribution is refunded & a new contribution is created to represent
   * the balance (.25 EUR or 13 cents) so the contact appears to have made a 13 cent donation.
   *
   * The new donation gets today's date as we have not passed a refund date.
   *
   * @throws \Civi\ExchangeRates\ExchangeRatesException;
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function testMakeLesserRefund(): void {
    $lesser_amount = round($this->original_amount - 0.25, 2);

    $time = time();
    // Add an earlier contribution - this will be the most recent if our contribution is
    // deleted.
    $receiveDate = date('Y-m-d', strtotime('1 year ago'));
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->ids['Contact']['default'],
      'version' => 4,
      'financial_type_id:name' => 'Cash',
      'total_amount' => 40,
      'source' => 'NZD' . ' ' . 200,
      'receive_date' => $receiveDate,
      'trxn_id' => "TEST_GATEWAY {$this->gateway_txn_id} " . ($time - 200),
    ]);
    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 41.23,
      'wmf_donor.last_donation_date' => date('Y-m-d') . ' 04:05:06',
      'wmf_donor.last_donation_amount' => 1.23,
      'wmf_donor.last_donation_usd' => 1.23,
      'wmf_donor.' . $this->financialYearTotalFieldName => 1.23,
    ]);

    wmf_civicrm_mark_refund(
      $this->original_contribution_id,
      'Chargeback',
      TRUE, NULL, NULL,
      $this->original_currency, $lesser_amount
    );

    $refund_contribution_id = CRM_Core_DAO::singleValueQuery("
          SELECT entity_id FROM wmf_contribution_extra
          WHERE
          parent_contribution_id = %1",
      array(1 => array($this->original_contribution_id, 'Integer'))
    );

    $refund_contribution = civicrm_api3('Contribution', 'getsingle', array(
      'id' => $refund_contribution_id,
    ));

    $this->assertEquals(
      "{$this->original_currency} 0.25",
      $refund_contribution['contribution_source'],
      'Refund contribution has correct lesser amount'
    );
    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 40,
      'wmf_donor.last_donation_date' => date('Y-m-d 00:00:00', strtotime('1 year ago')),
      'wmf_donor.last_donation_usd' => 40,
      'wmf_donor.' . $this->financialYearTotalFieldName => 0,
      'wmf_donor.last_donation_currency' => 'NZD',
      'wmf_donor.last_donation_amount' => 200,
    ]);
  }

  /**
   * Make a refund in the wrong currency.
   */
  public function testMakeWrongCurrencyRefund(): void {
    $this->expectException(WMFException::class);
    $wrong_currency = 'GBP';
    $this->assertNotEquals($this->original_currency, $wrong_currency);
    $this->processMessageWithoutQueuing([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => $wrong_currency,
      'gross' => $this->original_amount,
      'date' => date('Y-m-d H:i:s'),
      'gateway' => 'test_gateway',
      'type' => 'refund',
    ], 'Refund');
  }

  /**
   * Make a refund for too much.
   */
  public function testMakeScammerRefund(): void {
    $this->processMessage([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => $this->original_currency,
      'gross' => $this->original_amount + 100.00,
      'date' => date('Y-m-d H:i:s'),
      'gateway' => 'test_gateway',
      'type' => 'refund',
    ], 'Refund', 'refund');
    $mailing = $this->getMailing(0);
    $this->assertStringContainsString("<p>Refund amount mismatch for : {$this->original_contribution_id}, difference is 100. See http", $mailing['html']);
  }

  /**
   * Make a lesser refund in the wrong currency
   */
  public function testLesserWrongCurrencyRefund() {
    $epochtime = time();
    $dbtime = wmf_common_date_unix_to_civicrm($epochtime);
    $this->setExchangeRates($epochtime, array('USD' => 1, 'COP' => .01));

    $result = $this->callAPISuccess('contribution', 'create', array(
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id' => 'Cash',
      'total_amount' => 200,
      'currency' => 'USD',
      'contribution_source' => 'COP 20000',
      'trxn_id' => "TEST_GATEWAY {$this->gateway_txn_id} " . (time() + 20),
    ));

    wmf_civicrm_mark_refund(
      $result['id'],
      'Refunded',
      TRUE,
      $dbtime,
      NULL,
      'COP',
      5000
    );

    $contributions = $this->callAPISuccess('Contribution', 'get', array(
      'contact_id' => $this->ids['Contact']['default'],
      'sequential' => TRUE,
    ));
    $this->assertEquals(3, $contributions['count'], print_r($contributions, TRUE));
    $this->assertEquals(200, $contributions['values'][1]['total_amount']);
    $this->assertEquals('USD', $contributions['values'][2]['currency']);
    $this->assertEquals($contributions['values'][2]['total_amount'], 150);
    $this->assertEquals('COP 15000', $contributions['values'][2]['contribution_source']);
  }

}
