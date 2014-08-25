<?php
namespace wmf_communication;

class TestMailer implements IMailer {
    static private $mailings = array();

    public function setup() {
        Mailer::$defaultSystem = 'test';
    }

    public function send( $email ) {
        self::$mailings[] = $email;
    }

    public function countMailings() {
        return count( self::$mailings );
    }

    public function getMailing( $index ) {
        return $mailings[$index];
    }
}
