<?php
namespace wmf_communication;

use \BaseWmfDrupalPhpUnitTestCase;

class MediaWikiMessagesTest extends BaseWmfDrupalPhpUnitTestCase {
    function setUp() {
        parent::setUp();

        $this->msgs = MediaWikiMessages::getInstance();
    }

    function testGetMsg_en() {
        $str = $this->msgs->getMsg( 'donate_interface-submit-button', 'en' );
        $this->assertTrue( !empty( $str ) );
    }

    function testGetMsg_other() {
        $enStr = $this->msgs->getMsg( 'donate_interface-submit-button', 'en' );

        $str = $this->msgs->getMsg( 'donate_interface-submit-button', 'fr' );
        $this->assertTrue( !empty( $str ) );
        $this->assertNotEquals( $enStr, $str );
    }

    function testMsgExists() {
        $this->assertTrue( $this->msgs->msgExists( 'donate_interface-submit-button', 'en' ) );
        $this->assertTrue( $this->msgs->msgExists( 'donate_interface-submit-button', 'fr' ) );
    }

    function testLanguageList() {
        $languages = $this->msgs->languageList();
        $this->assertTrue( count($languages) > 3 );
        $this->assertTrue( in_array( 'en', $languages ) );
        $this->assertTrue( in_array( 'fr', $languages ) );
    }
}
