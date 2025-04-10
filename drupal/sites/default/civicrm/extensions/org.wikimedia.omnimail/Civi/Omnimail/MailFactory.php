<?php

namespace Civi\Omnimail;

use Civi\WMFException\WMFException;

/**
 * Class Mailer
 *
 * @package Civi\Omnimail
 */
class MailFactory {

  /**
   * We only need one instance of this object.
   *
   * So we use the singleton pattern and cache the instance in this variable
   *
   * @var self
   */
  static private $singleton;

  /**
   * Singleton function used to manage this object.
   *
   * @return self
   */
  public static function singleton(): self {
    if (self::$singleton === NULL) {
      self::$singleton = new self();
    }
    return self::$singleton;
  }

  protected $activeMailer;

  /**
   * Set the active mailer.
   *
   * @param string|null $name
   * @public null|IMailer
   *  Optionally pass in the mailer as an object.
   *
   * @throws \CRM_Core_Exception
   */
  public function setActiveMailer(?string $name, $mailer = NULL): void {
    if ($mailer) {
      $this->activeMailer = $mailer;
      return;
    }
    switch ($name) {

      case 'smtp':
        $this->activeMailer = new SMTPMailer();
        $this->activeMailer->setSmtpHost(CIVICRM_SMTP_HOST);
        break;

      case 'test':
        $this->activeMailer = new \Civi\Test\Mailer();
        break;

      default:
        throw new \CRM_Core_Exception("Unknown mailer requested: " . $name);
    }
  }

  /**
   * Get the Mailer class.
   */
  public function getMailer() {
    if (!$this->activeMailer) {
      if (!getenv('CIVICRM_SMTP_HOST') && !defined('CIVICRM_SMTP_HOST')) {
        throw new WMFException(
          WMFException::EMAIL_SYSTEM_FAILURE,
          'CIVICRM_SMTP_HOST is not defined or set as env variable'
        );
      }
      if (!defined('CIVICRM_SMTP_HOST')) {
        define('CIVICRM_SMTP_HOST', getenv('CIVICRM_SMTP_HOST'));
      }
      $this->setActiveMailer('smtp');
    }
    return $this->activeMailer;
  }

  /**
   * Send a mail using the active mailler.
   *
   * @param array $email
   * @param array $headers
   *
   * @return bool
   */
  public function send($email, $headers): bool {
    return (bool) $this->getMailer()->send(
      $email,
      $headers
    );
  }

}
