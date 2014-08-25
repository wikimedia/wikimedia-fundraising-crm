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
	 * Gets the id of the parent job created along with this mailing
	 *
	 * @returns int parent job id
	 */
	function getJobID();

	/**
	 * Gets the status of the parent job created along with this mailing
	 *
	 * @returns enum('Scheduled', 'Running', 'Complete', 'Paused', 'Canceled') parent job status
	 */
	function getJobStatus();
}

class CiviMailingRecord implements ICiviMailingRecord {

	protected $mailing;
	protected $job;

	/**
	 * @param CRM_Mailing_DAO_Mailing $mailing
	 * @param CRM_Mailing_DAO_Job $job
	 */
	public function __construct( $mailing, $job ) {
		$this->mailing = $mailing;
		$this->job = $job;
	}

	public function getJobID() {
		return $this->job->id;
	}

	public function getMailingID() {
		return $this->mailing->id;
	}

	public function getMailingName() {
		return $this->mailing->name;
	}

	public function getJobStatus() {
		return $this->job->status;
	}

	public function getMailing() {
		return $this->mailing;
	}
}
