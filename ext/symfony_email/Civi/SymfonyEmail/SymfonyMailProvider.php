<?php

namespace Civi\SymfonyEmail;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class SymfonyMailProvider implements SymfonyMailInterface {

  /**
   * @return \Symfony\Component\Mailer\MailerInterface
   */
  public function getMailer(): MailerInterface {
    if (\CRM_Utils_Constant::value('CIVICRM_SMTP_HOST')) {
      $dsn = \CRM_Utils_Constant::value('CIVICRM_SMTP_HOST');
    }
    else {
      $dsn = \Civi::settings()->get('symfony_email_dsn');
    }
    $dsns = $this->normalizeDsns($dsn);

    $transport = count($dsns) > 1
      ? Transport::fromDsns($dsns)
      : Transport::fromDsn(reset($dsns));
    return new Mailer($transport);
  }

  /**
   * @return \Symfony\Component\Mime\Email
   */
  public function getEmail(): Email {
    return new Email();
  }

  private static function normalizeDsns(string $dsn): array {
    return array_values(array_filter(array_map(
      static function (string $dsn): string {
        $dsn = trim($dsn);

        if (str_starts_with($dsn, 'tls://')) {
          return 'smtp://' . substr($dsn, 6) . '?encryption=tls';
        }

        if (str_starts_with($dsn, 'ssl://')) {
          return 'smtps://' . substr($dsn, 6);
        }

        if (!preg_match(';^[a-z][a-z0-9+\-.]*://;i', $dsn)) {
          $dsn = 'smtp://' . $dsn;
        }

        if (str_starts_with($dsn, 'smtp://') && !str_contains($dsn, '?')) {
          return $dsn . '?auto_tls=false';
        }

        return $dsn;
      },
      explode(';', $dsn)
    )));
  }

}
