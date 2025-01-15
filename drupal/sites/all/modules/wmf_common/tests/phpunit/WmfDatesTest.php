<?php
/**
 *
 * @group WmfCommon
 */
class WmfDatesTest extends BaseWmfDrupalPhpUnitTestCase {
	/**
	 * @return [dateText, expectedSeconds, expectedUtc]
	 */
	public static function dateProvider() {
		return array(
			array( '@1470333044', 1470333044, '2016-08-04T17:50:44+00:00' ),
			array( '@1470333045.63', 1470333045, '2016-08-04T17:50:45+00:00' ),
		);
	}

	/**
	 * @dataProvider WmfDatesTest::dateProvider
	 */
	public function testDateFormatUsingUtc( $_text, $seconds, $expectedUtc ) {
		$actual = wmf_common_date_format_using_utc( 'c', $seconds );

		$this->assertEquals( $expectedUtc, $actual,
			'Date formatted as expected' );
	}
}
