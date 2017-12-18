<?php namespace queue2civicrm\fredge;

use FredgeDataValidationException;
use wmf_common\WmfQueueConsumer;
use WmfException;

class AntifraudQueueConsumer extends WmfQueueConsumer {

	/**
	 * Validate and store messages from the payments-antifraud queue
	 *
	 * @param array $message
	 * @throws WmfException
	 */
	function processMessage( $message ) {
		$id = "{$message['gateway']}-{$message['order_id']}";
		watchdog(
			'fredge',
			"Beginning processing of payments-antifraud message for $id: " .
			json_encode( $message ),
			array(),
			WATCHDOG_INFO
		);

		// handle the IP address conversion to binary so we can do database voodoo later.
		if ( array_key_exists( 'user_ip', $message ) ) {
			// check for IPv6
			if ( strpos( ':', $message['user_ip'] ) !== false ) {
				/**
				 * despite a load of documentation to the contrary, the following line
				 * ***doesn't work at all***.
				 * Which is okay for now: We force IPv4 on payments.
				 * @TODO eventually: Actually handle IPv6 here.
				 */
				// $message['user_ip'] = inet_pton($message['user_ip']);

				watchdog(
					'fredge',
					'Weird. Somehow an ipv6 address got through on payments. ' .
						"Caught in antifraud consumer. $id",
					array(),
					WATCHDOG_WARNING
				);
				$message['user_ip'] = 0;
			} else {
				$message['user_ip'] = ip2long( $message['user_ip'] );
			}
		}

		$this->insertAntifraudData( $message, $id );
	}

	/**
	 * take a message and insert or update rows in payments_fraud and payments_fraud_breakdown.
	 * If there is not yet an antifraud row for this ct_id and order_id, all fields
	 * in the table must be present in the message.
	 * @param array $msg the message that you want to upsert.
	 * @param string $logIdentifier Some small string for the log that will help id
	 * the message if something goes amiss and we have to log about it.
	 * @throws FredgeDataValidationException
	 */
	protected function insertAntifraudData( $msg, $logIdentifier ) {

		if ( empty( $msg ) || empty( $msg['contribution_tracking_id'] ) || empty( $msg['order_id'] ) ) {
			$error = "$logIdentifier: missing essential payments_fraud IDs. Dropping message on floor.";
			throw new FredgeDataValidationException( $error );
		}

		$id = 0;
		$inserting = true;

		$dbs = wmf_civicrm_get_dbs();
		$dbs->push( 'fredge' );
		$query = 'SELECT id FROM payments_fraud WHERE contribution_tracking_id = :ct_id AND order_id = :order_id LIMIT 1';
		$result = db_query( $query, array(
			':ct_id' => $msg['contribution_tracking_id'],
			':order_id' => $msg['order_id']
		) );
		if ( $result->rowCount() === 1 ) {
			$id = $result->fetch()->id;
			$inserting = false;
		}
		$data = fredge_prep_data( $msg, 'payments_fraud', $logIdentifier, $inserting );
		//now all you have to do is insert the actual message data.
		if ( $inserting ) {
			$id = db_insert( 'payments_fraud' )
				->fields( $data )
				->execute();
		} else {
			db_update( 'payments_fraud' )
				->fields( $data )
				->condition( 'id', $id )
				->execute();
		}
		if ( $id ) {
			foreach ( $msg['score_breakdown'] as $test => $score ) {
			  if ($score > 100000000) {
			    $score = 100000000;
        }
				$breakdown = array(
					'payments_fraud_id' => $id,
					'filter_name' => $test,
					'risk_score' => $score,
				);
				// validate the data. none of these fields would be converted, so no need
				// to store the output
				fredge_prep_data( $breakdown, 'payments_fraud_breakdown', $logIdentifier, true );
				db_merge( 'payments_fraud_breakdown' )->key( array(
					'payments_fraud_id' => $id,
					'filter_name' => $test,
				) )->fields( array(
					'risk_score' => $score,
				) )->execute();
			}
		}
	}
}
