<?php namespace queue2civicrm\banner_history;

use wmf_common\WmfQueueConsumer;
use WmfException;

class BannerHistoryQueueConsumer extends WmfQueueConsumer {

	/**
	 * Validate and store messages from the banner history queue
	 *
	 * @param array $message
	 * @throws WmfException
	 */
	function processMessage( $message ) {
		if ( empty( $message ) ) {
			throw new WmfException(
				'BANNER_HISTORY',
				'Empty banner history message.'
			);
		}

		if (
			empty( $message['banner_history_id'] ) ||
			empty( $message['contribution_tracking_id'] )
		) {
			throw new WmfException(
				'BANNER_HISTORY',
				'Missing banner history or contribution tracking ID.'
			);
		}

		$bannerHistoryId = $message['banner_history_id'];
		$contributionTrackingId = $message['contribution_tracking_id'];

		if (
			!is_numeric( $contributionTrackingId ) ||
			!preg_match( '/^[0-9a-f]{16,20}$/', $bannerHistoryId )
		) {
			throw new WmfException(
				'BANNER_HISTORY',
				'Invalid data in banner history message.'
			);
		}

		watchdog(
			'banner_history',
			"About to add row for $bannerHistoryId",
			array(),
			WATCHDOG_INFO
		);

		db_merge( 'banner_history_contribution_associations' )
			->key( array(
				'banner_history_log_id' => $bannerHistoryId,
				'contribution_tracking_id' => $contributionTrackingId
			) )
			->insertFields( array(
				'banner_history_log_id' => $bannerHistoryId,
				'contribution_tracking_id' => $contributionTrackingId
			) )
			->execute();

		watchdog( 'banner_history', "Processed $bannerHistoryId" );
	}
}
