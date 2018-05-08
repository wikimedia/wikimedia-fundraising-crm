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
    self::CIVI_CONFIG => [
      'fatal' => TRUE,
    ],
    self::STOMP_BAD_CONNECTION => [
      'fatal' => TRUE,
    ],
    self::INVALID_MESSAGE => [
      'reject' => TRUE,
    ],
    self::INVALID_RECURRING => [
      'reject' => TRUE,
    ],
    self::CIVI_REQ_FIELD => [
      'reject' => TRUE,
    ],
    self::IMPORT_CONTACT => [
      'reject' => TRUE,
    ],
    self::IMPORT_CONTRIB => [
      'reject' => TRUE,
    ],
    self::IMPORT_SUBSCRIPTION => [
      'reject' => TRUE,
    ],
    self::DUPLICATE_CONTRIBUTION => [
      'drop' => TRUE,
      'no-email' => TRUE,
    ],
    self::DUPLICATE_INVOICE => [
      'requeue' => TRUE,
      'no-email' => TRUE,
    ],
    self::GET_CONTRIBUTION => [
      'reject' => TRUE,
    ],
    self::PAYMENT_FAILED => [
      'no-email' => TRUE,
    ],
    self::UNKNOWN => [
      'fatal' => TRUE,
    ],
    self::UNSUBSCRIBE => [
      'reject' => TRUE,
    ],
    self::MISSING_PREDECESSOR => [
      'requeue' => TRUE,
      'no-email' => TRUE,
    ],
    self::BANNER_HISTORY => [
      'drop' => TRUE,
      'no-email' => TRUE,
    ],

    // other errors
    self::FILE_NOT_FOUND => [
      'fatal' => TRUE,
    ],
    self::INVALID_FILE_FORMAT => [
      'fatal' => TRUE,
    ],

    self::fredge => [
      'reject' => TRUE,
    ],
    self::MISSING_MANDATORY_DATA => [
      'reject' => TRUE,
    ],
    self::DATA_INCONSISTENT => [
      'reject' => TRUE,
    ],
    self::GET_CONTACT => [
      'fatal' => FALSE,
    ],
    self::EMAIL_SYSTEM_FAILURE => [
      'fatal' => TRUE,
    ],
    self::BAD_EMAIL => [
      'no-email' => TRUE,
    ],
  ];

  var $extra;

  var $type;

  var $userMessage;

  /**
   * WmfException constructor.
   *
   * @param int $code Error code
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
  function __construct($code, $message, $extra = []) {
    if (!array_key_exists($code, self::$error_types)) {
      $message .= ' -- ' . t('Warning, throwing an unknown exception: "%code"', ['%code' => $code]);
      $code = self::UNKNOWN;
    }
    $this->type = array_search($code, (new ReflectionClass($this))->getConstants());
    $this->code = $code;
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

  /**
   * Get string representing category of error
   *
   * @return string
   */
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
    $info = self::$error_types[$this->code];
    if (array_key_exists($property, $info)) {
      return $info[$property];
    }
    return $default;
  }
}
