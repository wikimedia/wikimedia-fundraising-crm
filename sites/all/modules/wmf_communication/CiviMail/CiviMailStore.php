<?php
namespace wmf_communication;

use \CRM_Activity_BAO_Activity;
use \CRM_Core_DAO;
use \CRM_Core_DAO_Email;
use \CRM_Core_OptionGroup;
use \CRM_Core_Transaction;
use \CRM_Mailing_BAO_MailingJob;
use \CRM_Mailing_BAO_Mailing;
use \CRM_Mailing_DAO_MailingJob;
use \CRM_Mailing_DAO_Mailing;
use \CRM_Mailing_Event_BAO_Queue;
use \Exception;
/**
 * Handle inserting sent CiviMail records for emails
 * not actually sent by CiviCRM
 */
interface ICiviMailStore {

	/**
	 * Adds a mailing template, a mailing, and a 'sent' parent job to CiviMail
	 *
	 * @param string $source the content's system of origination (eg Silverpop, WMF)
	 * @param string $templateName the source system's template name
	 * @param string $bodyTemplate the body of the mailing
	 * @param string $subjectTemplate the subject of the mailing
	 * @param int $revision the revision the mailing
	 * @param string $jobStatus the CiviMail status of the mailing job
	 *	enum('Scheduled', 'Running', 'Complete', 'Paused', 'Canceled')
	 *
	 * We use the source, templateName and revision to create a unique name
	 *
	 * @throws CiviMailingInsertException something bad happened with the insert
	 */
	function addMailing( $source, $templateName, $bodyTemplate, $subjectTemplate, $revision = 0, $jobStatus = 'Complete' );

	/**
	 * Gets a mailing record matching the input parameters
	 *
	 * @param string $source
	 * @param string $templateName
	 * @param int $revision
	 *
	 * @returns ICiviMailingRecord
	 *
	 * @throws CiviMailingMissingException no mailing found with those parameters
	 */
	function getMailing( $source, $templateName, $revision = 0 );

	/**
	 * Adds a child job with completed date $date, a queue entry, and an entry
	 * in the recipients table
	 *
	 * @param ICiviMailingRecord $mailingRecord Mailing that is being sent
	 * @param string $email Email address of recipient
	 * @param string $date Completion date to use for child job
	 *
	 * @returns ICiviMailQueueRecord
	 *
	 * @throws CiviQueueInsertException if email isn't in Civi or an error occurs
	 */
	function addQueueRecord( $mailingRecord, $email, $date = null );

	/**
	 * Retrieves the queue record matching the parameters.
	 *
	 * @param ICiviMailingRecord $mailingRecord
	 * @param string $email recipient address
	 * @param string $date approximate original send date
	 *
	 * @returns ICiviMailQueueRecord
	 */
	function getQueueRecord( $mailingRecord, $email, $date = null );

	/**
	 * Adds an individual activity and activity target record associated with
	 * a sent email.  This is separate from inserting the queue record because
	 * of the distinction between bulk email activities which all share the same
	 * date and individual email activities
	 *
	 * @param ICiviMailQueueRecord $queueRecord record of sent email
	 * @param string $subject subject of sent email
	 * @param string $details full text of email
	 * @param string $date date of activity
	 */
	function addActivity( $queueRecord, $subject, $details, $date = null );
}

class CiviMailStore implements ICiviMailStore {

	protected static $mailings = array();
	protected static $jobs = array();
	protected static $emailActivityTypeId = -1;

	public function addMailing( $source, $templateName, $bodyTemplate, $subjectTemplate, $revision = 0, $jobStatus = 'Complete' ) {
		$name = $this::makeUniqueName( $source, $templateName, $revision );
		$mailing = $this->getMailingInternal( $name );

		$transaction = new CRM_Core_Transaction();
		try {
			if ( !$mailing ) {
				$params = array(
					'subject' => $subjectTemplate,
					'body_html' => $bodyTemplate,
					'name' => $name,
					'is_completed' => TRUE,
					//TODO: user picker on TY config page, or add 'TY mailer' contact
					'scheduled_id' => 1
				);
				$mailing = CRM_Mailing_BAO_Mailing::add( $params, CRM_Core_DAO::$_nullArray );
				self::$mailings[$name] = $mailing;
			}

			$job = $this->getJobInternal( $mailing->id );

			$saveJob = ( !$job || $job->status !== $jobStatus );

			if ( !$job ) {
				$job = new \CRM_Mailing_BAO_MailingJob();
				$job->start_date = $job->end_date = gmdate( 'YmdHis' );
				$job->job_type = 'external';
				$job->mailing_id = $mailing->id;
			}

			if ( $saveJob ) {
				$job->status = $jobStatus;
				$job->save();
				self::$jobs[$mailing->id] = $job;
			}
			$transaction->commit();
			return new CiviMailingRecord( $mailing, $job );
		}
		catch ( Exception $e ) {
			$transaction->rollback();
			$msg = "Error inserting CiviMail Mailing record $name -- {$e->getMessage()}";
			throw new CiviMailingInsertException( $msg, 0, $e );
		}
	}

	public function addQueueRecord( $mailingRecord, $emailAddress, $date = null ) {
		if ( !$date ) {
			$date = gmdate( 'YmdHis' );
		}
		$email = new CRM_Core_DAO_Email();
		$email->email = $emailAddress;

		if ( !$email->find() || !$email->fetch() ) {
			throw new CiviQueueInsertException( "No record of email $emailAddress in CiviCRM" );
		}
		//If there are multiple records for the email address, just use the first.
		//They should to be de-duped later, so no need to add extra mess.
		$transaction = new CRM_Core_Transaction();
		try {
			$childJob = $this->addChildJob( $mailingRecord, $date );
			$queue = $this->addQueueInternal( $childJob, $email );
			//add contact to recipients table
			$sql = "INSERT INTO civicrm_mailing_recipients
(mailing_id, email_id, contact_id)
VALUES ( %1, %2, %3 )";
			$params = array(
				1 => array( $mailingRecord->getMailingID(), 'Integer' ),
				2 => array( $email->id, 'Integer' ),
				3 => array( $email->contact_id, 'Integer' )
			);
			CRM_Core_DAO::executeQuery( $sql, $params );
			$transaction->commit();
		} catch ( Exception $e ) {
			$transaction->rollback();
			$msg = "Error inserting CiviMail queue entry for email $emailAddress -- {$e->getMessage()}";
			throw new CiviQueueInsertException( $msg, 0, $e );
		}
		return new CiviMailQueueRecord( $queue, $email );
	}

	public function addActivity( $queueRecord, $subject, $details, $date = null ) {
		if ( !$date ) {
			$date = gmdate( 'YmdHis' );
		}
		if ( self::$emailActivityTypeId == -1 ) {
			self::$emailActivityTypeId = CRM_Core_OptionGroup::getValue('activity_type',
				'Email',
				'name'
			);
		}
		$activity = array(
			'source_contact_id' => $queueRecord->getContactID(),
			'target_contact_id' => $queueRecord->getContactID(),
			'activity_type_id' => self::$emailActivityTypeId,
			'activity_date_time' => $date,
			'subject' => $subject,
			'details' => $details,
			'status_id' => 2,
			'deleteActivityTarget' => FALSE,
		);

		CRM_Activity_BAO_Activity::create( $activity );
	}

	public function getMailing( $source, $templateName, $revision = 0) {
		$name = $this::makeUniqueName( $source, $templateName, $revision );
		$mailing = $this->getMailingInternal( $name );
		if ( !$mailing ) {
			throw new CiviMailingMissingException();
		}
		$job = $this->getJobInternal( $mailing->id );
		// We need both.  If somehow the job wasn't created, throw
		// so the caller tries to add the mailing again.
		if ( !$job ) {
			throw new CiviMailingMissingException();
		}
		return new CiviMailingRecord( $mailing, $job );
	}

	protected function getMailingInternal( $name ) {
		if ( array_key_exists( $name, self::$mailings ) ) {
			return self::$mailings[$name];
		}
		$mailing = new CRM_Mailing_DAO_Mailing();
		$mailing->name = $name;

		if ( !$mailing->find() || !$mailing->fetch() ) {
			return null;
		}
		self::$mailings[$name] = $mailing;
		return $mailing;
	}

	protected function getJobInternal( $mailingId ) {
		if ( array_key_exists( $mailingId, self::$jobs ) ) {
			return self::$jobs[$mailingId];
		}
		$job = new \CRM_Mailing_DAO_MailingJob();
		$job->mailing_id = $mailingId;
		if ( !$job->find() || !$job->fetch() ) {
			return null;
		}
		self::$jobs[$mailingId] = $job;
		return $job;
	}

	protected function addChildJob( $mailingRecord, $date ) {
		$job = new CRM_Mailing_DAO_MailingJob();
		$job->mailing_id = $mailingRecord->getMailingID();
		$job->parent_id = $mailingRecord->getJobID();
		$job->status = 'Complete';
		$job->jobType = 'child';
		$job->job_limit = 1;
		$job->start_date = $job->end_date = $date;
		$job->save();
		return $job;
	}

	protected function addQueueInternal( $job, $email ) {
		$params = array(
			'mailing_id' => $job->mailing_id,
			'job_id' => $job->id,
			'email_id' => $email->id,
			'contact_id' => $email->contact_id,
		);
		$queue = CRM_Mailing_Event_BAO_Queue::create( $params );
		return $queue;
	}

	public function getQueueRecord( $mailingRecord, $email, $date = null ) {
		//TODO: will use this for Silverpop, but not needed for TY emails
	}

	public static function makeUniqueName( $source, $templateName, $revision ) {
		return "$source|$templateName|$revision";
	}
}

class CiviMailingMissingException extends Exception {}
class CiviMailingInsertException extends Exception {}
class CiviQueueInsertException extends Exception {}
