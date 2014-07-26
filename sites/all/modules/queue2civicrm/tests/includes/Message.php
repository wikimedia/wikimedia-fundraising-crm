<?php

class Message {
    protected $defaults = array();

    public $body;
    public $headers;

    protected $data;

    function __construct( $values = array() ) {
        $this->data = $this->defaults;
        $this->headers = array();
        $this->set( $values );
    }

    function set( $values ) {
        if ( is_array( $values ) ) {
            $this->data = $values + $this->data;
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
        if ( !$this->defaults ) {
            $path = __DIR__ . "/../data/{$name}.json";
            $this->defaults = json_decode( file_get_contents( $path ), true );
        }
    }
}

class TransactionMessage extends Message {
    protected $txn_id_key = 'gateway_txn_id';

    function __construct( $values = array() ) {
        $this->loadDefaults( "donation" );

        parent::__construct( array(
            $this->txn_id_key => mt_rand(),
            'order_id' => mt_rand(),
        ) + $values );

        $this->setHeaders( array(
            "persistent" => 'true',
            // FIXME: this might indicate a key error in our application code.
            "correlation-id" => "{$this->data['gateway']}-{$this->data[$this->txn_id_key]}",
            "JMSCorrelationID" => "{$this->data['gateway']}-{$this->data[$this->txn_id_key]}",
        ) );
    }

    function getGateway() {
        return $this->data['gateway'];
    }

    function getGatewayTxnId() {
        return $this->data[$this->txn_id_key];
    }
}

class RefundMessage extends TransactionMessage {
    function __construct( $values = array() ) {
        $this->loadDefaults( "refund" );

        $this->txn_id_key = 'gateway_refund_id';

        parent::__construct( $values );
    }
}

class RecurringPaymentMessage extends TransactionMessage {
    function __construct( $values = array() ) {
        $this->loadDefaults( "recurring_payment" );

        $this->txn_id_key = 'txn_id';

        parent::__construct( $values );
    }
}

class RecurringSignupMessage extends TransactionMessage {
    function __construct( $values = array() ) {
        $this->loadDefaults( "recurring_signup" );

        parent::__construct( $values );
    }
}
