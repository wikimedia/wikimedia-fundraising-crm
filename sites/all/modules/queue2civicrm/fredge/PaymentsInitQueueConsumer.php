<?php namespace queue2civicrm\fredge;

use wmf_common\WmfQueueConsumer;
use WmfException;

class PaymentsInitQueueConsumer extends WmfQueueConsumer {

	/**
	 * Validate and store messages from the payments-init queue
	 *
	 * @param array $message
	 * @throws WmfException
	 */
	function processMessage( $message ) {
		$logId = "{$message['gateway']}-{$message['order_id']}";
		watchdog(
			'fredge',
			"Beginning processing of payments-init message for $logId: " .
				json_encode( $message ),
			array(),
			WATCHDOG_INFO
		);

		$id = 0;
		$inserting = true;

		$dbs = wmf_civicrm_get_dbs();
		$dbs->push( 'fredge' );
		$query = 'SELECT id FROM payments_initial
                  WHERE contribution_tracking_id = :ct_id
                  AND order_id = :order_id LIMIT 1';
		$result = db_query( $query, array(
			':ct_id' => $message['contribution_tracking_id'],
			':order_id' => $message['order_id']
		) );
		if ( $result->rowCount() === 1 ) {
			$id = $result->fetch()->id;
			$inserting = false;
		}

		$data = fredge_prep_data( $message, 'payments_initial', $logId, $inserting );

		if ( $inserting ) {
			db_insert( 'payments_initial' )
				->fields( $data )
				->execute();
		} else {
			db_update( 'payments_initial' )
				->fields( $data )
				->condition( 'id', $id )
				->execute();
		}
	}
}
