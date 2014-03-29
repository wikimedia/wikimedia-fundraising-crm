<?php

/**
 * FIXME: injecting into a live MQ should done from a drush command, not from tests
 */
class MessageSource {
    protected $stomp_url = 'tcp://localhost:61613';
    //protected $message_type = "TransactionMessage";
    protected $queue = "/queue/test-donations";

    function __construct( $stomp_url = null, $queue = null ) {
        if ( $stomp_url ) {
            $this->stomp_url = $stomp_url;
        }
        $this->stomp = new Stomp( $this->stomp_url );

        if (method_exists($this->stomp, 'connect'))
                $this->stomp->connect();

        if ( $queue ) {
            $this->queue = $queue;
        }
    }

    function setQueue( $queue ) {
        $this->queue = $queue;
    }

    function inject( $values = array() ) {
        //$message = new $message_type( $values );
        if ( is_array( $values ) ) {
            $message = new TransactionMessage( $values );
        } elseif ( is_object( $values ) ) {
            $message = $values;
        }

        $this->stomp->send(
            $this->queue,
            json_encode( $message->getBody() ),
            $message->getHeaders()
        );
    }
}
