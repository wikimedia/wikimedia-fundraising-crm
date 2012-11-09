<?php

require_once __DIR__ . '/Message.php';

class MessageSource {
    protected $stomp_url = 'tcp://localhost:61613';
    //protected $message_type = "TransactionMessage";
    protected $queue = "/queue/test-donations";

    function __construct( $stomp_url = null ) {
        require_once __DIR__ . '/data-default_transaction.inc';
        // $message_type::$defaults = $default_message;
        TransactionMessage::$defaults = $default_message;

        if ( $stomp_url ) {
            $this->stomp_url = $stomp_url;
        }
        require_once __DIR__ . '/../Stomp.php';
        $this->stomp = new Stomp( $this->stomp_url );

        if (method_exists($this->stomp, 'connect'))
                $this->stomp->connect();
    }

    function setQueue( $queue ) {
        $this->queue = $queue;
    }

    function inject( $values = array() ) {
        //$message = new $message_type( $values );
        $message = new TransactionMessage( $values );

        $this->stomp->send(
            $this->queue,
            json_encode( $message->getBody() ),
            $message->getHeaders()
        );
    }
}
