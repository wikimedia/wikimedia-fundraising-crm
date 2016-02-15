<?php

require_once( __DIR__ . "/../../bootstrap.inc" );

/**
 * @group WmfCivicrm
 */
class InstallFunctionsTest extends BaseWmfDrupalPhpUnitTestCase {

    /**
     * Test that the install function for creating option values works.
     *
     * Test with multiple values and an appostrophe for good measure.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testCreateOptionValues() {
        wmf_civicrm_create_option_values( 'payment_instrument', array('Monopoly Money', "IOU's", 'Drakmar'));
        $options = civicrm_api3('Contribution', 'getoptions', array('field' => 'payment_instrument_id'));
        $this->assertTrue(in_array('Monopoly Money', $options['values']));
        $this->assertTrue(in_array("IOU's", $options['values']));
        $this->assertTrue(in_array('Drakmar', $options['values']));
    }

   /**
     * Test that an option value can be created and used for 'tag_used_for'.
     *
     * Wmf sets tags against contributions & needs to add a used_for for that.
     *
     * Here we test against participants since that should NOT be in the DB.
     *
     * (am unsure whether it would be better if the install just used the
     * equivalent functions in the .module file or there was a reason not to).
     */
    public function testUsedForTag() {
        wmf_civicrm_create_option_values_detailed('tag_used_for', array(
            'Participants' => array('value' => 'civicrm_participant'),
        ));
        $options = civicrm_api3('Tag', 'getoptions', array('field' => 'used_for'));
        $this->assertArrayHasKey('civicrm_contact', $options['values']);
        $this->assertArrayHasKey('civicrm_participant', $options['values']);
    }

}
