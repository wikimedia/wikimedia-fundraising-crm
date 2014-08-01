<?php

interface ICiviMailQueueRecord {
	/**
	 * Adds a bounce mail record and calls the CiviCRM bounce processing hooks
	 */
	function markBounced( $bounceType );

	/**
	 * Adds a 'delivered' event for this message
	 */
	function markDelivered( $time = null );

	/**
	 * Get the Variable Email Return Path header to use
	 *
	 * @returns string the VERP header
	 */
	function getVerp();

	/**
	 * Get the CiviMail database ID of this queue record
	 *
	 * @returns int
	 */
	function getQueueID();
}

class CiviMailQueueRecord implements ICiviMailQueueRecord {

	protected $queue;
	protected $emailAddress;

	/**
	 * @param CRM_Mailing_Event_DAO_Queue $queue
	 * @param string $emailAddress
	 */
	public function __construct( $queue, $emailAddress ) {
		$this->queue = $queue;
		$this->emailAddress = $emailAddress;
	}

	public function getVerp() {
		$verpAndUrls = CRM_Mailing_BAO_Mailing::getVerpAndUrls(
			$this->queue->job_id,
			$this->queue->id,
			$this->queue->hash,
			$this->emailAddress );

		return $verpAndUrls[0]['bounce'];
	}

	public function markBounced( $bounceType ) {
		//TODO
	}

	public function getQueueID() {
		return $this->queue->id;
	}

	public function markDelivered( $time = null ) {
		if ( !$time ) {
			$time = gmdate( 'YmdHis' );
		}
		$sql = "INSERT INTO civicrm_mailing_event_delivered ( event_queue_id, time_stamp ) VALUES ( %1, %2 )";
		$params = array(
			1 => array( $this->getQueueID(), 'Integer' ),
			2 => array( $time, 'String' )
		);
		CRM_Core_DAO::executeQuery( $sql, $params );
	}
}
