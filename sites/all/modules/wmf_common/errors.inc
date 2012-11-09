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
        'UNSUBSCRIBE' => array(),
        'UNSUBSCRIBE_WARN' => array(
          'no-email' => TRUE,
        ),
    );

    var $type;

    function __construct( $type, $message, $extra = null ) {
        if ( !array_key_exists( $type, self::$error_types ) ) {
            $message .= ' -- ' . t( 'Warning, throwing a misspelled exception: "%type"', array( '%type' => $type ) );
            $type = 'UNKNOWN';
        }
        $this->type = $type;

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

function wmf_common_override_drush_error_handler() {
    set_error_handler( "wmf_common_drush_error_handler" );
}

function wmf_common_drush_error_handler( $errno, $message, $filename, $line, $context ) {
  // E_DEPRECATED was added in PHP 5.3. Drupal 6 will not fix all the
  // deprecated errors, but suppresses them. So we suppress them as well.
  if (defined('E_DEPRECATED')) {
    $errno = $errno & ~E_DEPRECATED;
  }

  // "error_reporting" is usually set in php.ini, but may be changed by
  // drush_errors_on() and drush_errors_off().
  if ($errno & error_reporting()) {
    // NOTE: this next line is the only change we make to the standard drush error handler:
    // INCREDIBLY SLOW: $type = drush_get_option('php-notices', 'notice');
    $type = 'notice';

    // Bitmask value that constitutes an error needing to be logged.
    $error = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if ($errno & $error) {
      $type = 'error';
    }

    // Bitmask value that constitutes a warning being logged.
    $warning = E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING;
    if ($errno & $warning) {
      $type = 'warning';
    }

    drush_log($message . ' ' . basename($filename) . ':' . $line, $type);

    return TRUE;
  }
}
