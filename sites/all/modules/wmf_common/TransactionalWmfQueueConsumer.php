<?php namespace wmf_common;

use Exception;
use WmfDatabase;

/**
 * OK, this inheritance is getting Inception-level silly, but half our
 * queue consumers don't need to lock all the databases.
 */
abstract class TransactionalWmfQueueConsumer extends WmfQueueConsumer {

	/**
	 * We override the base callback wrapper to run processMessage inside
	 * a crazy multi-database transaction.
	 *
	 * @param array $message
	 */
	public function processMessageWithErrorHandling( $message ) {
		$this->logMessage( $message );
		$callback = array( $this, 'processMessage' );
		try {
			WmfDatabase::transactionalCall(
				$callback, array( $message )
			);
		} catch( Exception $ex ) {
			$this->handleError( $message, $ex );
		}
	}
}
