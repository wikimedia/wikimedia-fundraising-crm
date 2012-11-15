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
        wmf_common_failmail( 'wmf_common', 'STOMP_BAD_CONNECTION totally lacking a stomp server: ' . $ex->getMessage() );
        return;
    }

    // Create the subscription -- to handle requeues we will only select things that either have no delay_till header
    // or a delay_till that is less than now. Because ActiveMQ is stupid, numeric selects auto compare to null.
    $ctime = time();
    $con->subscribe( $queue, array( 'ack' => 'client', ) );

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
        } elseif (($msg->command == 'MESSAGE') &&
                  array_key_exists('delay_till', $msg->headers) &&
                  (intval($msg->headers['delay_till']) > time())
        ) {
            // We have a message that we are not supposed to process yet, So requeue and ack
            if( wmf_common_stomp_requeue($msg) ) {
                wmf_common_stomp_ack_frame($msg);
            } else {
                throw new WmfException("Failed to requeue a delayed message");
            }
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

function wmf_common_stomp_connection( $renew = FALSE ) {
    global $_wmf_common_stomp_con;
    
    if ( empty( $_wmf_common_stomp_con ) || $renew == TRUE ) {
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

/**
 * Acknowledge a STOMP message and remove it from the queue
 * @param $msg Message to acknowledge
 */
function wmf_common_stomp_ack_frame( $msg ) {
    $con = wmf_common_stomp_connection();
    if( $con ) {
        $con->ack( $msg );
    }
}

/**
 * Enqueue a STOMP message
 *
 * @param $msg    Message to queue
 * @param $queue  Queue to queue to; should start with /queue/
 * @return bool   True if STOMP claims it worked
 */
function wmf_common_stomp_queue( $msg, $properties, $queue ) {
  $con = wmf_common_stomp_connection();

  $properties['persistent'] = 'true';

  if ($con && $con->send($queue, $msg, $properties)) {
    return TRUE;
  } else {
    return FALSE;
  }
}

/**
 * Places a STOMP message back onto the queue moving it's original timestamp to another
 * property and maintaining a count of previous moves. After wmf_common_requeue_max
 * moves it will move the message to $queue . '_badmsg'
 *
 * Note: orig_timestamp is in ms
 *
 * @param StompFrame $msg_orig
 * @return bool True if it all went successfully
 */
function wmf_common_stomp_requeue_with_delay( $msg_orig ) {
  $msg = $msg_orig->body;
  $headers = array(
    'orig_timestamp' => array_key_exists( 'orig_timestamp', $msg_orig->headers ) ? $msg_orig->headers['orig_timestamp'] : $msg_orig->headers['timestamp'],
    'delay_till' => time() + intval(variable_get('wmf_common_requeue_delay', 20 * 60)),
    'delay_count' => array_key_exists( 'delay_count', $msg_orig->headers ) ? $msg_orig->headers['delay_count'] + 1 : 1,
  );

  $queue = $msg_orig->headers['destination'];
  $max_count = intval(variable_get('wmf_common_requeue_max', 10));
  if (($max_count > 0) && ($headers['delay_count'] > $max_count)) {
    // Bad message! Move to bad message queue
    $queue .= '_badmsg';
  }

  $retval = false;
  try {
    $retval = wmf_common_stomp_queue( $msg, $headers, $queue );
  } catch (Stomp_Exception $ex) {
    $exMsg = "Failed to requeue message with {$ex->getMessage()}. Contents: " . json_encode($msg_orig);
    watchdog('recurring', $exMsg, NULL, WATCHDOG_ERROR);
    wmf_common_failmail('recurring', $exMsg);
  }
  return $retval;
}

/**
 * Places a STOMP message back onto the queue. Does not increment delay_count.
 *
 * @param $msg_orig
 * @return bool
 */
function wmf_common_stomp_requeue( $msg_orig ) {
  $msg = $msg_orig->body;
  $headers = array(
    'orig_timestamp' => array_key_exists( 'orig_timestamp', $msg_orig->headers ) ? $msg_orig->headers['orig_timestamp'] : $msg_orig->headers['timestamp'],
    'delay_till' => array_key_exists( 'delay_till', $msg_orig->headers ) ? $msg_orig->headers['delay_till'] : 0,
    'delay_count' => array_key_exists( 'delay_count', $msg_orig->headers ) ? $msg_orig->headers['delay_count'] : 0,
  );
  $queue = $msg_orig->headers['destination'];

  $retval = false;
  try {
    $retval = wmf_common_stomp_queue( $msg, $headers, $queue );
  } catch (Stomp_Exception $ex) {
    $exMsg = "Failed to requeue message with {$ex->getMessage()}. Contents: " . json_encode($msg_orig);
    watchdog('recurring', $exMsg, NULL, WATCHDOG_ERROR);
    wmf_common_failmail('recurring', $exMsg);
  }
  return $retval;
}
