<?php
/**
 * TODO: really decouple from implementation
 */

namespace wmf_communication;

class Mailer {
    function __construct() {
        require_once implode(DIRECTORY_SEPARATOR, array(variable_get('wmf_common_phpmailer_location', ''), 'class.phpmailer.php'));
    }

    /**
     * @param array $email  All keys are required:
     *    from_address
     *    from_name
     *    html
     *    plaintext
     *    reply_to
     *    subject
     *    to_address
     *    to_name
     */
    function send( $email ) {
        $mailer = new \PHPMailer( true );

        $mailer->set( 'Charset', 'utf-8' );

        $mailer->AddReplyTo( $email[ 'from_address' ], $email[ 'from_name' ] );
        $mailer->SetFrom( $email[ 'from_address' ], $email[ 'from_name' ] );
        $mailer->set( 'Sender', $email[ 'reply_to' ] );

        $mailer->AddAddress( $email[ 'to_address' ], $email[ 'to_name' ] );

        $mailer->Subject = $email[ 'subject' ];
        $mailer->AltBody = $email[ 'plaintext' ];
        $mailer->MsgHTML( $email[ 'html' ] );

        $success = $mailer->Send();

        return $success;
    }
}
