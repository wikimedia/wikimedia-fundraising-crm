<?php

/**
 * Pop up to $batch_size messages off $queue and execute $callback on each.
 *
 * @param $callback: must have the signature ($msg) -> bool
 */

$_wmf_common_stomp_con = NULL;

function wmf_common_dequeue_loop( $queue, $batch_size, $callback ) {
    watchdog( 'wmf_common',
        t( 'Attempting to process at most %size contribution(s) from "%queue" queue.',
            array( '%size' => $batch_size, '%queue' => $queue )
        )
    );
    try {
        $con = wmf_common_stomp_connection();
    }
    catch ( Exception $ex ) {
        wmf_common_failmail( 'STOMP_BAD_CONNECTION totally lacking a stomp server: ' . $ex->getMessage() );
        return;
    }
    $con->subscribe( $queue, array('ack' => 'client') );

    $processed = 0;
    for ( $i = 0; $i < $batch_size; $i++ ) {
        // we could alternatively set a time limit on the stomp readframe
        set_time_limit( 10 );
        $msg = $con->readFrame();
        if ( empty($msg) ) {
            return FALSE;
        }
        if ($msg->command == 'RECEIPT') {
            // TODO it would be smart to keep track of RECEIPT frames as they are an ack-of-ack
            watchdog( 'wmf_common', t('Popped a sweet nothing off the queue:') . check_plain( print_r($msg, TRUE) ));
            $i--;
            continue;
        }
        set_time_limit( 60 );
        $success = $callback( $msg );

        if ($success === TRUE) {
            $processed++;
        } else {
            break;
        }
    }

    $con->unsubscribe( $queue );
    $con->disconnect();

    return $processed;
}

function wmf_common_stomp_connection( $renew = false ) {
    global $_wmf_common_stomp_con;
    
    if ( empty( $_wmf_common_stomp_con ) || $renew == true ) {
        //TODO these variables should be owned by wmf_common
        require_once variable_get('queue2civicrm_stomp_path', drupal_get_path('module', 'queue2civicrm') . '/Stomp.php');
        watchdog( 'wmf_common', 'Attempting connection to queue server: ' . variable_get('queue2civicrm_url', 'tcp://localhost:61613'));
        if ( !empty( $_wmf_common_stomp_con ) && $_wmf_common_stomp_con->isConnected() ) {
            $_wmf_common_stomp_con->disconnect();
        }

        $_wmf_common_stomp_con = new Stomp(variable_get('queue2civicrm_url', 'tcp://localhost:61613'));
    } 

    $attempt = 0;
    while ( !wmf_common_stomp_is_connected() && $attempt < 2 ) {
        try {
            ++$attempt;
            $_wmf_common_stomp_con = new Stomp(variable_get('queue2civicrm_url', 'tcp://localhost:61613'));
            $_wmf_common_stomp_con->connect();
            register_shutdown_function( 'wmf_common_stomp_disconnect' );
        }
        catch ( Stomp_Exception $e ) {
            $_wmf_common_stomp_con = FALSE;
            watchdog( 'wmf_common', "Queue connection failure #$attempt: " . $e->getMessage(), array(), WATCHDOG_ERROR );
        }
    }
    
    if ( !wmf_common_stomp_is_connected() ) {
        throw new WmfException( "STOMP_BAD_CONNECTION", "Gave up connecting to the queue." );
    }

    return $_wmf_common_stomp_con;
}

/**
 * Disconnect. Never called directly, only used as an
 * automatic shutdown function.
 */
function wmf_common_stomp_disconnect() {
    try {
        if ( wmf_common_stomp_is_connected() ) {
            global $_wmf_common_stomp_con;
            $_wmf_common_stomp_con->disconnect();
        }
    }
    catch ( Exception $ex ) {
        watchdog( 'wmf_common', "Stomp blew up during disconnect: " . $ex->getMessage(), NULL, WATCHDOG_ERROR );
    }
}

function wmf_common_stomp_is_connected() {
    global $_wmf_common_stomp_con;
    return ( is_object($_wmf_common_stomp_con) && $_wmf_common_stomp_con->isConnected() );
}

function wmf_common_stomp_ack_frame( $msg ) {
    $con = wmf_common_stomp_connection();
    if( $con ) {
        $con->ack( $msg );
    }
}
