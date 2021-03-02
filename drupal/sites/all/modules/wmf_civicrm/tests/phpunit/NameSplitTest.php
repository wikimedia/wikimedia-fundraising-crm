<?php

/**
 * @group WmfCivicrm
 */
class NameSplitTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'WmfCivicrm name splitting',
            'group' => 'WmfCivicrm',
            'description' => 'Check insane name split nonsense',
        );
    }

    /**
     * @dataProvider getNames
     */
    public function testSplit( $full_name, $expected ) {
        $names = wmf_civicrm_janky_split_name( $full_name );
        $this->assertEquals( $expected, $names );
    }

    function getNames() {
        return array(
            array( 'Fu Bar', array( 'Fu', 'Bar' ) ),
            array( 'Fubar', array( 'Fubar', '' ) ),
            array( 'Yellow Rubber Ducky', array( 'Yellow', 'Rubber Ducky' ) ),
        );
    }
}
