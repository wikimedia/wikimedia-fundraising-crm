<?php namespace wmf_communication;

use \Exception;

/**
 * Entity representing a single mailing job batch run
 *
 * A job can be created in small, decoupled steps, and intermediate values
 * examined in the database.
 *
 * For example, here is the lifecycle of a typical job:
 *
 *     // Create an empty mailing job, which will render letters using the
 *     // specified template.
 *     $job = Job::create( 'RecurringDonationsSnafuMay2013Template' );
 *
 *     foreach ( $recipients as $contact_id => $email ) {
 *         $job
 *     // Trigger the batch run.  Execution 
 *     $job->run();
 */
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

        watchdog( 'wmf_communication',
            "Retrieving mailing job :id from the database.",
            array( ':id' => $id ),
            WATCHDOG_INFO
        );
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

    /**
     * Reserve an empty Job record and sequence number.
     *
     * @param string $templateClass mailing template classname
     *
     * TODO: other job-wide parameters and generic storage
     */
    static function create( $templateClass ) {
        $jobId = db_insert( 'wmf_communication_job' )
            ->fields( array(
                'template_class' => $templateClass,
            ) )
            ->execute();

        watchdog( 'wmf_communication',
            "Created a new job id :id, of type :template_class.",
            array(
                ':id' => $jobId,
                ':template_class' => $templateClass,
            ),
            WATCHDOG_INFO
        );
        return Job::getJob( $jobId );
    }

    /**
     * Find all queued recipients and send letters.
     */
    function run() {
        watchdog( 'wmf_communication',
            "Running mailing job ID :id...",
            array( ':id' => $this->id ),
            WATCHDOG_INFO
        );

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
                    $successful++;
                    $recipient->setSuccessful();
                } else {
                    $failed++;
                    $recipient->setFailed();
                }
            }
        }

        if ( ( $successful + $failed ) === 0 ) {
            watchdog( 'wmf_communication',
                "The mailing job (ID :id) was empty, or already completed.",
                array( ':id' => $this->id ),
                WATCHDOG_WARNING
            );
        } else {
            watchdog( 'wmf_communication',
                "Completed mailing job (ID :id), :successful letters successfully sent, and :failed failed.",
                array(
                    ':id' => $this->id,
                    ':successful' => $successful,
                    ':failed' => $failed,
                ),
                WATCHDOG_INFO
            );
        }
    }

    function getId() {
        return $this->id;
    }
}
