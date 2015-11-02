<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class RefundTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Refund',
            'group' => 'Pipeline',
            'description' => 'Test refund handling.',
        );
    }

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
            'contribution_type' => 'Cash',
            'total_amount' => $this->original_amount,
            'contribution_source' => $this->original_currency . ' ' . $this->original_amount,
            'receive_date' => wmf_common_date_unix_to_civicrm( $time ),
            'trxn_id' => $this->trxn_id,
        ) );
        $this->original_contribution_id = $results['id'];

        $this->refund_contribution_id = null;
    }

    public function tearDown() {
        civicrm_api3( 'contribution', 'delete', array(
            'id' => $this->original_contribution_id,
        ) );

        if ( $this->refund_contribution_id ) {
            civicrm_api3( 'contribution', 'delete', array(
                'id' => $this->refund_contribution_id,
            ) );
        }

        civicrm_api3( 'contact', 'delete', array(
            'id' => $this->contact_id,
        ) );

        parent::tearDown();
    }

    /**
     * Covers wmf_civicrm_mark_refund
     */
    public function testMarkRefund() {
        $this->refund_contribution_id = wmf_civicrm_mark_refund( $this->original_contribution_id );

        $this->assertNotNull( $this->refund_contribution_id,
            "Refund created" );

        $results = civicrm_api3( 'contribution', 'get', array(
            'id' => $this->original_contribution_id,
        ) );
        $contribution = array_pop( $results['values'] );

        $this->assertEquals( 'Refunded', $contribution['contribution_status'],
            'Refunded contribution has correct status' );

        $results = civicrm_api3( 'contribution', 'get', array(
            'id' => $this->refund_contribution_id,
        ) );
        $refund_contribution = array_pop( $results['values'] );

        $this->assertEquals( 'Refund', $refund_contribution['financial_type'] );
        $this->assertEquals( 'Pending', $refund_contribution['contribution_status'] );
        $this->assertEquals(
            "{$this->original_currency} -{$this->original_amount}",
            $refund_contribution['contribution_source'] );
    }

    /**
     * Make a refund with type set to "chargeback"
     */
    public function testMarkRefundWithType() {
        $this->refund_contribution_id = wmf_civicrm_mark_refund( $this->original_contribution_id, 'chargeback' );

        $api = civicrm_api_classapi();
        $results = civicrm_api3( 'contribution', 'get', array(
            'id' => $this->refund_contribution_id,

            'version' => 3,
        ) );
        $contribution = array_pop( $results['values'] );

        $this->assertEquals( 'Chargeback', $contribution['financial_type'],
            'Refund contribution has correct type' );
    }

    /**
     * Make a refund for less than the original amount
     */
    public function testMakeLesserRefund() {
        $lesser_amount = round( $this->original_amount - 0.25, 2 );
        $this->refund_contribution_id = wmf_civicrm_mark_refund(
            $this->original_contribution_id,
            'chargeback',
            true, null, null,
            $this->original_currency, $lesser_amount
        );

        $results = civicrm_api3( 'contribution', 'get', array(
            'id' => $this->refund_contribution_id,

            'version' => 3,
        ) );
        $refund_contribution = array_pop( $results['values'] );

        $this->assertEquals(
            "{$this->original_currency} -{$lesser_amount}",
            $refund_contribution['contribution_source'],
            'Refund contribution has correct lesser amount' );
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
