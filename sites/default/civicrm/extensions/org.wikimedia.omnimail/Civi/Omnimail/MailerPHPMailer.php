<?php

namespace Civi\Omnimail;

use PHPMailer;

/**
 * Use the PHPMailer engine
 */
class MailerPHPMailer extends MailerBase implements IMailer {
  function send( $email, $headers = array() ) {
    $mailer = new PHPMailer( true );

    $mailer->set( 'CharSet', 'utf-8' );
    $mailer->Encoding = 'quoted-printable';

    $mailer->AddReplyTo( $email['from_address'], $email['from_name'] );
    $mailer->SetFrom( $email['from_address'], $email['from_name'] );
    if ( !empty( $email['reply_to'] ) ) {
      $mailer->set( 'Sender', $email['reply_to'] );
    }

    // Note that this is incredibly funky.  This is the only mailer to support
    // a "to" parameter, and it behaves differently than to_address/to_name.
    // You can pass a list of bare email addresses through "to" and they'll all
    // become to addresses, but without names, so this should only be used by
    // maintenancey things directed at staff.
    if ( isset( $email['to'] ) ) {
      if ( is_string( $email['to'] ) ) {
        $email['to'] = $this->splitAddresses( $email['to'] );
      }
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
    $locale = empty( $email['locale'] ) ? null : $email['locale'];
    $mailer->MsgHTML( $this->wrapHtmlSnippet( $email['html'], $locale ) );
    $this->normalizeContent( $email );
    $mailer->AltBody = $email['plaintext'];

    return $mailer->Send();
  }
}
