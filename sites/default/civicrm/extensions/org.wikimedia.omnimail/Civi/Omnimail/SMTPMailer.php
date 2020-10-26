<?php

namespace Civi\Omnimail;

use wmf_communication\MailerBase;

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
  public function getSmtpHost(): string
  {
    return $this->smtpHost;
  }

  /**
   * @param string $smtpHost
   */
  public function setSmtpHost(string $smtpHost): void
  {
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
   * @throws \WmfException
   * @throws \phpmailerException
   */
  public function send($email, $headers = array()) {
    $mailer = new \PHPMailer(TRUE);
    $mailer->IsSMTP();
    $mailer->Host = $this->smtpHost;

    // From here - copied from PhpMailer - perhaps some of it is obsolete now....
    $mailer->set('CharSet', 'utf-8');
    $mailer->Encoding = 'quoted-printable';

    $mailer->AddReplyTo($email['from_address'], $email['from_name']);
    $mailer->SetFrom($email['from_address'], $email['from_name']);
    if (!empty($email['reply_to'])) {
      $mailer->set('Sender', $email['reply_to']);
    }

    $mailer->AddAddress($email['to_address'], $email['to_name']);

    foreach ($headers as $header => $value) {
      $mailer->AddCustomHeader("$header: $value");
    }

    $mailer->Subject = $email['subject'];
    # n.b. - must set AltBody after MsgHTML(), or the text will be overwritten.
    $locale = empty($email['locale']) ? null : $email['locale'];
    $mailer->MsgHTML($this->wrapHtmlSnippet($email['html'], $locale));
    $this->normalizeContent($email);
    $mailer->AltBody = $email['plaintext'];
    // End copy-pasta

    // WMF specific settings
    $mailer->SMTPOptions = [
      // Our cert doesn't match the internal hostname
      'ssl' => [
        'verify_peer_name' => false,
      ],
    ];
    // Seconds - default is 300.
    $mailer->Timeout = 10;
    // Jeff suggests we don't want to advertise every module we use
    $mailer->XMailer = ' ';
    // end WMF.
    return $mailer->Send();
  }

}
