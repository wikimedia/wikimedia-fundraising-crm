<?php

/**
 * @group Queue2Civicrm
 */
class BannerHistoryTest extends BaseWmfDrupalPhpUnitTestCase {
	public function testValidMessage() {
		$msg = ( object ) array(
			'body' => json_encode( array(
				'banner_history_id' => substr(
					md5( mt_rand() . time() ), 0, 16
				),
				'contribution_tracking_id' => strval( mt_rand() ),
			) ),
		);
		banner_history_process_message( $msg );
		// check for thing in db
	}

	/**
	 * @expectedException WmfException
	 */
	public function testBadContributionId() {
		$msg = ( object ) array(
			'body' => json_encode( array(
				'banner_history_id' => substr(
					md5( mt_rand() . time() ), 0, 16
				),
				'contribution_tracking_id' => '1=1; DROP TABLE students;--',
			) ),
		);
		banner_history_process_message( $msg );
	}

	/**
	 * @expectedException WmfException
	 */
	public function testBadHistoryId() {
		$msg = ( object ) array(
			'body' => json_encode( array(
				'banner_history_id' => '\';GRANT ALL ON drupal.* TO \'leet\'@\'haxx0r\'',
				'contribution_tracking_id' => strval( mt_rand() ),
			) ),
		);
		banner_history_process_message( $msg );
	}
}
