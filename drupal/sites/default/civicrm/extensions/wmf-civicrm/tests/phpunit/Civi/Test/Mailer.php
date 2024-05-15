<?php

namespace Civi\Test;

use Civi\Omnimail\IMailer;

class Mailer implements IMailer {

  /**
   * @var array
   */
  protected $mailings = [];

  /**
   * @param array $email
   *
   * @return true
   */
  public function send($email): bool {
    $this->mailings[] = $email;
    return TRUE;
  }

  /**
   * @return array
   */
  public function getMailings(): array {
    return $this->mailings;
  }

  /**
   * Get the number of mailings sent since the class was instantiated.
   *
   * @return int
   */
  public function count(): int {
    return count($this->mailings);
  }

}
