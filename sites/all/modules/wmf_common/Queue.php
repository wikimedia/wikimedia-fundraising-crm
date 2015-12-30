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
     * Pop from a queue and execute a callback on each message
     *
     * @param string $queue name of the queue we will read from
     * @param integer|null $batch_size maximum number of messages to process, or falseish for unlimited
     * @param integer|null $time_limit maximum time to spend looping, in seconds, or falseish for unlimited
     * @param callable $callback: must have the signature function($msg) -> bool
     *
     * @return integer number of messages processed
     */
    function dequeue_loop( $queue, $batch_size, $time_limit, $callback ) {
        if ( !$batch_size and !$time_limit ) {
            throw new Exception( "Bad configuration: need to give a count or time limit" );
        }

        $queue = $this->normalizeQueueName( $queue );

        watchdog( 'wmf_common',
            'Attempting to process at most %size contribution(s) from "%queue" queue, spending at most %time seconds.',
            array(
                '%size' => ( $batch_size ? $batch_size : 'unlimited' ),
                '%time' => ( $time_limit ? $time_limit : 'unlimited' ),
                '%queue' => $queue,
            ),
            WATCHDOG_INFO
        );

        $con = $this->getFreshConnection();

        // Create the subscription -- to handle requeues we will only select
        // things that either have no delay_till header or a delay_till that is
        // less than now. Because ActiveMQ is stupid, numeric selects auto
        // compare to null.
        $start_time = time();
        $con->subscribe( $queue, array( 'ack' => 'client', ) );
        $con->setReadTimeout( 4 );

        $processed = 0;
        while ( true ) {
            if ( $batch_size
                and $processed >= $batch_size
            ) {
                watchdog( 'wmf_common', t( 'Processed the maximum batch size, exiting dequeue loop.' ), NULL, WATCHDOG_INFO );
                break;
            }

            if ( $time_limit
                and time() >= ( $start_time + $time_limit )
            ) {
                watchdog( 'wmf_common', t( 'Time limit expired, exiting dequeue loop.' ), NULL, WATCHDOG_INFO );
                break;
            }

            $msg = $con->readFrame();
            if ( empty($msg) ) {
                watchdog( 'wmf_common', t('Ran out of messages.'), NULL, WATCHDOG_INFO );
                break;
            }
            if ($msg->command === 'RECEIPT') {
                // TODO it would be smart to keep track of RECEIPT frames as they are an ack-of-ack
                watchdog( 'wmf_common', t('Popped a sweet nothing off the queue:') . check_plain( print_r($msg, TRUE) ), NULL, WATCHDOG_INFO );
                $i--;
                continue;
            } elseif (($msg->command === 'MESSAGE') &&
                      array_key_exists('delay_till', $msg->headers) &&
                      (intval($msg->headers['delay_till']) > time())
            ) {
                // We have a message that we are not supposed to process yet, So requeue and ack
                watchdog( 'wmf_common', t('Requeueing delay_till message.'), NULL, WATCHDOG_DEBUG );
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

            watchdog( 'wmf_common', t( 'Feeding raw queue message to %callback : %msg', array( '%callback' => print_r($callback, TRUE), '%msg' => $this->debug_message( $msg ) ) ), NULL, WATCHDOG_INFO );

            set_time_limit( 60 );

            try {
                $this->commonMessageNormalize( $msg );
                WmfDatabase::transactionalCall(
                    $callback, array( $msg )
                );
                $this->ack( $msg );
            } catch ( WmfException $ex ) {
                watchdog( 'wmf_common', 'Failure while processing message: ' . $ex->getMessage(), NULL, WATCHDOG_ERROR );

                if ( $ex->isRequeue() ) {
                    $ret = $this->requeueWithDelay( $msg, $ex );

                    if ( $ret ) {
                      $this->ack( $msg );
                    } else {
                      throw new WmfException( "STOMP_BAD_CONNECTION", "Failed to requeue a message" );
                    }
                }

                if ( $ex->isDropMessage() ) {
                    watchdog( 'wmf_common', "Dropping message altogether: " . Queue::getCorrelationId( $msg ), NULL, WATCHDOG_ERROR );
                    $this->ack( $msg );
                } elseif ( $ex->isRejectMessage() ) {
                    watchdog( 'wmf_common', "\nRemoving failed message from the queue: \n" . print_r($msg, TRUE), NULL, WATCHDOG_ERROR );
                    $this->reject( $msg, $ex );
                    $this->ack( $msg );

                    $mailableDetails = $this->item_url( $msg );
                } else {
                    $mailableDetails = "Redacted contents of message ID: " . Queue::getCorrelationId( $msg );
                }

                if ( !$ex->isNoEmail() ) {
                    wmf_common_failmail( 'wmf_common', $ex, $mailableDetails );
                }

                if ( $ex->isFatal() ) {
                    $error = "Halting Process.";
                    watchdog( 'wmf_common', $error, NULL, WATCHDOG_ERROR );

                    throw $ex;
                }
            } catch (Exception $ex) {
                $error = 'UNHANDLED ERROR. Halting dequeue loop. Exception: ' . $ex->getMessage() . "\nStack Trace: " . print_r( $ex->getTrace(), true );
                watchdog( 'wmf_common', $error, NULL, WATCHDOG_ERROR );
                wmf_common_failmail( 'wmf_common', $error, Queue::getCorrelationId( $msg ) );

                throw $ex;
            }

            $processed++;
        }

        $con->unsubscribe( $queue );
        $this->disconnect();

        return $processed;
    }

    /**
     * Preview several messages from the head of a queue, without ack'ing.
     */
    function peekMultiple( $queue, $count ) {
        # TODO: prefetchPolicy, timeout as params?

        $queue = $this->normalizeQueueName( $queue );
        $con = $this->getFreshConnection();

        $con->setReadTimeout( 1 );
        # FIXME: probably the wrong value--does this buffer include msgs not
        # specific to our subscription?
        $con->prefetchSize = $count;

        $con->subscribe( $queue, array( 'ack' => 'client' ) );
        $messages = array();
        while ( $con->hasFrameToRead() && $count-- >= 0 ) {
            $msg = $con->readFrame();
            if ( !$msg ) {
                break;
            }
            $messages[] = $msg;
        }
        return $messages;
    }

    function getByCorrelationId( $queue, $correlationId ) {
        $con = $this->getFreshConnection();
        $properties = array(
            'ack' => 'client',
            'selector' => "JMSCorrelationID='{$correlationId}'",
        );
		$timeout = variable_get( "queue_timeout_$queue", 5 );
		$con->setReadTimeout( $timeout );
        $con->subscribe( $this->normalizeQueueName( $queue ), $properties );

        return $con->readFrame();
    }

    function getByAnyId( $queue, $correlationId ) {
        $con = $this->getFreshConnection();
        $properties = array(
            'ack' => 'client',
            'selector' => "JMSCorrelationID='{$correlationId}' OR JMSMessageID='{$correlationId}'",
        );
        $con->subscribe( $this->normalizeQueueName( $queue ), $properties );

        return $con->readFrame();
    }

    static function getCorrelationId( $msg ) {
        if ( !empty( $msg->headers['correlation-id'] ) ) {
            return $msg->headers['correlation-id'];
        }
        $body = json_decode( $msg->body, TRUE );
        if ( !empty( $body['gateway'] ) && !empty( $body['gateway_txn_id'] ) ) {
            return "{$body['gateway']}-{$body['gateway_txn_id']}";
        }
        if ( !empty( $msg->headers['message-id'] ) ) {
            return $msg->headers['message-id'];
        }

        watchdog( 'wmf_common', 'Could not create a correlation-id for message: ' . $msg->body, NULL, WATCHDOG_WARNING );
        return '';
    }

    function isConnected() {
        return $this->connection and $this->connection->isConnected();
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
                $this->connection = null;
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
                watchdog( 'wmf_common', t('Attempting to disconnect from the queue server'), NULL, WATCHDOG_INFO );
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

      $queue = $this->normalizeQueueName( $queue );
      watchdog( 'wmf_common', 'Enqueueing a message on ' . $queue, NULL, WATCHDOG_DEBUG );
      return $this->getConnection()->send( $queue, $msg, $properties );
    }

    /**
     * Places a STOMP message back onto the queue moving its original timestamp to another
     * property and maintaining a count of previous moves. After wmf_common_requeue_max
     * moves it will reject the message (place into a rejection queue with details of
     * the underlying exception that has caused this requeue.)
     *
     * Note: orig_timestamp is in ms
     *
     * @param StompFrame $msg_orig The message as fetched from stomp
     * @param WmfException $ex The exception which has caused this message to need to be requeued.
     *
     * @return bool True if it all went successfully
     */
    function requeueWithDelay( $msg_orig, WmfException $ex ) {
      $msg = $msg_orig->body;
      $headers = array(
        'orig_timestamp' => array_key_exists( 'orig_timestamp', $msg_orig->headers ) ? $msg_orig->headers['orig_timestamp'] : $msg_orig->headers['timestamp'],
        'delay_till' => time() + intval(variable_get('wmf_common_requeue_delay', 20 * 60)),
        'delay_count' => array_key_exists( 'delay_count', $msg_orig->headers ) ? $msg_orig->headers['delay_count'] + 1 : 1,
      );
      $headers += $msg_orig->headers;

      $queue = $msg_orig->headers['destination'];
      $max_count = intval(variable_get('wmf_common_requeue_max', 10));

      $retval = FALSE;
      if (($max_count > 0) && ($headers['delay_count'] > $max_count)) {
        try {
            $this->reject( $msg_orig, $ex );
            $retval = TRUE;
        } catch ( WmfException $ex ) {
            $errorMsg = $ex->getMessage();
        }
      } else {
        watchdog( 'wmf_common', "Requeueing message to $queue", NULL, WATCHDOG_INFO );
        $errorMsg = "Bad connection?";
        try {
          $retval = $this->enqueue( $msg, $headers, $queue );
        } catch ( Stomp_Exception $ex ) {
          $errorMsg = $ex->getMessage();
        }
      }

      if ( !$retval ) {
        $error = "Failed to requeue message: {$errorMsg}. Contents: " . json_encode( $msg_orig );
        watchdog( 'wmf_common', $error, NULL, WATCHDOG_ERROR );
        wmf_common_failmail( 'wmf_common', $error );
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
        $retval = $this->enqueue( $msg, $headers, $queue );
        return $retval;
      } catch (Stomp_Exception $ex) {
        $exMsg = "Failed to requeue message with {$ex->getMessage()}. Contents: " . json_encode($msg_orig);
        watchdog('wmf_common', $exMsg, NULL, WATCHDOG_ERROR);
        wmf_common_failmail('wmf_common', $exMsg);
        return false;
      }
    }

    /**
     * Saves an archival msg to <QUEUE>-damaged
     *
     * @return string URL pointing to manual edit and requeuing of the newly archived msg
     */
    function reject( $msg, WmfException $error ) {
        $suffix = "-damaged";
        //if ( strstr( $msg->headers['destination'], $suffix ) ) { ERROR
        $msg->headers['destination'] .= $suffix;

        $new_body = array(
            'original' => json_decode( $msg->body ),
        );
        if ( $error ) {
            $msg->headers['error'] = $error->getErrorName();
            $new_body['error'] = $error->getMessage();
        }
        $msg->headers['correlation-id'] = Queue::getCorrelationId( $msg );

        $queue = $this->normalizeQueueName( $msg->headers['destination'] );

        watchdog( 'wmf_common', 'Attempting to move a message to ' . $queue, NULL, WATCHDOG_INFO );
        watchdog( 'wmf_common', "Requeuing under correlation-id {$msg->headers['correlation-id']}", NULL, WATCHDOG_INFO );
        if ( !$this->enqueue( json_encode( $new_body ), $msg->headers, $queue ) ) {
            $exMsg = 'Failed to inject rejected message into $queue! ' . json_encode( $msg );
            watchdog( 'wmf_common', $exMsg, NULL, WATCHDOG_ERROR );
            throw new WmfException( 'STOMP_BAD_CONNECTION', 'Could not reinject damaged message' );
        }
    }

    function item_url( $msg ) {
        global $base_url;
        $queue = str_replace('/queue/', '', $msg->headers['destination'] );
        $correlationId = Queue::getCorrelationId( $msg );
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

    protected function normalizeQueueName( $queue ) {
        $queue = str_replace( '/queue/', '', $queue );
        return '/queue/' . $queue;
    }

    protected function debug_message( $msg ) {
        $msg_copy = clone( $msg );
        if ( is_string( $msg_copy->body ) ) {
            $decoded = json_decode( $msg_copy->body, true );
            if ( $decoded ) {
                $msg_copy->body = $decoded;
            }
        }
        # php 5.4 $msg_str = json_encode( $msg_copy, JSON_PRETTY_PRINT );
        $msg_str = print_r( $msg_copy, true );
        return $msg_str;
    }

    protected function commonMessageNormalize( &$msg ) {
        // argh.  Collapse useful headers into the message, then do a stupid dance.
        $pull_headers = array(
            'source_name',
            'source_type',
            'source_host',
            'source_run_id',
            'source_version',
            'source_enqueued_time',
        );
        $newBody = json_decode( $msg->body, true )
            + array_intersect_key( $msg->headers, array_flip( $pull_headers ) );

        $msg->body = json_encode( $newBody );
    }
}
