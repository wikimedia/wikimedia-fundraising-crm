<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\WMFException\WMFException;

/**
 * @group Pipeline
 * @group WmfCivicrm
 * @group Refund
 */
class RefundTest extends BaseWmfDrupalPhpUnitTestCase {

  protected $gateway_txn_id;

  protected $contact_id;

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

  /**
   * Check chargeback status exists.
   */
  public function testStatuses() {
    $options = $this->callAPISuccess('Contribution', 'getoptions', array('field' => 'contribution_status_id'));
    $this->assertTrue(in_array('Chargeback', $options['values']));
  }

  /**
   * Generic testing of refund handling.
   */
  public function testMarkRefund() {
    $this->setupOriginalContribution();
    $message = [
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => 'EUR',
      'gross' => 1.23,
      'date' => '2015-09-09',
      'gateway' => 'test_gateway',
      'gateway_refund_id' => 'my_special_ref',
      'type' => 'refund',
    ];
    $this->processMessage($message, 'Refund', 'refund');

    $contribution = $this->getContribution('original');

    $this->assertEquals('Refunded', $contribution['contribution_status_id:name'], 'Contribution not refunded');

    $financialTransactions = civicrm_api3('EntityFinancialTrxn', 'get', array(
      'entity_id' => $this->ids['Contribution']['original'],
      'entity_table' => 'civicrm_contribution',
      'api.financial_trxn.get' => 1,
      'sequential' => TRUE,
    ));
    $this->assertEquals(2, $financialTransactions['count']);
    $transaction1 = $financialTransactions['values']['0']['api.financial_trxn.get']['values'][0];
    $transaction2 = $financialTransactions['values']['1']['api.financial_trxn.get']['values'][0];

    $this->assertEquals('TEST_GATEWAY E-I-E-I-O', $transaction1['trxn_id']);
    $this->assertEquals(strtotime('2015-09-09'), strtotime($transaction2['trxn_date']));
    $this->assertEquals('my_special_ref', $transaction2['trxn_id']);

    // With no valid donations we wind up with null not zero as no rows are selected
    // in the calculation query.
    // This seems acceptable. we would probably need a tricky union or extra IF to
    // force to NULL. Field defaults are ignored in INSERT ON DUPLICATE UPDATE,
    // seems an OK sacrifice. If one valid donation (in any year) exists we
    // will get zeros in other years so only non-donors will have NULL values.
    // not quite sure why some are zeros not null?
    $this->assertContactValues($contribution['contact_id'], [
      'wmf_donor.lifetime_usd_total' => NULL,
      'wmf_donor.last_donation_date' => NULL,
      'wmf_donor.last_donation_amount' => 0.00,
      'wmf_donor.last_donation_usd' => 0.00,
      'wmf_donor.' . $this->financialYearTotalFieldName => NULL,
    ]);
  }

  /**
   * Check that marking a contribution as refunded updates WMF Donor data.
   */
  public function testMarkRefundCheckWMFDonorData(): void {
    $this->setupOriginalContribution();
    $nextYear = date('Y', strtotime('+1 year'));
    $yearAfterNext = date('Y', strtotime('+2 year'));
    $this->createTestEntity('Contact', ['contact_type' => 'Individual', 'first_name' => 'Maisy', 'last_name' => 'Mouse'], 'maisy');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['maisy'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => 50,
      'source' => 'USD 50',
      'receive_date' => "$nextYear-11-01",
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 345,
    ]);
    // Create an additional negative contribution. This is how they were prior to Feb 2016.
    // We want to check it is ignored for the purpose of determining the most recent donation,
    // although it should contribute to the lifetime total.
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['maisy'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => -10,
      'contribution_source' => 'USD -10',
      'receive_date' => "$nextYear-12-01",
    ]);

    $this->processMessage([
      'gateway_parent_id' => 345,
      'gateway' => 'adyen',
      'gateway_txn_id' => 'my_special_ref',
      'gross' => 10,
      'date' => "$nextYear-09-09",
      'type' => 'refund',
    ], 'Refund', 'refund');

    $this->assertContactValues($this->ids['Contact']['maisy'], [
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
   * Asset the specified fields match those on the given contact.
   *
   * @param int $contactID
   * @param array $expected
   */
  protected function assertContactValues(int $contactID, array $expected) {
    try {
      $contact = Contact::get(FALSE)->setSelect(
        array_keys($expected)
      )->addWhere('id', '=', $contactID)->execute()->first();
    }
    catch (CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }

    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $contact[$key], "wrong value for $key");
    }
  }

  /**
   * Make a refund with type set to "chargeback"
   */
  public function testMarkRefundWithType(): void {
    $this->setupOriginalContribution();
    $this->processMessage([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => 'my_special_ref',
      'gross' => 10,
      'gross_currency' => 'USD',
      'date' => date('Ymd'),
      'type' => 'chargeback',
    ], 'Refund', 'refund');

    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contribution']['original'])
      ->addSelect('contribution_status_id:name')
      ->execute()->single();

    $this->assertEquals('Chargeback', $contribution['contribution_status_id:name'],
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
   * @throws \CRM_Core_Exception
   */
  public function testMakeLesserRefund(): void {
    $this->setupOriginalContribution();
    $time = time();
    // Add an earlier contribution - this will be the most recent if our contribution is
    // deleted.
    $receiveDate = date('Y-m-d', strtotime('1 year ago'));
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => 40,
      'source' => 'NZD' . ' ' . 200,
      'receive_date' => $receiveDate,
      'trxn_id' => "TEST_GATEWAY" . ($time - 200),
    ]);
    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 41.23,
      'wmf_donor.last_donation_date' => date('Y-m-d') . ' 04:05:06',
      'wmf_donor.last_donation_amount' => 1.23,
      'wmf_donor.last_donation_usd' => 1.23,
      'wmf_donor.' . $this->financialYearTotalFieldName => 1.23,
    ]);

    $this->processMessage([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => 'EUR',
      'gross' => 0.98,
      'date' => date('Y-m-d H:i:s'),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => 'abc',
      'type' => 'refund',
    ], 'Refund', 'refund');

    $refundContribution = Contribution::get(FALSE)
      ->addWhere('contribution_extra.parent_contribution_id', '=', $this->ids['Contribution']['original'])
      ->execute()
      ->single();

    $this->assertEquals(
      "EUR 0.25", $refundContribution['source'], 'Refund contribution has correct lesser amount'
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
    $this->setupOriginalContribution();
    $this->expectException(WMFException::class);
    $wrong_currency = 'GBP';
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
    $this->setupOriginalContribution();
    $this->processMessage([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => 'EUR',
      'gross' => 101.23,
      'date' => date('Y-m-d H:i:s'),
      'gateway' => 'test_gateway',
      'type' => 'refund',
    ], 'Refund', 'refund');
    $mailing = $this->getMailing(0);
    $this->assertStringContainsString("<p>Refund amount mismatch for : {$this->ids['Contribution']['original']}, difference is 100. See http", $mailing['html']);
  }

  /**
   * Make a lesser refund in the wrong currency
   */
  public function testLesserWrongCurrencyRefund(): void {
    $this->setupOriginalContribution();
    $this->setExchangeRates(time(), ['USD' => 1, 'COP' => .01]);

    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id.name' => 'Cash',
      'total_amount' => 200,
      'currency' => 'USD',
      'contribution_source' => 'COP 20000',
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 345,
      'trxn_id' => "TEST_GATEWAY E-I-E-I-O " . (time() + 20),
    ]);

    $this->processMessage([
      'gateway_parent_id' => 345,
      'gateway' => 'adyen',
      'gateway_txn_id' => 123,
      'gross_currency' => 'COP',
      'gross' => 5000,
      'date' => date('Y-m-d H:i:s'),
      'type' => 'refund',
    ], 'Refund', 'refund');

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

  /**
   * @return void
   */
  public function setupOriginalContribution(): void {
    $time = time();
    $this->setExchangeRates($time, ['USD' => 1, 'EUR' => 0.5, 'NZD' => 5]);
    $this->setExchangeRates(strtotime('1 year ago'), ['USD' => 1, 'EUR' => 0.5, 'NZD' => 5]);

    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Es',
      'debug' => 1,
    ]);
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => 1.23,
      'contribution_source' => 'EUR 1.23',
      'receive_date' => date('Y-m-d') . ' 04:05:06',
      'trxn_id' => 'TEST_GATEWAY E-I-E-I-O',
      'contribution_xtra.gateway' => 'test_gateway',
      'contribution_xtra.gateway_txn_id' => 'E-I-E-I-O',
    ], 'original');

    $this->financialYearEnd = (date('m') > 6) ? date('Y') + 1 : date('Y');
    $this->financialYearTotalFieldName = 'total_' . ($this->financialYearEnd - 1) . '_' . $this->financialYearEnd;
    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 1.23,
      'wmf_donor.last_donation_date' => date('Y-m-d') . ' 04:05:06',
      'wmf_donor.last_donation_amount' => 1.23,
      'wmf_donor.last_donation_usd' => 1.23,
      'wmf_donor.' . $this->financialYearTotalFieldName => 1.23,
    ]);
  }

}
