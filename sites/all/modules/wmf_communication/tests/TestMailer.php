<?php
namespace wmf_communication;

class TestMailer implements IMailer {
    static protected $mailings;

    static public function setup() {
        Mailer::$defaultSystem = 'test';

        self::$mailings = array();
    }

    public function send( $email ) {
        self::$mailings[] = $email;
    }

    static public function countMailings() {
        return count( self::$mailings );
    }

    static public function getMailing( $index ) {
        return self::$mailings[$index];
    }
}
