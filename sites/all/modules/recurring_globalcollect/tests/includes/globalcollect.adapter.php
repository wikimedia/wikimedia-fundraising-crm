<?php

/**
 * Stub
 *
 * Should be a proper test double, with expectations.
 */
class GlobalCollectAdapter {
    function __construct( $options ) {
    }

    function do_transaction( $name ) {
        return array(
            'status' => 'completed',
        );
    }

    function load_request_data( $data ) {
    }
}
