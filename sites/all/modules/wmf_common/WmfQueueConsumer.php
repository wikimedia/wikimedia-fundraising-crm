<?php namespace wmf_common;

use SmashPig\Core\QueueConsumers\BaseQueueConsumer;
use Exception;
use WmfException;

/**
 * Queue consumer that knows what to do with WmfExceptions
 */
abstract class WmfQueueConsumer extends BaseQueueConsumer {

	protected function handleError( $message, Exception $ex ) {
		$correlationId = "{$message['gateway']}-{$message['order_id']}";
		if ( $ex instanceof WmfException ) {
			watchdog(
				'wmf_common',
				'Failure while processing message: ' . $ex->getMessage(),
				NULL,
				WATCHDOG_ERROR
			);

			$this->handleWmfException( $message, $ex, $correlationId );
		} else {
			$error = 'UNHANDLED ERROR. Halting dequeue loop. Exception: ' .
				$ex->getMessage() . "\nStack Trace: " .
				$ex->getTraceAsString();
			watchdog( 'wmf_common', $error, NULL, WATCHDOG_ERROR );
			wmf_common_failmail( 'wmf_common', $error, NULL, $correlationId );

			throw $ex;
		}
	}

	/**
	 * @param array $message
	 * @param WmfException $ex
	 * @param string $correlationId
	 * @throws WmfException when we want to halt the dequeue loop
	 */
	protected function handleWmfException(
		$message, WmfException $ex, $correlationId
	) {
		$mailableDetails = '';
		$reject = false;

		if ( $ex->isRequeue() ) {
			$delay = intval( variable_get( 'wmf_common_requeue_delay', 20 * 60 ) );
			$maxTries = intval( variable_get( 'wmf_common_requeue_max', 10 ) );
			$ageLimit = $delay * $maxTries;
			if ( isset( $message['source_enqueued_time'] ) ) {
				// This should be set the first time a message is queued and
				// not updated on retry.
				$queuedTime = $message['source_enqueued_time'];
			} else if ( isset( $message['date'] ) ) {
				// This is not entirely accurate, being the date the payment
				// actually occurred. Can still use it as fallback.
				$queuedTime = $message['date'];
			} else {
				// Setting this to 0 means we'll always go the reject route
				// and log an error.
				$queuedTime = 0;
				watchdog(
					'wmf_common',
					"Message has no useful information about queued date",
					$message,
					WATCHDOG_NOTICE
				);
			}
			if ( $queuedTime + $ageLimit < time() ) {
				$reject = true;
			} else {
				$retryDate = time() + $delay;
				$this->sendToDamagedStore( $message, $ex, $retryDate );
			}
		}

		if ( $ex->isDropMessage() ) {
			watchdog(
				'wmf_common',
				"Dropping message altogether: $correlationId",
				NULL,
				WATCHDOG_ERROR
			);
		} elseif ( $ex->isRejectMessage() || $reject ) {
			$messageString = json_encode( $message );
			watchdog(
				'wmf_common',
				"\nRemoving failed message from the queue: \n$messageString",
				NULL,
				WATCHDOG_ERROR
			);
			$damagedId = $this->sendToDamagedStore(
				$message,
				$ex
			);
			$mailableDetails = self::itemUrl( $damagedId );
		} else {
			$mailableDetails = "Redacted contents of message ID: $correlationId";
		}

		if ( !$ex->isNoEmail() ) {
			wmf_common_failmail( 'wmf_common', '', $ex, $mailableDetails );
		}

		if ( $ex->isFatal() ) {
			$error = 'Halting Process.';
			watchdog( 'wmf_common', $error, NULL, WATCHDOG_ERROR );

			throw $ex;
		}
	}

	public function processMessageWithErrorHandling( $message ) {
		$this->logMessage( $message );
		parent::processMessageWithErrorHandling( $message );
	}

	protected function logMessage( $message ) {
		$className = preg_replace( '/.*\\\/', '', get_called_class() );
		$formattedMessage = print_r( $message, true );
		watchdog( $className, $formattedMessage, NULL, WATCHDOG_INFO );
	}

	/**
	 * Get a url to view the damaged message
	 *
	 * @param int $damagedId
	 * @return string
	 */
	public static function itemUrl( $damagedId ) {
		global $base_url;
		return "{$base_url}/damaged/{$damagedId}";
	}
}
