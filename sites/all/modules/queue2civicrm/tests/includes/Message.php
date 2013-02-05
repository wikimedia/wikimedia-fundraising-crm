<?php

class Message {
    static $defaults;

    public $body;
    public $headers;

    protected $data;

    function __construct( $values = array() ) {
        $this->data = static::$defaults;
        $this->headers = array();
        $this->set( $values );
    }

    function set( $values ) {
        if ( is_array( $values ) ) {
            $this->data = array_merge( $this->data, $values );
        }

        $this->body = json_encode( $this->data );
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

    function loadDefaults( $name ) {
        // FIXME: how to check if ( !self::$defaults ), but ignoring static::$defaults?
        // should just cache message contents by name.
        global $message;
        require_once __DIR__ . "/../data/{$name}.inc";
        static::$defaults = $message;
    }
}

class TransactionMessage extends Message {
    protected $txn_id_key = 'gateway_txn_id';

    function __construct( $values = array() ) {
        $this->loadDefaults( "base_transaction" );

        parent::__construct( $values + array(
            $this->txn_id_key => rand(),
            'order_id' => rand(),
        ) );

        $this->setHeaders( array(
                "persistent" => 'true',
                // FIXME: this might indicate a key error in our application code.
                "correlation-id" => "{$this->data['gateway']}-{$this->data[$this->txn_id_key]}",
                "JMSCorrelationID" => "{$this->data['gateway']}-{$this->data[$this->txn_id_key]}",
        ) );
    }
}

class RefundMessage extends TransactionMessage {
    function __construct( $values = array() ) {
        $this->loadDefaults( "refund_transaction" );

        $this->txn_id_key = 'gateway_refund_id';

        parent::__construct( $values );
    }
}
