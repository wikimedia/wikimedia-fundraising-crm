<?php

namespace Civi\Omnimail;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Use the PHPMailer engine
 *
 * @deprecated
 */
class MailerPHPMailer extends MailerBase implements IMailer {

  /**
   * @param array $email
   * @param array $headers
   *
   * @return bool
   * @throws \PHPMailer\PHPMailer\Exception
   */
  function send($email, $headers = []) {
    $mailer = new PHPMailer(TRUE);

    $mailer->set('CharSet', 'utf-8');
    $mailer->Encoding = 'quoted-printable';

    $mailer->addReplyTo($email['from_address'], $email['from_name']);
    $mailer->setFrom($email['from_address'], $email['from_name']);
    if (!empty($email['reply_to'])) {
      $mailer->set('Sender', $email['reply_to']);
    }

    // Note that this is incredibly funky.  This is the only mailer to support
    // a "to" parameter, and it behaves differently than to_address/to_name.
    // You can pass a list of bare email addresses through "to" and they'll all
    // become to addresses, but without names, so this should only be used by
    // maintenancey things directed at staff.
    if (isset($email['to'])) {
      if (is_string($email['to'])) {
        $email['to'] = $this->splitAddresses($email['to']);
      }
      foreach ($email['to'] as $to) {
        $mailer->addAddress($to);
      }
    }
    else {
      $mailer->addAddress($email['to_address'], $email['to_name']);
    }

    foreach ($headers as $header => $value) {
      $mailer->addCustomHeader("$header: $value");
    }

    $mailer->Subject = $email['subject'];
    # n.b. - must set AltBody after MsgHTML(), or the text will be overwritten.
    $locale = empty($email['locale']) ? NULL : $email['locale'];
    $mailer->msgHTML($this->wrapHtmlSnippet($email['html'], $locale));
    $mailer->AltBody = \CRM_Utils_String::htmlToText($email['html']);

    return $mailer->send();
  }

}
