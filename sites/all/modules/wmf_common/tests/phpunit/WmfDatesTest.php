<?php
/**
 * @group WmfCommon
 */
class WmfDatesTest extends BaseWmfDrupalPhpUnitTestCase {
	public static function dateProvider() {
		return array(
			array( '@1470333044', 1470333044 ),
			array( '@1470333045.63', 1470333045 ),
		);
	}

	/**
	 * @dataProvider WmfDatesTest::dateProvider
	 */
    public function testDateParse( $text, $expectedSeconds ) {
		$actual = wmf_common_date_parse_string( $text );

        $this->assertEquals( $expectedSeconds, $actual,
            'Date parsed as expected' );
    }
}
