<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class RefundTest extends BaseWmfDrupalPhpUnitTestCase {

    /**
     * Id of the contribution created in the setup function.
     *
     * @var int
     */
    protected $original_contribution_id;

    public function setUp() {
        parent::setUp();
        civicrm_initialize();

        $results = civicrm_api3( 'contact', 'create', array(
            'contact_type' => 'Individual',
            'first_name' => 'Test',
            'last_name' => 'Es',
        ) );
        $this->contact_id = $results['id'];

        $this->original_currency = 'EUR';
        $this->original_amount = '1.23';
        $this->gateway_txn_id = mt_rand();
        $time = time();
        $this->trxn_id = "TEST_GATEWAY {$this->gateway_txn_id} {$time}";

        $results = civicrm_api3( 'contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'financial_type_id' => 'Cash',
            'total_amount' => $this->original_amount,
            'contribution_source' => $this->original_currency . ' ' . $this->original_amount,
            'receive_date' => wmf_common_date_unix_to_civicrm( $time ),
            'trxn_id' => $this->trxn_id,
        ) );
        $this->original_contribution_id = $results['id'];

        $this->refund_contribution_id = null;
    }

    public function tearDown() {
        civicrm_api3('contribution', 'delete', array(
            'id' => $this->original_contribution_id,
        ));

        if ($this->refund_contribution_id && $this->refund_contribution_id != $this->original_contribution_id) {
          civicrm_api3('contribution', 'delete', array(
            'id' => $this->refund_contribution_id,
          ));
        }

        civicrm_api3( 'contact', 'delete', array(
            'id' => $this->contact_id,
        ) );

        parent::tearDown();
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
        wmf_civicrm_mark_refund( $this->original_contribution_id, 'refund', false, '2015-09-09', 'my_special_ref');

        $contribution = civicrm_api3( 'contribution', 'getsingle', array(
            'id' => $this->original_contribution_id,
        ) );

        $this->assertEquals( 'Refunded', $contribution['contribution_status'],
            'Refunded contribution has correct status' );

        $financialTransactions = civicrm_api3('EntityFinancialTrxn', 'get', array(
            'entity_id' => $this->original_contribution_id,
            'entity_table' => 'civicrm_contribution',
            'api.financial_trxn.get' => 1,
            'sequential' => TRUE,
        ));
        $this->assertEquals(2, $financialTransactions['count']);
        $transaction1 = $financialTransactions['values']['0']['api.financial_trxn.get']['values'][0];
        $transaction2 = $financialTransactions['values']['1']['api.financial_trxn.get']['values'][0];

        $this->assertEquals($transaction1['trxn_id'], $this->trxn_id);
        $this->assertEquals(strtotime($transaction2['trxn_date']), strtotime('2015-09-09'));
        $this->assertEquals($transaction2['trxn_id'], 'my_special_ref');
    }

    /**
     * Make a refund with type set to "chargeback"
     */
    public function testMarkRefundWithType() {
        $this->refund_contribution_id = wmf_civicrm_mark_refund( $this->original_contribution_id, 'chargeback' );

        $contribution = civicrm_api3('contribution', 'getsingle', array(
          'id' => $this->original_contribution_id,
        ));

        $this->assertEquals( 'Chargeback', $contribution['contribution_status'],
            'Refund contribution has correct type' );
    }

    /**
     * Make a refund for less than the original amount
     */
    public function testMakeLesserRefund() {
        $lesser_amount = round( $this->original_amount - 0.25, 2 );
        wmf_civicrm_mark_refund(
            $this->original_contribution_id,
            'chargeback',
            true, null, null,
            $this->original_currency, $lesser_amount
        );


        $this->refund_contribution_id  = CRM_Core_DAO::singleValueQuery("
          SELECT entity_id FROM wmf_contribution_extra
          WHERE
          parent_contribution_id = %1",
          array(1 => array($this->original_contribution_id, 'Integer'))
        );

        $refund_contribution = civicrm_api3('Contribution', 'getsingle', array(
          'id' => $this->refund_contribution_id,
        ));

        $this->assertEquals(
            "{$this->original_currency} -0.25",
            $refund_contribution['contribution_source'],
            'Refund contribution has correct lesser amount'
        );
    }

    /**
     * Make a refund in the wrong currency
     *
     * @expectedException WmfException
     */
    public function testMakeWrongCurrencyRefund() {
        $wrong_currency = 'GBP';
        $this->assertNotEquals( $this->original_currency, $wrong_currency );
        wmf_civicrm_mark_refund(
            $this->original_contribution_id, 'refund',
            true, null, null,
            $wrong_currency, $this->original_amount
        );
    }

    /**
     * Make a refund for too much
     *
     * @expectedException WmfException
     */
    public function testMakeScammerRefund() {
        wmf_civicrm_mark_refund(
            $this->original_contribution_id, 'refund',
            true, null, null,
            $this->original_currency, $this->original_amount + 100.00
        );
    }
}
