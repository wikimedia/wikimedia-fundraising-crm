<?php
namespace wmf_communication;

use \BaseWmfDrupalPhpUnitTestCase;

/**
 * @group WmfCommunication
 */
class TranslationTest extends BaseWmfDrupalPhpUnitTestCase {
    protected $msgKey = 'donate_interface-submit-button';

    function testNextFallback() {
        $langcode = Templating::next_fallback( 'fr-US' );
        $this->assertEquals( 'fr', $langcode );
    }

}
