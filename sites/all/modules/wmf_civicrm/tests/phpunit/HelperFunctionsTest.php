<?php

/**
 * @group WmfCivicrm
 */
class HelperFunctionsTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'WmfCivicrm help functions',
            'group' => 'WmfCivicrm',
            'description' => 'Tests for helper functions in .module file',
        );
    }

    /**
     * Prepare for test.
     *
     * @throws \Exception
     */
    public function setUp() {
        // @todo not sure why I needed to do this to be enotice free.
        global $user;
        $user->timezone = '+13';
        civicrm_initialize();
        parent::setUp();
    }

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
        $contact = civicrm_api3('Contact', 'create', array(
            'contact_type' => 'Organization',
            'organization_name' => 'The Evil Empire',
        ));
        wmf_civicrm_tag_contact_for_review($contact);
        $entityTags = civicrm_api3('EntityTag', 'get', array('entity_id' => $contact['id']));
        $this->assertArrayHasKey(civicrm_api3('Tag', 'getvalue', array('name' => 'Review', 'return' => 'id')), $entityTags['values']);
    }

}
