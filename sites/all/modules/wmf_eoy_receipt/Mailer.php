<?php

namespace wmf_eoy_receipt;

class Mailer {
    function __construct() {
        require_once implode(DIRECTORY_SEPARATOR, array(variable_get('wmf_common_phpmailer_location', ''), 'class.phpmailer.php'));
    }

    function send( $email ) {
        $mailer = new \PHPMailer( true );

        $mailer->set( 'Charset', 'utf-8' );

        $mailer->AddReplyTo( $email[ 'from_address' ], $email[ 'from_name' ] );
        $mailer->SetFrom( $email[ 'from_address' ], $email[ 'from_name' ] );

        $mailer->AddAddress( $email[ 'to_address' ], $email[ 'to_name' ] );

        $mailer->Subject = $email[ 'subject' ];
        $mailer->AltBody = $email[ 'plaintext' ];
        $mailer->MsgHTML( $email[ 'html' ] );

        $success = $mailer->Send();
    }
}
