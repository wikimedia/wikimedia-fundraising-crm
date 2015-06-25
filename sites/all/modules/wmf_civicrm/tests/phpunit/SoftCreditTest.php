<?php

class SoftCreditTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Soft Credit',
            'group' => 'Pipeline',
            'description' => 'Ensure we record soft credits.',
        );
    }

    public function testSoftCredit() {
        $fixtures = CiviFixtures::create();

        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',

            'soft_credit_to' => $fixtures->org_contact_name,
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );

        $api = civicrm_api_classapi();
        $api->Contribution->Get( array(
            'id' => $contribution['id'],
            'return' => array(
                'soft_credit_to' => 1,
            ),

            'version' => 3,
        ) );

        $this->assertEquals( $fixtures->org_contact_id, $api->values[0]->soft_credit_to );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionMessage Bad soft credit target
     */
    public function testBadSoftCreditTarget() {
        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',

            'soft_credit_to' => 'Not a thing',
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );
    }
}
