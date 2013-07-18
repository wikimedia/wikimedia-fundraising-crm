<?php namespace wmf_communication;

use \Exception;

/**
 * Record linking a job and CiviCRM contact, contains all information needed to
 * generate and send a letter, and current delivery status.
 */
class Recipient {
    protected $contactId;
    protected $jobId;
    protected $status;
    
    /**
     * @var array additional parameters passed to the letter body template
     */
    protected $vars = array();

    /**
     * Get recipients for this job, in the "queued" status, up to $batchSize.
     *
     * Call this repeatedly until empty set is returned.
     */
    static function getQueuedBatch( $jobId, $batchSize = 100 ) {
        $result = db_select( 'wmf_communication_recipient' )
            ->fields( 'wmf_communication_recipient' )
            ->condition( 'job_id', $jobId )
            ->condition( 'status', 'queued' )
            ->range( 0, $batchSize )
            ->execute();

        $recipients = array();
        while ( $row = $result->fetchAssoc() ) {
            $recipients[] = Recipient::loadFromRow( $row );
        }
        return $recipients;
    }

    static function create( $jobId, $contactId, $vars ) {
        db_insert( 'wmf_communication_recipient' )
            ->fields( array(
                'job_id' => $jobId,
                'contact_id' => $contactId,
                'status' => 'queued',
                'vars' => json_encode( $vars ),
            ) )
            ->execute();
    }

    /**
     * @param array $dbRow associative form of the db record for a single recipient
     */
    static protected function loadFromRow( $dbRow ) {
        $recipient = new Recipient();

        $recipient->contactId = $dbRow['contact_id'];
        $recipient->jobId = $dbRow['job_id'];
        $recipient->status = $dbRow['status'];

        if ( $dbRow['vars'] ) {
            $recipient->vars = json_decode( $dbRow['vars'], true );
            if ( $recipient->vars === null ) {
                throw new Exception( 'Could not decode serialized vars, error code: ' . json_last_error() );
            }
        }

        // FIXME: Get contact details en masse.
        // TODO: Maybe we want to decouple from the civi db, and keep all necessary contact
        // info in the recipient table.
        $api = civicrm_api_classapi();
        $success = $api->Contact->get( array(
            'id' => $recipient->contactId,
            'return' => 'email,display_name,preferred_language',
            'version' => 3,
        ) );
        if ( !$success ) {
            throw new Exception( $api->errorMsg() );
        }
        $values = $api->values();
        if ( $values ) {
            $recipient->contact = array_pop( $values );
        } else {
            throw new Exception( 'Tried to email a non-existent contact, ' . $recipient->contactId );
        }

        return $recipient;
    }

    function getEmail() {
        return $this->contact->email;
    }

    function getName() {
        return $this->contact->display_name;
    }

    function getLanguage() {
        return Translation::normalize_language_code( $this->contact->preferred_language );
    }

    function getVars() {
        return $this->vars;
    }

    function setFailed() {
        $this->setStatus( 'failed' );
    }

    function setSuccessful() {
        $this->setStatus( 'successful' );
    }

    /**
     * Usually, you will want to use a specific accessor like setFailed, above.
     */
    function setStatus( $status ) {
        db_update( 'wmf_communication_recipient' )
            ->fields( array(
                'status' => $status
            ) )
            ->execute();
    }
}
