<?php

/**
 * @group WmfCivicrm
 */
class HelperFunctionsTest extends BaseWmfDrupalPhpUnitTestCase {

    /**
     * Test wmf_civicrm_tag_contact_for_review.
     *
     * Maintenance note: the civicrm entity_tag get api returns an odd syntax.
     *
     * If that ever gets fixed it may break this test - but only the test would
     * need to be altered to adapt.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testTagContactForReview() {
        civicrm_initialize();
        $contact = civicrm_api3('Contact', 'create', array(
            'contact_type' => 'Organization',
            'organization_name' => 'The Evil Empire',
        ));
        wmf_civicrm_tag_contact_for_review($contact);
        $entityTags = civicrm_api3('EntityTag', 'get', array('entity_id' => $contact['id']));
        $tag = reset($entityTags['values']);
        $this->assertEquals(civicrm_api3('Tag', 'getvalue', array('name' => 'Review', 'return' => 'id')), $tag['tag_id']);
    }

    /**
     * Test wmf_ensure_language_exists
     *
     * Maintenance note: the civicrm entity_tag get api returns an odd syntax.
     *
     * If that ever gets fixed it may break this test - but only the test would
     * need to be altered to adapt.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testEnsureLanguageExists() {
        civicrm_initialize();
        wmf_civicrm_ensure_language_exists('en_IL');
        $languages = civicrm_api3('OptionValue', 'get', array(
            'option_group_name' => 'languages',
            'name' => 'en_IL',
        ));
        $this->assertEquals(1, $languages['count']);
    }

    /**
     * Test wmf custom api entity get detail.
     *
     * @todo consider moving test to thank_you module or helper function out of there.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testGetEntityTagDetail() {
        civicrm_initialize();
        $contact = $this->callAPISuccess('Contact', 'create', array(
            'first_name' => 'Papa',
            'last_name' => 'Smurf',
            'contact_type' => 'Individual',
        ));
        $contribution = $this->callAPISuccess('Contribution', 'create', array(
            'contact_id' => $contact['id'],
            'total_amount' => 40,
            'financial_type_id' => 'Donation',
        ));

        $tag1 = $this->ensureTagExists('smurfy');
        $tag2 = $this->ensureTagExists('smurfalicious');

        $this->callAPISuccess('EntityTag', 'create', array('entity_id' => $contribution['id'], 'entity_table' => 'civicrm_contribution', 'tag_id' => 'smurfy'));
        $this->callAPISuccess('EntityTag', 'create', array('entity_id' => $contribution['id'], 'entity_table' => 'civicrm_contribution', 'tag_id' => 'smurfalicious'));

        $smurfiestTags = wmf_thank_you_get_tag_names($contribution['id']);
        $this->assertEquals(array('smurfy', 'smurfalicious'), $smurfiestTags);

        $this->callAPISuccess('Tag', 'delete', array('id' => $tag1));
        $this->callAPISuccess('Tag', 'delete', array('id' => $tag2));
    }

    /**
     * Helper function to protect test against cleanup issues.
     *
     * @param string $name
     * @return int
     */
    public function ensureTagExists($name) {
        $tags = $this->callAPISuccess('EntityTag', 'getoptions', array('field' => 'tag_id'));
        if (in_array($name, $tags['values'])) {
            return array_search($name, $tags['values']);
        }
        $tag = $this->callAPISuccess('Tag', 'create', array(
            'used_for' => 'civicrm_contribution',
            'name' => $name
        ));
        $this->callAPISuccess('Tag', 'getfields', array('cache_clear' => 1));
        return $tag['id'];
    }

}
