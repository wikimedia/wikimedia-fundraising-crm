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
    $transport = Transport::fromDsn('smtp://mailcatcher:1025');
    return new Mailer($transport);
  }

  /**
   * @return \Symfony\Component\Mime\Email
   */
  public function getEmail(): Email {
    return new Email();
  }

}
