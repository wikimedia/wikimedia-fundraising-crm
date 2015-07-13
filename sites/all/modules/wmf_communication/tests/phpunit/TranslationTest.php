<?php
namespace wmf_communication;

use \BaseWmfDrupalPhpUnitTestCase;

/**
 * @group WmfCommunication
 */
class TranslationTest extends BaseWmfDrupalPhpUnitTestCase {
    protected $msgKey = 'donate_interface-submit-button';

    function testReplaceMessages() {
        $smallStr = MediaWikiMessages::getInstance()->getMsg( $this->msgKey, 'fr' );
        $fullStr = Translation::replace_messages( "Viva %{$this->msgKey}%", 'fr' );

        $this->assertEquals( "Viva $smallStr", $fullStr );
    }

    function testNextFallback() {
        $langcode = Translation::next_fallback( 'fr-US' );
        $this->assertEquals( 'fr', $langcode );
    }

    function testGetTranslatedMessage() {
        $str = Translation::get_translated_message( $this->msgKey, 'fr-YY' );
        $directlyTranslated = MediaWikiMessages::getInstance()->getMsg( $this->msgKey, 'fr' );
        $this->assertEquals( $directlyTranslated, $str );
    }
}
