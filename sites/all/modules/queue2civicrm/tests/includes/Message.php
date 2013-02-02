<?php

class Message {
    static $defaults;

    protected $data;
    protected $headers;

    function __construct( $values = array() ) {
        $this->data = static::$defaults;
        $this->set( $values );
        $this->headers = array();
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
}

class TransactionMessage extends Message {
    function __construct( $values = array() ) {
        if ( !self::$defaults ) {
            require_once __DIR__ . '/../data/base_transaction.inc';
            self::$defaults = $message;
        }

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

class RefundMessage extends TransactionMessage {
    function __construct( $values = array() ) {
        require_once __DIR__ . '/../data/refund_transaction.inc';
        self::$defaults = $message;

        parent::__construct( $values + array(
                'gateway_refund_id' => rand(),
                'gateway_parent_id' => rand(),
        ) );

        // FIXME
        unset( $values['gateway_txn_id'] );
        unset( $values['order_id'] );
    }
}
