<?php

/**
 * Handle inserting sent CiviMail records for bulk emails
 * not actually sent by CiviCRM
 */
interface ICiviMailBulkStore extends ICiviMailStore {
	/**
	 * Inserts sent mail records in CiviMail to track a mailing sent externally
	 * Adds queue entries, mailing recipients, sent events, and activity records
	 *
	 * @param ICiviMailingRecord $mailingRecord the mailing that was sent out
	 * @param array $addresses list of email addresses that received the mailing
	 * @param string $date MySQL formatted date of mailing
	 */
	function addSentBulk( $mailingRecord, $addresses, $date = null );
}

class CiviMailBulkStore extends CiviMailStore implements ICiviMailBulkStore {

	public function addSentBulk( $mailingRecord, $addresses, $date = null ) {
		if ( !$date ) {
			$date = gmdate( 'YmdHis' );
		}

		$batches = array();
		$emails = array();
		$found = 0;

		// Join to queue table to determine whether we are re-importing a mailing
		$query = 'SELECT e.id AS email_id, e.contact_id, q.id AS queue_id
FROM civicrm_email e
LEFT JOIN civicrm_mailing_event_queue q
	ON q.email_id = e.id
	AND q.contact_id = e.contact_id
	AND q.job_id IN ( SELECT id FROM civicrm_mailing_job WHERE mailing_id = %1 )
WHERE e.email = %2';

		foreach ( $addresses as $address ) {
			$params = array(
				1 => array( $mailingRecord->getMailingID(), 'Integer' ),
				2 => array( $address, 'String' ),
			);

			$dao = CRM_Core_DAO::executeQuery( $query, $params );

			if ( !$dao->fetch() ) {
				watchdog( WATCHDOG_WARNING, "Email '$address' not found in CiviCRM");
				continue;
			}

			if ( $dao->queue_id ) {
				// already created a record for this address, so skip it
				continue;
			}

			$emails[] = array(
				'email_id' => $dao->email_id,
				'contact_id' => $dao->contact_id
			);
			$found++;
			if ( $found % CRM_Core_DAO::BULK_INSERT_COUNT == 0 ) {
				$batches[] = $emails;
				$emails = array();
			}
		}

		// add the last batch.
		if ( !empty( $emails ) ) {
			$batches[] = $emails;
		}

		try {
			foreach( $batches as $batch ) {
				$this->addSentBatch( $mailingRecord, $batch, $date );
			}
			$mailing = $mailingRecord->getMailing();
			$mailing->status = 'Complete';
			$mailing->save();
		}
		catch ( CiviMailBulkInsertException $ex ) {
			watchdog_exception( WATCHDOG_WARNING, $ex, "Error importing mailing with ID {$mailingRecord->getMailingID()}" );
		}
	}

	/**
	 * Adds a batch of contacts to recipients, queue and delivered events,
	 * bulk mail activity and activity targets.
	 *
	 * @param ICiviMailingRecord $mailingRecord
	 * @param array $emails each should have an email_id and a contact_id
	 * @param string $date MySQL formatted date of send
	 *
	 * @throws CiviMailBulkInsertException
	 */
	protected function addSentBatch( $mailingRecord, $emails, $date ) {
		// implicitly committed when it goes out of scope
		$transaction = new CRM_Core_Transaction();
		try {
			$childJob = $this->addChildJob( $mailingRecord, $date );
			$makeParam = function( $email ) use ( $childJob ) {
				return array (
					$childJob->id,
					$email['email_id'],
					$email['contact_id'],
					"null",
				);
			};
			$queueParams = array_map( $makeParam, $emails );

			CRM_Mailing_Event_BAO_Queue::bulkCreate( $queueParams, $date );
			$this->insertRecipients( $mailingRecord->getMailingID(), $childJob->id );

			// add delivered event and activities with CRM_Mailing_BAO_Job::writeToDB
			$queueIDs = array();
			$contacts = array();

			$queueQuery = 'SELECT q.id, q.contact_id, e.email, q.hash, NULL as phone
FROM civicrm_mailing_event_queue q
INNER JOIN civicrm_email e ON q.email_id = e.id
WHERE q.job_id = %1';

			$param = array( 1 => array ( $childJob->id, 'Integer' ) );

			$queueEntity = CRM_Core_DAO::executeQuery( $queueQuery, $param, TRUE, 'CRM_Mailing_Event_BAO_Queue' );

			while( $queueEntity->fetch() ) {
				$queueIDs[] = $queueEntity->id;
				$contacts[] = $queueEntity->contact_id;
			}

			// TODO: must be a better way to get a BAO from a DAO
			$jobBao = new CRM_Mailing_BAO_Job();
			$jobParams = array(
				'id' => $childJob->id,
				'mailing_id' => $childJob->mailing_id
			);
			$jobBao->copyValues( $jobParams );
			$mailing = $mailingRecord->getMailing();
			$success = $jobBao->writeToDB(
				$queueIDs,
				$contacts,
				$mailing,
				$date
			);

			if ( !$success ) {
				throw new CiviMailBulkInsertException( "CRM_Mailing_BAO_Job::writeToDB failed" );
			}
		} catch ( Exception $ex ) {
			$transaction->rollback();
			throw new CiviMailBulkInsertException( "Error adding events or activity record for mailing {$mailingRecord->getMailingID()}", 0, $ex );
		}
	}

	/**
	 * Ensure that all addresses in the job queue for a mailing have a
	 * corresponding record in the mailing recipients table.
	 * Should not create duplicate records.
	 *
	 * @param int $mailingID ID of the sent mailing
	 * @param int $jobID ID of the child job
	 */
	protected function insertRecipients( $mailingID, $jobID ) {
		$recipientInsert = 'INSERT INTO civicrm_mailing_recipients
(mailing_id, email_id, contact_id)
SELECT %1, q.email_id, q.contact_id
FROM civicrm_mailing_event_queue q
LEFT JOIN civicrm_mailing_recipients r
	ON r.mailing_id = %1
	AND r.email_id = q.email_id
	AND r.contact_id = q.contact_id
WHERE q.job_id = %2
AND r.id IS NULL';
		$params = array(
			1 => array( $mailingID, 'Integer' ),
			2 => array( $jobID, 'Integer' ),
		);
		CRM_Core_DAO::executeQuery( $recipientInsert, $params );
	}
}

class CiviMailBulkInsertException extends Exception { }
