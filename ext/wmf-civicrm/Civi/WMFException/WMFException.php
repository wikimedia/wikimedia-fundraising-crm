<?php

namespace Civi\WMFException;

use Exception;
use ReflectionClass;
use Twig\Error\RuntimeError;

class WMFException extends Exception {

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

  const INVALID_FILE_FORMAT = 16;

  const FREDGE = 17;

  const MISSING_MANDATORY_DATA = 18;

  const DATA_INCONSISTENT = 19;

  const BANNER_HISTORY = 20;

  const GET_CONTACT = 21;

  const EMAIL_SYSTEM_FAILURE = 22;

  const BAD_EMAIL = 23;

  const DUPLICATE_INVOICE = 24;

  const CONTRIBUTION_TRACKING = 25;

  const DATABASE_CONTENTION = 26;

  //XXX shit we aren't using the 'rollback' attribute
  // and it's not correct in most of these cases
  /**
   * Array of error types with information as to how to respond.
   *   - fatal
   *   - reject
   *   - requeue - Put the message back in the queue & retry later
   *   - drop
   *   - no-email - Do not send failmail to fr-tech
   *
   * @var array
   */
  static $error_types = [
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

    self::INVALID_FILE_FORMAT => [
      'fatal' => TRUE,
    ],

    self::FREDGE => [
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
    self::CONTRIBUTION_TRACKING => [
      'fatal' => TRUE,
    ],
    self::DATABASE_CONTENTION => [
      'requeue' => TRUE,
      // Ideally we would like to be notified only if these exceed some sort of threshold.
      // Not having done that work yet we'd rather ignore a bit of failmail than not notice
      // a server-killing query. Also note that grafana might be able to monitor deadlocks on a more
      // mysql-y level.
      'no-email' => FALSE,
    ],
  ];

  var $extra;

  var $type;

  var $userMessage;

  /**
   * WMFException constructor.
   *
   * @param int $code Error code
   * @param string $message A WMF constructed message.
   * @param array $extra Extra parameters.
   *   If error_message is included then it will be included in the User Error
   *   message. If you are working with a CiviCRM Exception ($e) then you can
   *   pass in $e->getErrorData() which will include the api error message
   *   and message and potentially backtrace & sql details (if you passed in
   *   'debug' => 1). Any data in the $extra array will be rendered in fail
   *   mails - but only 'error_message' is used for user messages (provided the
   *   getUserMessage function is used).
   * @param \Throwable|null $previous
   *    A previous exception which caused this new exception.
   */
  public function __construct($code, $message, $extra = [], ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
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
    unset($addToMessage['trace'], $addToMessage['exception']);
    $this->message = $this->message . "\nSource: " . var_export($addToMessage, TRUE);
  }

  /**
   * Get error message intended for end users.
   *
   * @return string
   */
  public function getUserErrorMessage(): string {
    if ($this->type === self::DATABASE_CONTENTION) {
      // Add a user-friendly message for database contention as it happens during manual imports & turns out to be the
      // underlying error for the message 'financial_type_id is not valid: Benevity'
      return ts('The database is under heavy load and failed to process this row. Try again when it is quieter');
    }
    return (string) (!empty($this->extra['error_message']) ? $this->extra['error_message'] : $this->userMessage);
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

  public function isRequeue(): bool {
    if ($this->getErrorCharacteristic('requeue', FALSE)) {
      return TRUE;
    }
    if ($this->extra) {
      // The above should have already picked up any deadlocks but
      // perhaps this legacy check still picks that up?
      // We want to retry later if the problem was a lock wait timeout
      // or a deadlock. This string parsing used to be the only way to determine that.
      $flattened = print_r($this->extra, TRUE);
      if (
        preg_match('/\'12(05|13) \*\* /', $flattened) ||
        preg_match('/Database lock encountered/', $flattened)
        // @todo not treating constraints as deadlocks here at this stage - doing that
        // more specifically but something to keep considering.
        || ( $this->extra['error_code'] ?? null ) === 'deadlock'
      ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  function isFatal() {
    return $this->getErrorCharacteristic('fatal', FALSE);
  }

  function isNoEmail() {
    //start hack
    if (is_array($this->extra) && array_key_exists('email', $this->extra)) {
      $no_failmail = explode(',', (string) \Civi::settings()->get('wmf_failmail_exclude_list'));
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
