<?php
namespace Civi\SymfonyEmail;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;

interface SymfonyMailInterface {

  /**
   * @return \Symfony\Component\Mime\Email
   */
  public function getEmail(): Email;

  /**
   * @return \Symfony\Component\Mailer\MailerInterface
   */
  public function getMailer(): MailerInterface;

}
