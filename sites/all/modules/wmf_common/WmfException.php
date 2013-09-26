<?php

class WmfException extends Exception {
    //XXX shit we aren't using the 'rollback' attribute
    // and it's not correct in most of these cases
    static $error_types = array(
        'CIVI_CONFIG' => array(
            'fatal' => TRUE,
        ),
        'STOMP_BAD_CONNECTION' => array(
            'fatal' => TRUE,
        ),
        'INVALID_MESSAGE' => array(
            'reject' => TRUE,
        ),
        'INVALID_RECURRING' => array(
            'reject' => TRUE,
        ),
        'CIVI_REQ_FIELD' => array(
            'reject' => TRUE,
        ),
        'IMPORT_CONTACT' => array(
            'fatal' => TRUE,
        ),
        'IMPORT_CONTRIB' => array(
            'fatal' => TRUE,
        ),
        'IMPORT_SUBSCRIPTION' => array(
            'fatal' => TRUE,
        ),
        'IMPORT_TAG' => array(
            'fatal' => TRUE,
        ),
        'DUPLICATE_CONTRIBUTION' => array(
            'reject' => TRUE,
            'no-email' => TRUE,
        ),
        'GET_CONTRIBUTION' => array(
            'reject' => TRUE,
        ),
        'PAYMENT_FAILED' => array(
            'no-email' => TRUE,
        ),
        'UNKNOWN' => array(
            'fatal' => TRUE,
        ),
        'UNSUBSCRIBE' => array(
            'fatal' => TRUE,
        ),
        'UNSUBSCRIBE_WARN' => array(
            'no-email' => TRUE,
        ),

        // other errors
        'FILE_NOT_FOUND' => array(
            'fatal' => TRUE,
        ),
        'INVALID_FILE_FORMAT' => array(
            'fatal' => TRUE,
        ),
    );

    var $type;
    var $extra;

    function __construct( $type, $message, $extra = null ) {
        if ( !array_key_exists( $type, self::$error_types ) ) {
            $message .= ' -- ' . t( 'Warning, throwing a misspelled exception: "%type"', array( '%type' => $type ) );
            $type = 'UNKNOWN';
        }
        $this->type = $type;
        $this->extra = $extra;

        if ( is_array( $message ) ) {
            $message = implode( "\n", $message );
        }
        $this->message = "{$this->type} {$message}";

        if ( $extra ) {
            $this->message .= "\nSource: " . var_export( $extra, true );
        }

        if ( function_exists( 'watchdog' ) ) {
            // It seems that dblog_watchdog will pass through XSS, so
            // rely on our own escaping above, rather than pass $vars.
            $escaped = htmlspecialchars( $this->message, ENT_COMPAT, 'UTF-8', false );
            watchdog( 'wmf_common', $escaped, NULL, WATCHDOG_ERROR );
        }
        if ( function_exists('drush_set_error') && $this->isFatal() ) {
            drush_set_error( $this->type, $this->message );
        }
    }

    function getErrorName()
    {
        return $this->type;
    }

    function isRollbackDb()
    {
        return $this->getErrorCharacteristic('rollback', FALSE);
    }

    function isRejectMessage()
    {
        return $this->getErrorCharacteristic('reject', FALSE);
    }

    function isFatal()
    {
        return $this->getErrorCharacteristic('fatal', FALSE);
    }

    function isNoEmail()
    {
		//start hack
		if ( is_array( $this->extra ) && array_key_exists( 'email', $this->extra ) ){
			$no_failmail = explode( ',', variable_get('wmf_common_no_failmail', '') );
			if ( in_array( $this->extra['email'], $no_failmail ) ){
				echo "Failmail suppressed - email on suppression list";
				return true;
			}
		}
		//end hack
        return $this->getErrorCharacteristic('no-email', FALSE);
    }

    protected function getErrorCharacteristic($property, $default)
    {
        $info = self::$error_types[$this->type];
        if (array_key_exists($property, $info)) {
            return $info[$property];
        }
        return $default;
    }
}
