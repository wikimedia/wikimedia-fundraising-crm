<?php

namespace Civi\Omnimail;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class SMTPMailer extends MailerBase implements IMailer {

  /**
   * The SMTP Host to use.
   *
   * @var string
   */
  protected $smtpHost;

  /**
   * @return string
   */
  public function getSmtpHost(): string {
    return $this->smtpHost;
  }

  /**
   * @param string $smtpHost
   */
  public function setSmtpHost(string $smtpHost): void {
    $this->smtpHost = $smtpHost;
  }

  /**
   * Note this is heavily copied from the WMF php mailer class.
   *
   * A later goal would be to cut the apron strings.
   *
   * @param array $email
   * @param array $headers
   *
   * @return bool
   *
   * @throws \Civi\WMFException\WMFException
   * @throws Exception
   */
  public function send($email, $headers = []) {
    $mailer = new PHPMailer(TRUE);
    $mailer->isSMTP();
    $mailer->Host = $this->smtpHost;

    // From here - copied from PhpMailer - perhaps some of it is obsolete now....
    $mailer->set('CharSet', 'utf-8');
    $mailer->Encoding = 'quoted-printable';

    $mailer->addReplyTo($email['from_address'], $email['from_name']);
    $mailer->setFrom($email['from_address'], $email['from_name']);
    if (!empty($email['reply_to'])) {
      $mailer->set('Sender', $email['reply_to']);
    }

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
    // End copy-pasta

    // WMF specific settings
    $mailer->SMTPOptions = [
      // Our cert doesn't match the internal hostname
      'ssl' => [
        'verify_peer_name' => FALSE,
      ],
    ];
    // Seconds - default is 300.
    $mailer->Timeout = 10;
    // Jeff suggests we don't want to advertise every module we use
    $mailer->XMailer = ' ';
    // end WMF.
    return $mailer->send();
  }

}
