<?php namespace wmf_communication;

interface IMailer {
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
    function send( $email );
}

class Mailer {
    static public $defaultSystem = 'phpmailer';

    static public function getDefault() {
        switch ( self::$defaultSystem ) {
        case 'phpmailer':
            return new MailerPHPMailer();
        case 'drupal':
            return new MailerDrupal();
        default:
            throw new Exception( "Unknown mailer requested: " . self::$defaultSystem );
        }
    }
}

class MailerPHPMailer implements IMailer {
    function __construct() {
        $path = implode(DIRECTORY_SEPARATOR, array(variable_get('wmf_common_phpmailer_location', ''), 'class.phpmailer.php'));
        watchdog( 'wmf_communication', t( "Loading PHPMailer class from :path", array( ':path' => $path ) ), WATCHDOG_INFO );
        require_once( $path );
    }

    function send( $email ) {
        watchdog( 'wmf_communication', t( "Sending an email to :to_address, using PHPMailer", array( ':to_address' => $email['to_address'] ) ), WATCHDOG_DEBUG );

        $mailer = new \PHPMailer( true );

        $mailer->set( 'CharSet', 'utf-8' );

        $mailer->AddReplyTo( $email['from_address'], $email['from_name'] );
        $mailer->SetFrom( $email['from_address'], $email['from_name'] );
        $mailer->set( 'Sender', $email['reply_to'] );

        $mailer->AddAddress( $email['to_address'], $email['to_name'] );

        $mailer->Subject = $email['subject'];
        $mailer->AltBody = $email['plaintext'];
        $mailer->MsgHTML( $email['html'] );

        $success = $mailer->Send();

        return $success;
    }
}

class MailerDrupal implements IMailer {
    function send( $email ) {
        $from = "{$email['from_name']} <{$email['from_address']}>";

        $headers = array(
            'From' => $from,
            'Sender' => $email['reply_to'],
            'Return-Path' => $from,
        );

        $body = $this->formatTwoPart( $email['html'], $email['plaintext'], $headers );

        $message = array(
            'id' => 'wmf_communication_generic',
            'to' => "{$email['to_name']} <{$email['to_address']}>",
            'subject' => $email['subject'],
            'body' => $body,
            'headers' => $headers,
        );

        $mailsys = drupal_mail_system( 'wmf_communication', 'generic' );
        $success = $mailsys->mail( $message );

        return $success;
    }

    protected function formatTwoPart( $html, $txt, &$headers ) {
        $boundary = uniqid('wmf');

        $headers['MIME-Version'] = '1.0';
        $headers['Content-Type'] = "multipart/alternative;boundary={$boundary}";

        $body = "
This is a MIME-encoded message.
--{$boundary}
Content-type: text/plain;charset=utf-8

{$txt}

--{$boundary}
Content-type: text/html;charset=utf-8

$html

--{$boundary}--";

        return $body;
    }
}
