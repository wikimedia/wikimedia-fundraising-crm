<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class PhoneImportTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Phone Number',
            'group' => 'Pipeline',
            'description' => 'Ensure we record phone numbers.',
        );
    }

    public function testPhoneImport() {
        $phone = '555-555-5555';

        $msg = array(
            'currency' => 'USD',
            'date' => time(),
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',

            'phone' => $phone,
        );

        $contribution = wmf_civicrm_contribution_message_import( $msg );

        $api = civicrm_api_classapi();
        $api->Phone->Get( array(
            'contact_id' => $contribution['contact_id'],
        ) );

        $this->assertEquals( $phone, $api->values[0]->phone );
        $this->assertEquals( 1, $api->values[0]->is_primary );
        $this->assertEquals( wmf_civicrm_get_default_location_type_id(), $api->values[0]->location_type_id );
        $this->assertEquals( CRM_Core_OptionGroup::getValue('phone_type', 'phone'), $api->values[0]->phone_type_id );
    }

}
