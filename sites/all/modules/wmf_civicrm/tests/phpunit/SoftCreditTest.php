<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
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

        $retrievedContribution = $this->callAPISuccessGetSingle('Contribution', array(
          'id' => $contribution['id'],
          'return' => array(
            'soft_credit_to' => 1,
          ),
        ));

        $this->assertEquals( $fixtures->org_contact_id, $retrievedContribution['soft_credit_to']);
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
