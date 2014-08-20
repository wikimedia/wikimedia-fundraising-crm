<?php

interface ICiviMailQueueRecord {
	/**
	 * Adds a bounce mail record and calls the CiviCRM bounce processing hooks
	 */
	function markBounced( $bounceType );

	/**
	 * Adds a 'delivered' event for this message
	 */
	function markDelivered( $date = null );

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

	/**
	 * Get the CiviCRM Contact ID of this queue record
	 *
	 * @returns int
	 */
	function getContactID();

	/**
	 * Get the CiviCRM Email ID of this queue record
	 *
	 * @returns int
	 */
	function getEmailID();
}

class CiviMailQueueRecord implements ICiviMailQueueRecord {

	protected $queue;
	protected $emailAddress;

	/**
	 * @param CRM_Mailing_Event_DAO_Queue $queue
	 * @param CRM_Core_Email $email
	 */
	public function __construct( $queue, $email ) {
		$this->queue = $queue;
		$this->email = $email;
	}

	public function getVerp() {
		$verpAndUrls = CRM_Mailing_BAO_Mailing::getVerpAndUrls(
			$this->queue->job_id,
			$this->queue->id,
			$this->queue->hash,
			$this->email->email );

		return $verpAndUrls[0]['bounce'];
	}

	public function markBounced( $bounceType, $date = null ) {
		//TODO
	}

	public function getQueueID() {
		return $this->queue->id;
	}

	public function getContactID() {
		return $this->email->contact_id;
	}

	public function getEmailID() {
		return $this->email->id;
	}

	public function markDelivered( $date = null ) {
		if ( !$date ) {
			$date = gmdate( 'YmdHis' );
		}
		$sql = "INSERT INTO civicrm_mailing_event_delivered ( event_queue_id, time_stamp ) VALUES ( %1, %2 )";
		$params = array(
			1 => array( $this->getQueueID(), 'Integer' ),
			2 => array( $date, 'String' )
		);
		CRM_Core_DAO::executeQuery( $sql, $params );
	}
}
