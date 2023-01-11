<?php
namespace wmf_communication;
use Civi\Omnimail\IMailer;
use Civi\Omnimail\MailFactory;

class TestMailer implements IMailer {
  protected $mailings = [];
  static protected $success = true;

  static public function setup() {
      Mailer::$defaultSystem = 'test';
      MailFactory::singleton()->setActiveMailer('test');
      self::$success = true;
  }

  static public function setSuccess( $success ) {
      self::$success = $success;
  }

  public function send($email) {
      $this->mailings[] = $email;
      return self::$success;
  }

  /**
   * Get the number of mailings sent in the test.
   *
   * @return int
   */
  public function countMailings(): int {
    return count($this->mailings);
  }

  /**
   * Get the content on the sent mailing.
   *
   * @param int $index
   *
   * @return array
   */
  public function getMailing(int $index): array {
      return $this->mailings[$index];
  }

}
