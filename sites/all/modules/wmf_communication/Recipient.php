<?php namespace wmf_communication;

use \Exception;

/**
 * Link metadata between a mailing job and a CiviCRM contact
 *
 * This object contains all the information needed to generate and send a
 * letter, including template parameters. It can be queried for the current
 * delivery status.
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
     *
     * @param integer $jobId
     * @param integer $batchSize maximum number to return
     * @param integer $queuedState fetch recipients in the given state
     *
     * @return array of Recipient
     */
    static function getQueuedBatch( $jobId, $batchSize = 100, $queuedState = 'queued' ) {
        $result = db_select( 'wmf_communication_recipient' )
            ->fields( 'wmf_communication_recipient' )
            ->condition( 'job_id', $jobId )
            ->condition( 'status', $queuedState )
            ->orderBy( 'queued_id' )
            ->range( 0, $batchSize )
            ->execute();

        $recipients = array();
        while ( $row = $result->fetchAssoc() ) {
            $recipients[] = Recipient::loadFromRow( $row );
        }
        return $recipients;
    }

    /**
     * Add a CiviCRM contact to a mailing job
     *
     * @param integer $jobId
     * @param integer $contactId CiviCRM contact id
     * @param array $vars serializable template parameters
     */
    static function create( $jobId, $contactId, $vars ) {
        watchdog( 'wmf_communication',
            "Adding contact :contact_id to job :job_id, with parameters :vars",
            array(
                'job_id' => $jobId,
                'contact_id' => $contactId,
                'vars' => json_encode( $vars, JSON_PRETTY_PRINT ),
            ),
            WATCHDOG_INFO
        );
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
     * Parse database record into a Recipient object
     *
     * @param array $dbRow associative form of the db record for a single recipient
     *
     * @return Recipient
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
            'return' => 'email,display_name,first_name,last_name,preferred_language',
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

    function getFirstName() {
        return $this->contact->first_name;
    }

    function getLastName() {
        return $this->contact->last_name;
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
     *
     * If your job has custom recipient workflow states, set them using this method.
     *
     * @param string $status
     */
    function setStatus( $status ) {
        db_update( 'wmf_communication_recipient' )
            ->condition( 'contact_id', $this->contactId )
            ->condition( 'job_id', $this->jobId )
            ->fields( array(
                'status' => $status,
            ) )
            ->execute();
    }
}
