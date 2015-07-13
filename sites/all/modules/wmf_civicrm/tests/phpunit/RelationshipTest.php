<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class RelationshipTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Relationship',
            'group' => 'Pipeline',
            'description' => 'Ensure we record contact relationships.',
        );
    }

    public function testRelationship() {
        $fixtures = CiviFixtures::create();

        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',

            'relationship_target_contact_id' => $fixtures->contact_id,
            'relationship_type' => 'Spouse of',
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );

        $api = civicrm_api_classapi();

        $api->RelationshipType->Get( array(
            'name_a_b' => 'Spouse of',
        ) );
        $relationship_type_id = $api->values[0]->id;

        $api->Relationship->Get( array(
            'contact_id_a' => $contribution['contact_id'],
        ) );

        $this->assertEquals( $relationship_type_id, $api->values[0]->relationship_type_id );
    }

    /**
     * @expectedException WmfException
     */
    public function testBadRelationshipTarget() {
        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',

            'relationship_target_contact_id' => mt_rand(),
            'relationship_type' => 'Spouse of',
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );
    }

    /**
     * @expectedException WmfException
     */
    public function testBadRelationshipType() {
        $fixtures = CiviFixtures::create();

        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',

            'relationship_target_contact_id' => $fixtures->contact_id,
            'relationship_type' => 'Total stranger to',
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );
    }
}
