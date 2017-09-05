<?php namespace queue2civicrm\unsubscribe;

use wmf_common\TransactionalWmfQueueConsumer;
use CRM_Core_DAO;
use WmfException;


class UnsubscribeQueueConsumer extends TransactionalWmfQueueConsumer {

	/**
	 * Processes an individual unsubscribe message. The message must contain the email address and
	 * the contribution ID. The contribution ID is required because, as a protection mechanism, we want
	 * unsubscribe emails to be single shot. Therefore we obtain the contact ID from the contribution ID
	 * (in case someone has gone through and de-duped contacts since the email was sent) and check its
	 * unsubscribe status taking one of two actions after:
	 *
	 * - If a contact has already opted out we abort and do no further action.
	 * - Otherwise, we opt out that contact and then do a Civi search for all matching emails - opting
	 *   out those contacts as well.
	 *
	 * @param array $message.
	 *
	 */
	function processMessage( $message ) {

		// Sanity checking :)
		if ( empty( $message['email'] ) or empty( $message['contribution-id'] ) ) {
			$error = "Required field not present! Dropping message on floor. Message: " . json_encode( $message );
			throw new WmfException( 'UNSUBSCRIBE', $error );
		}

		$emails = array( strtolower( $message['email'] ) );
		$contribId = $message['contribution-id'];
		watchdog( 'unsubscribe', "$contribId: Acting on contribution ID", array(),
			WATCHDOG_INFO );

		// Find the contact from the contribution ID
		$contacts = $this->getEmailsFromContribution( $contribId );

		if ( count( $contacts ) === 0 ) {
			watchdog( 'unsubscribe',
				"$contribId: No contacts returned for contribution ID. Acking frame and returning.",
				array(),
				WATCHDOG_NOTICE );
		} else {
			// Excellent -- we have a collection of emails to unsubscribe now! :) Check opt out status and add them to the array
			foreach ( $contacts as $contact ) {
				if ( $contact['is_opt_out'] == TRUE )
				{
					watchdog( 'unsubscribe',
						"$contribId: Contact already opted out with this contribution ID.",
						array(),
						WATCHDOG_NOTICE );
					continue;
				}
				$email = strtolower( $contact['email'] );
				if ( !in_array( $email, $emails ) ) {
					$emails[] = $email;
				}
			}

			// And opt them out
			$count = $this->optOutEmails( $emails );
			watchdog( 'unsubscribe', "$contribId: Successfully updated $count rows." );
		}
	}

	/**
	 * Obtains a list of arrays of (contact ID, is opt out, email address) for
	 * the contact specified by the given contribution.
	 *
	 * @param int $contributionId  The Civi contribution ID
	 *
	 * @return array
	 */
	function getEmailsFromContribution( $contributionId) {
		$query = "
			SELECT con.id, con.is_opt_out, e.email
			FROM civicrm_contribution ct, civicrm_contact con
			LEFT JOIN civicrm_email e
			  ON con.id = e.contact_id
			WHERE ct.id = %1 AND ct.contact_id = con.id";

		$dao = CRM_Core_DAO::executeQuery( $query, array(
			1 => array( $contributionId, 'Integer' ),
		) );

		$out = array();
		while ( $dao->fetch() ) {
			$out[] = array(
				'contact_id' => (int)$dao->id,
				'is_opt_out' => (bool)$dao->is_opt_out,
				'email' => $dao->email,
			);
		}
		return $out;
	}

	/**
	 * Updates the Civi database with an opt out record for the specified email address
	 *
	 * @param array   $emails   Email addresses to unsubscribe
	 *
	 * @returns Number of affected rows
	 */
	function optOutEmails( $emails) {
		$escaped = array();
		foreach ($emails as $email) {
			$escaped[] = "'" . addslashes( $email ) . "'";
		}
		$email_condition = 'e.email IN (' . implode(', ', $escaped) . ')';

		$query = <<<EOS
UPDATE civicrm_contact con, civicrm_email e
    SET con.is_opt_out = 1
    WHERE con.id = e.contact_id AND {$email_condition}
EOS;

		$dbs = wmf_civicrm_get_dbs();
		$dbs->push( 'civicrm' );

		$result = db_query( $query );
		return $result->rowCount();
	}

}



