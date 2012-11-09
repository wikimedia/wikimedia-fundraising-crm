<?php

class Message {
    static $defaults;

    protected $data;
    protected $headers;

    function __construct( $values = null ) {
        $this->data = static::$defaults;
        $this->set( $values );
        $this->headers = array();
    }

    function set( $values ) {
        if ( is_array( $values ) ) {
            $this->data = array_merge( $this->data, $values );
        }
    }

    function setHeaders( $values ) {
        if ( is_array( $values ) ) {
            $this->headers = array_merge( $this->headers, $values );
        }
    }

    function getBody() {
        return $this->data;
    }

    function getHeaders() {
        return $this->headers;
    }
}

class TransactionMessage extends Message {
    function __construct( $values = array() ) {
        parent::__construct();

        $override['gateway_txn_id'] = rand();
        $override['order_id'] = rand();

        $this->set( $override );
        $this->set( $values );
    }

    function getHeaders() {
        $this->setHeaders( array(
                "persistent" => 'true',
                "JMSCorrelationID" => "{$this->data['gateway']}-{$this->data['gateway_txn_id']}",
        ) );
        return parent::getHeaders();
    }
}
