<?php

class Queue {
    protected $url;
    protected $connection = NULL;

    const MAX_CONNECT_ATTEMPTS = 3;

    function __construct( $url ) {
        $this->url = $url;
    }

    function __destruct() {
        $this->disconnect();
    }

    /**
     * Pop up to $batch_size messages off $queue and execute $callback on each.
     *
     * @param $callback: must have the signature ($msg) -> bool
     */
    function dequeue_loop( $queue, $batch_size, $callback ) {
        watchdog( 'wmf_common',
            t( 'Attempting to process at most %size contribution(s) from "%queue" queue.',
                array( '%size' => $batch_size, '%queue' => $queue )
            )
        );

        $con = $this->getFreshConnection();

        // Create the subscription -- to handle requeues we will only select
        // things that either have no delay_till header or a delay_till that is
        // less than now. Because ActiveMQ is stupid, numeric selects auto
        // compare to null.
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
            if ($msg->command === 'RECEIPT') {
                // TODO it would be smart to keep track of RECEIPT frames as they are an ack-of-ack
                watchdog( 'wmf_common', t('Popped a sweet nothing off the queue:') . check_plain( print_r($msg, TRUE) ));
                $i--;
                continue;
            } elseif (($msg->command === 'MESSAGE') &&
                      array_key_exists('delay_till', $msg->headers) &&
                      (intval($msg->headers['delay_till']) > time())
            ) {
                // We have a message that we are not supposed to process yet, So requeue and ack
                if( $this->requeue( $msg ) ) {
                    $this->ack( $msg );
                } else {
                    throw new WmfException("STOMP_BAD_CONNECTION", "Failed to requeue a delayed message: ", $msg);
                }

                // If we're seeing messages with the current transaction ID in it we've started to eat our own
                // tail. So... we should bounce out.
                if ( strpos($msg->headers['message-id'], $this->getSessionId()) === 0 ) {
                    break;
                } else {
                    continue;
                }
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
        $this->disconnect();

        return $processed;
    }

    function isConnected() {
        return $this->connection !== null and $this->connection->isConnected();
    }

    function getFreshConnection() {
        return $this->getConnection( true );
    }

    function getConnection( $renew = false ) {
        if ( $renew && !empty( $this->connection ) ) {
            $this->disconnect();
        }

        watchdog( 'wmf_common', 'Attempting connection to queue server: %url', array( '%url' => $this->url ) );

        $attempt = 0;
        while ( !$this->isConnected() and $attempt <= Queue::MAX_CONNECT_ATTEMPTS ) {
            try {
                ++$attempt;
                $this->connection = new Stomp( $this->url );
                $this->connection->connect();
            }
            catch ( Stomp_Exception $e ) {
                $this->connection = false;
                watchdog( 'wmf_common', "Queue connection failure #$attempt: " . $e->getMessage(), array(), WATCHDOG_ERROR );
            }
        }
        
        if ( !$this->isConnected() ) {
            throw new WmfException( "STOMP_BAD_CONNECTION", "Gave up connecting to the queue." );
        }

        return $this->connection;
    }

    /**
     * Disconnect. Never called directly, only used as an
     * automatic shutdown function.  Do not blow up.
     */
    function disconnect() {
        try {
            if ( $this->isConnected() ) {
                $this->connection->disconnect();
            }
        }
        catch ( Exception $ex ) {
            watchdog( 'wmf_common', "Explosion during disconnect: " . $ex->getMessage(), NULL, WATCHDOG_ERROR );
        }
    }

    /**
     * Acknowledge a STOMP message and remove it from the queue
     * @param $msg Message to acknowledge
     */
    function ack( $msg ) {
        $this->getConnection()->ack( $msg );
    }

    /**
     * Enqueue a STOMP message
     *
     * @param $msg    Message to queue
     * @param $queue  Queue to queue to; should start with /queue/
     * @return bool   True if STOMP claims it worked
     */
    function enqueue( $msg, $properties, $queue ) {
      $properties['persistent'] = 'true';

      return $this->getConnection()->send( $queue, $msg, $properties );
    }

    /**
     * Places a STOMP message back onto the queue moving its original timestamp to another
     * property and maintaining a count of previous moves. After wmf_common_requeue_max
     * moves it will move the message to $queue . '_badmsg'
     *
     * Note: orig_timestamp is in ms
     *
     * @param StompFrame $msg_orig
     * @return bool True if it all went successfully
     */
    function requeueWithDelay( $msg_orig ) {
      $msg = $msg_orig->body;
      $headers = array(
        'orig_timestamp' => array_key_exists( 'orig_timestamp', $msg_orig->headers ) ? $msg_orig->headers['orig_timestamp'] : $msg_orig->headers['timestamp'],
        'delay_till' => time() + intval(variable_get('wmf_common_requeue_delay', 20 * 60)),
        'delay_count' => array_key_exists( 'delay_count', $msg_orig->headers ) ? $msg_orig->headers['delay_count'] + 1 : 1,
      );
      $headers += $msg_orig->headers;

      $queue = $msg_orig->headers['destination'];
      $max_count = intval(variable_get('wmf_common_requeue_max', 10));
      if (($max_count > 0) && ($headers['delay_count'] > $max_count)) {
        // Bad message! Move to bad message queue
        $queue .= '_badmsg';
      }

      $retval = FALSE;
      try {
        $this->enqueue( $msg, $headers, $queue );
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
    function requeue( $msg_orig ) {
      $msg = $msg_orig->body;
      $headers = array(
        'orig_timestamp' => array_key_exists( 'orig_timestamp', $msg_orig->headers ) ? $msg_orig->headers['orig_timestamp'] : $msg_orig->headers['timestamp'],
        'delay_till' => array_key_exists( 'delay_till', $msg_orig->headers ) ? $msg_orig->headers['delay_till'] : 0,
        'delay_count' => array_key_exists( 'delay_count', $msg_orig->headers ) ? $msg_orig->headers['delay_count'] : 0,
      );
      $headers += $msg_orig->headers;
      $queue = $headers['destination'];

      try {
        $this->enqueue( $msg, $headers, $queue );
      } catch (Stomp_Exception $ex) {
        $exMsg = "Failed to requeue message with {$ex->getMessage()}. Contents: " . json_encode($msg_orig);
        watchdog('wmf_common', $exMsg, NULL, WATCHDOG_ERROR);
        wmf_common_failmail('wmf_common', $exMsg);
      }
    }

    /**
     * Saves an archival msg to <QUEUE>-damaged
     *
     * @return string URL pointing to manual edit and requeuing of the newly archived msg
     */
    function reject( $msg, $error = null ) {
        $suffix = "-damaged";
        //if ( strstr( $msg->headers['destination'], $suffix ) ) { ERROR
        $msg->headers['destination'] = $this->queue . $suffix;
        $msg->headers['error'] = $error;

        $queue = $msg->headers['destination'];

        if ( !$this->enqueue( $msg->body, $msg->headers, $queue ) ) {
            $exMsg = 'Failed to inject rejected message into $queue! ' . json_encode( $msg );
            watchdog( 'wmf_common', $exMsg );
            # TODO do not deliver by mail
            wmf_common_failmail( 'wmf_common', $exMsg );
        }
    }

    function item_url( $msg ) {
        global $base_url;
        $queue = $msg->headers['destination'];
        $correlationId = $msg->headers['correlation-id'];
        return "{$base_url}/queue/{$queue}/{$correlationId}";
    }

    /**
     * Obtain the current stomp session id prefix
     *
     * @return string
     */
    protected function getSessionId() {
        return $this->getConnection()->getSessionId();
    }
}
