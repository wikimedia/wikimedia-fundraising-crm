<?php

/**
 * Mock object for testing
 */
class GlobalCollectAdapter {
    public $data;

    function load_request_data( $data ) {
        $this->data = $data;
    }

    function do_transaction( $transaction ) {
        return array(
            'status' => 'completed',
        );
    }
}
