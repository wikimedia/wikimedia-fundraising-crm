<?php namespace wmf_communication;

use Html2Text\Html2Text;

/**
 * Must be implemented by every mailing engine
 */
interface IMailer {
    /**
     * Enqueue an email into the external mailing system
     *
     * @param array $email  All keys are required:
     *    from_address
     *    from_name
     *    html
     *    plaintext
     *    reply_to
     *    subject
     *    to_address
     *    to_name
     *
     * @return boolean True if the mailing system accepted your message for delivery
     */
    function send( $email );
}

/**
 * Weird factory to get the default concrete Mailer.
 */
class Mailer {
    static public $defaultSystem = 'phpmailer';
    /**
     * RTL languages, comes from http://en.wikipedia.org/wiki/Right-to-left#RTL_Wikipedia_languages
     * TODO: move to the LanguageTag module once that's available from here.
     */
    static public $rtlLanguages = array(
        'ar',
        'arc',
        'bcc',
        'bqi',
        'ckb',
        'dv',
        'fa',
        'glk',
        'he',
        'ku',
        'mzn',
        'pnb',
        'ps',
        'sd',
        'syc',
        'ug',
        'ur',
        'yi',
    );

    /**
     * Get the default Mailer
     *
     * @return IMailer instantiated default Mailer
     */
    static public function getDefault() {
        switch ( self::$defaultSystem ) {
        case 'phpmailer':
            return new MailerPHPMailer();
        case 'drupal':
            return new MailerDrupal();
        case 'test':
            return new TestMailer();
        default:
            throw new Exception( "Unknown mailer requested: " . self::$defaultSystem );
        }
    }

    /**
     * Wrap raw HTML in a full document
     *
     * This is necessary to convince recalcitrant mail clients that we are
     * serious about the character encoding.
     *
     * @param string $html
     *
     * @return string
     */
    static public function wrapHtmlSnippet( $html, $locale = null ) {
        if ( preg_match( '/<html.*>/i', $html ) ) {
            watchdog( 'wmf_communication',
                "Tried to wrap something that already contains a full HTML document.",
                NULL, WATCHDOG_ERROR );
            return $html;
        }

        $langClause = '';
        $bodyStyle = '';
        if ( $locale ) {
            $langClause = "lang=\"{$locale}\"";

            $localeComponents = explode( '-', $locale );
            $bareLanguage = $localeComponents[0];
            if ( in_array( $bareLanguage, self::$rtlLanguages ) ) {
                $bodyStyle = 'style="text-align:right; direction:rtl;"';
            }
        }

        return "
<html {$langClause}>
<head>
    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
</head>
<body {$bodyStyle}>
{$html}
</body>
</html>";
    }

    function normalizeContent( &$email ) {
        $converter = new Html2Text( $email['html'], false, array( 'do_links' => 'table' ) );
        $email['plaintext'] = $converter->get_text();

        if ( $email['plaintext'] === false ) {
            watchdog( 'thank_you', "Text rendering of template failed in {$email['locale']}.", array(), WATCHDOG_ERROR );
            throw new WmfException( 'RENDER', "Could not render plaintext" );
        }
    }
}

/**
 * Use the PHPMailer engine
 */
class MailerPHPMailer implements IMailer {
    function __construct() {
        $path = implode(DIRECTORY_SEPARATOR, array(variable_get('wmf_common_phpmailer_location', ''), 'class.phpmailer.php'));
        watchdog( 'wmf_communication',
            "Loading PHPMailer class from :path",
            array( ':path' => $path ),
            WATCHDOG_INFO
        );
        require_once( $path );
    }

    function send( $email, $headers = array() ) {
        watchdog( 'wmf_communication',
            "Sending an email to :to_address, using PHPMailer",
            array( ':to_address' => $email['to_address'] ),
            WATCHDOG_DEBUG
        );

        $mailer = new \PHPMailer( true );

        $mailer->set( 'CharSet', 'utf-8' );
        $mailer->Encoding = 'quoted-printable';

        $mailer->AddReplyTo( $email['from_address'], $email['from_name'] );
        $mailer->SetFrom( $email['from_address'], $email['from_name'] );
        $mailer->set( 'Sender', $email['reply_to'] );

        if ( isset( $email['to'] ) ) {
            foreach ( $email['to'] as $to ) {
                $mailer->AddAddress( $to );
            }
        } else {
            $mailer->AddAddress( $email['to_address'], $email['to_name'] );
        }

		foreach ($headers as $header => $value) {
			$mailer->AddCustomHeader( "$header: $value" );
		}

        $mailer->Subject = $email['subject'];
        # n.b. - must set AltBody after MsgHTML(), or the text will be overwritten.
        $mailer->MsgHTML( Mailer::wrapHtmlSnippet( $email['html'], $email['locale'] ) );
        $this->normalizeContent( $email );
        $mailer->AltBody = $email['plaintext'];

        $success = $mailer->Send();

        return $success;
    }
}

/**
 * Use the Drupal mailing system
 */
class MailerDrupal implements IMailer {
    function send( $email ) {
        $from = "{$email['from_name']} <{$email['from_address']}>";

        $headers = array(
            'From' => $from,
            'Sender' => $email['reply_to'],
            'Return-Path' => $from,
        );

        $this->normalizeContent( $email );
        $body = $this->formatTwoPart( Mailer::wrapHtmlSnippet( $email['html'] ), $email['plaintext'], $headers );

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
