<?php
namespace wmf_communication;
use Civi\Omnimail\IMailer;
use Civi\Omnimail\MailFactory;

class TestMailer implements IMailer {
    static protected $mailings;
    static protected $success = true;

    static public function setup() {
        Mailer::$defaultSystem = 'test';
        MailFactory::singleton()->setActiveMailer('test');
        self::$mailings = array();
        self::$success = true;
    }

    static public function setSuccess( $success ) {
        self::$success = $success;
    }

    public function send( $email ) {
        self::$mailings[] = $email;
        return self::$success;
    }

    static public function countMailings() {
        return count( self::$mailings );
    }

    static public function getMailing( $index ) {
        return self::$mailings[$index];
    }
}
