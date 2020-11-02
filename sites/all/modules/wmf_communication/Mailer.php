<?php namespace wmf_communication;

use Civi\Omnimail\MailFactory;
use Civi\Omnimail\IMailer;

/**
 * Weird factory to get the default concrete Mailer.
 *
 * This code has been moved to the Omnimail extension as part of our shift to
 * extensions that are CMS specific
 *
 * @deprecated
 */
class Mailer {
    static public $defaultSystem = 'phpmailer';

    /**
     * Get the default Mailer.
     *
     * @deprecated - call the MailFactory directly.
     *
     * @return IMailer instantiated default Mailer
     */
    static public function getDefault() {
      $mailfactory = MailFactory::singleton();
      $mailfactory->setActiveMailer(self::$defaultSystem);
      return $mailfactory->getMailer();
    }
}
