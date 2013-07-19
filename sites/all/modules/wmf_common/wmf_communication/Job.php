<?php namespace wmf_communication;

use \Exception;

class Job {
    protected $id;
    protected $template;

    /**
     * Pull a job from the database.
     *
     * @param integer $id  The job's database ID
     */
    static function getJob( $id ) {
        $job = new Job();
        $job->id = $id;

        watchdog( 'wmf_communication', t( "Retrieving mailing job :id from the database.", array( ':id' => $id ) ), WATCHDOG_INFO );
        $row = db_select( 'wmf_communication_job' )
            ->fields( 'wmf_communication_job' )
            ->condition( 'id', $id )
            ->execute()
            ->fetchAssoc();

        if ( !$row ) {
            throw new Exception( 'No such job found: ' . $id );
        }

        $templateClass = $row['template_class'];
        if ( !class_exists( $templateClass ) ) {
            throw new Exception( 'Could not find mailing template class: ' . $templateClass );
        }
        $job->template = new $templateClass;
        if ( !( $job->template instanceof IMailingTemplate ) ) {
            throw new Exception( 'Mailing template class must implement IMailingTemplate' );
        }

        return $job;
    }

    static function create( $templateClass ) {
        $jobId = db_insert( 'wmf_communication_job' )
            ->fields( array(
                'template_class' => $templateClass,
            ) )
            ->execute();

        return Job::getJob( $jobId );
    }

    /**
     * Find all queued recipients and send letters.
     */
    function run() {
        watchdog( 'wmf_communication', t( "Running mailing job ID :id...", array( ':id' => $this->id ) ), WATCHDOG_INFO );

        $mailer = Mailer::getDefault();
        $successful = 0;
        $failed = 0;

        while ( $recipients = Recipient::getQueuedBatch( $this->id ) ) {
            foreach ( $recipients as $recipient ) {
                $bodyTemplate = $this->template->getBodyTemplate( $recipient );

                $email = array(
                    'from_name' => $this->template->getFromName(),
                    'from_address' => $this->template->getFromAddress(),
                    'reply_to' => $this->template->getFromAddress(),
                    'to_name' => $recipient->getName(),
                    'to_address' => $recipient->getEmail(),
                    'subject' => $this->template->getSubject( $recipient ),
                    'plaintext' => $bodyTemplate->render( 'txt' ),
                    'html' => $bodyTemplate->render( 'html' ),
                );

                $success = $mailer->send( $email );

                if ( $success ) {
                    $recipient->setSuccessful();
                } else {
                    $recipient->setFailed();
                }
            }
        }

        if ( ( $successful + $failed ) === 0 ) {
            watchdog( 'wmf_communication', t( "The mailing job (ID :id) was empty, or already completed.", array( ':id' => $this->id ) ), WATCHDOG_WARNING );
        } else {
            watchdog( 'wmf_communication',
                t( "Completed mailing job (ID :id), :successful letters successfully sent, and :failed failed.",
                    array( ':id' => $this->id, ':successful' => $successful, ':failed' => $failed )
                ),
                WATCHDOG_INFO );
        }
    }

    function getId() {
        return $this->id;
    }
}
