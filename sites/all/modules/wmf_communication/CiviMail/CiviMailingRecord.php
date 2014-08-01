<?php

interface ICiviMailingRecord {
	/**
	 * Gets the unique name for this mailing in CiviCRM
	 *
	 * @returns string mailing name
	 */
	function getMailingName();

	/**
	 * Gets the CiviCRM db ID for the mailing
	 *
	 * @returns int mailing id
	 */
	function getMailingID();

	/**
	 * Gets the id of the completed parent job created along with this mailing
	 *
	 * @returns int parent job id
	 */
	function getJobID();
}

class CiviMailingRecord implements ICiviMailingRecord {

	protected $mailingName;
	protected $mailingID;
	protected $jobID;

	public function __construct( $mailingName, $mailingID, $jobID ) {
		$this->mailingName = $mailingName;
		$this->mailingID = $mailingID;
		$this->jobID = $jobID;
	}

	public function getJobID() {
		return $this->jobID;
	}

	public function getMailingID() {
		return $this->mailingID;
	}

	public function getMailingName() {
		return $this->mailingName;
	}
}
