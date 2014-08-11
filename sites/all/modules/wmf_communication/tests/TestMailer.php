<?php
namespace wmf_communication;

class TestMailer implements IMailer {
    static protected $mailings;

    public function setup() {
        Mailer::$defaultSystem = 'test';

        self::$mailings = array();
    }

    public function send( $email ) {
        self::$mailings[] = $email;
    }

    public function countMailings() {
        return count( self::$mailings );
    }

    public function getMailing( $index ) {
        return self::$mailings[$index];
    }
}
