<?php

class WmfException extends Exception {

  const CIVI_CONFIG = 1;

  const STOMP_BAD_CONNECTION = 2;

  const INVALID_MESSAGE = 3;

  const INVALID_RECURRING = 4;

  const CIVI_REQ_FIELD = 5;

  const IMPORT_CONTACT = 6;

  const IMPORT_CONTRIB = 7;

  const IMPORT_SUBSCRIPTION = 8;

  const DUPLICATE_CONTRIBUTION = 9;

  const GET_CONTRIBUTION = 10;

  const PAYMENT_FAILED = 11;

  const UNKNOWN = 12;

  const UNSUBSCRIBE = 13;

  const MISSING_PREDECESSOR = 14;

  const FILE_NOT_FOUND = 15;

  const INVALID_FILE_FORMAT = 16;

  const fredge = 17;

  const MISSING_MANDATORY_DATA = 18;

  const DATA_INCONSISTENT = 19;

  const BANNER_HISTORY = 20;

  const GET_CONTACT = 21;

  const EMAIL_SYSTEM_FAILURE = 22;

  const BAD_EMAIL = 23;

  const DUPLICATE_INVOICE = 24;

  //XXX shit we aren't using the 'rollback' attribute
  // and it's not correct in most of these cases
  static $error_types = [
    'CIVI_CONFIG' => [
      'fatal' => TRUE,
    ],
    'STOMP_BAD_CONNECTION' => [
      'fatal' => TRUE,
    ],
    'INVALID_MESSAGE' => [
      'reject' => TRUE,
    ],
    'INVALID_RECURRING' => [
      'reject' => TRUE,
    ],
    'CIVI_REQ_FIELD' => [
      'reject' => TRUE,
    ],
    'IMPORT_CONTACT' => [
      'reject' => TRUE,
    ],
    'IMPORT_CONTRIB' => [
      'reject' => TRUE,
    ],
    'IMPORT_SUBSCRIPTION' => [
      'reject' => TRUE,
    ],
    'DUPLICATE_CONTRIBUTION' => [
      'drop' => TRUE,
      'no-email' => TRUE,
    ],
    'DUPLICATE_INVOICE' => [
      'requeue' => TRUE,
      'no-email' => TRUE,
    ],
    'GET_CONTRIBUTION' => [
      'reject' => TRUE,
    ],
    'PAYMENT_FAILED' => [
      'no-email' => TRUE,
    ],
    'UNKNOWN' => [
      'fatal' => TRUE,
    ],
    'UNSUBSCRIBE' => [
      'reject' => TRUE,
    ],
    'MISSING_PREDECESSOR' => [
      'requeue' => TRUE,
      'no-email' => TRUE,
    ],
    'BANNER_HISTORY' => [
      'drop' => TRUE,
      'no-email' => TRUE,
    ],

    // other errors
    'FILE_NOT_FOUND' => [
      'fatal' => TRUE,
    ],
    'INVALID_FILE_FORMAT' => [
      'fatal' => TRUE,
    ],

    'fredge' => [
      'reject' => TRUE,
    ],
    'MISSING_MANDATORY_DATA' => [
      'reject' => TRUE,
    ],
    'DATA_INCONSISTENT' => [
      'reject' => TRUE,
    ],
    'GET_CONTACT' => [
      'fatal' => FALSE,
    ],
    'EMAIL_SYSTEM_FAILURE' => [
      'fatal' => TRUE,
    ],
    'BAD_EMAIL' => [
      'no-email' => TRUE,
    ],
  ];

  var $extra;

  var $type;

  var $userMessage;

  /**
   * WmfException constructor.
   *
   * @param string $type Error type
   * @param string $message A WMF constructed message.
   * @param array $extra Extra parameters.
   *   If error_message is included then it will be included in the User Error
   *   message. If you are working with a CiviCRM Exception ($e) then you can
   *   pass in $e->getExtraParams() which will include the api error message
   *   and message and potentially backtrace & sql details (if you passed in
   *   'debug' => 1). Any data in the $extra array will be rendered in fail
   *   mails - but only 'error_message' is used for user messages (provided the
   *   getUserMessage function is used).
   */
  function __construct($type, $message, $extra = []) {
    if (!array_key_exists($type, self::$error_types)) {
      $message .= ' -- ' . t('Warning, throwing a misspelled exception: "%type"', ['%type' => $type]);
      $type = 'UNKNOWN';
    }
    $this->type = $type;
    $this->code = constant('WmfException::' . $type);
    $this->extra = $extra;

    if (is_array($message)) {
      $message = implode("\n", $message);
    }
    $this->message = "{$this->type} {$message}";
    $this->userMessage = $this->message;
    $addToMessage = $this->extra;
    if (isset ($addToMessage['trace'])) {
      unset ($addToMessage['trace']);
    }
    $this->message = $this->message . "\nSource: " . var_export($addToMessage, TRUE);

    if (function_exists('watchdog')) {
      // It seems that dblog_watchdog will pass through XSS, so
      // rely on our own escaping above, rather than pass $vars.
      $escaped = htmlspecialchars($this->getMessage(), ENT_COMPAT, 'UTF-8', FALSE);
      watchdog('wmf_common', $escaped, NULL, WATCHDOG_ERROR);
    }
    if (function_exists('drush_set_error') && $this->isFatal()) {
      drush_set_error($this->type, $this->getMessage());
    }
  }

  /**
   * Get error message intended for end users.
   *
   * @return string
   */
  function getUserErrorMessage() {
    return !empty($this->extra['error_message']) ? $this->extra['error_message'] : $this->userMessage;
  }

  function getErrorName() {
    return $this->type;
  }

  function isRollbackDb() {
    return $this->getErrorCharacteristic('rollback', FALSE);
  }

  function isRejectMessage() {
    return !$this->isRequeue() && $this->getErrorCharacteristic('reject', FALSE);
  }

  function isDropMessage() {
    return $this->getErrorCharacteristic('drop', FALSE);
  }

  function isRequeue() {
    if ($this->extra) {
      // We want to retry later if the problem was a lock wait timeout
      // or a deadlock. Unfortunately we have to do string parsing to
      // figure that out.
      $flattened = print_r($this->extra, TRUE);
      if (
        preg_match('/\'12(05|13) \*\* /', $flattened) ||
        preg_match('/Database lock encountered/', $flattened)
      ) {
        return TRUE;
      }
    }
    return $this->getErrorCharacteristic('requeue', FALSE);
  }

  function isFatal() {
    return $this->getErrorCharacteristic('fatal', FALSE);
  }

  function isNoEmail() {
    //start hack
    if (is_array($this->extra) && array_key_exists('email', $this->extra)) {
      $no_failmail = explode(',', variable_get('wmf_common_no_failmail', ''));
      if (in_array($this->extra['email'], $no_failmail)) {
        echo "Failmail suppressed - email on suppression list";
        return TRUE;
      }
    }
    //end hack
    return $this->getErrorCharacteristic('no-email', FALSE);
  }

  protected function getErrorCharacteristic($property, $default) {
    $info = self::$error_types[$this->type];
    if (array_key_exists($property, $info)) {
      return $info[$property];
    }
    return $default;
  }
}
