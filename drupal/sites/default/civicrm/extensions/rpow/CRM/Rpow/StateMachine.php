<?php

use CRM_Rpow_Classifier as Classifier;

/**
 * Use MySQL connections with (up to) three stages;
 *
 * - In the first stage, we execute straight-up read statements on the read-only slave.
 *   Statements with connection-local side-effects (eg "SET @user_id=123" or "CREATE TEMPORARY TABLE")
 *   are be stored in a buffer.
 * - In the second stage, we encounter the first straight-up write statement.
 *   We switch to read-write master, where we replay the buffer along with the write statement.
 * - In the third/final stage, all statements are executed on the read-write master.
 */
class CRM_Rpow_StateMachine {

  /**
   * The current SQL should be directed to the read-only slave.
   */
  const READ_ONLY = 'ro';

  /**
   * The current SQL should be directed to the read-write master.
   */
  const READ_WRITE = 'rw';

  /**
   * The buffer should be replayed on the read-write master.
   */
  const REPLAY = 'rp';

  /**
   * @var string
   *   READ_ONLY or READ_WRITE
   */
  private $state;

  /**
   * @var array
   *   List of SQL statements.
   */
  private $buffer;

  private CRM_Rpow_Classifier $classifier;

  public function __construct() {
    $this->state = self::READ_ONLY;
    $this->buffer = [];
    $this->classifier = new Classifier();
  }

  /**
   * Determine the next state/action based on a new SQL string.
   *
   * @param string $sql
   *   The next SQL statement to execute
   * @return string
   *   What to do with the current statement: READ_ONLY, READ_WRITE, or REPLAY.
   */
  public function handle($sql) {
    switch ($this->state) {
      case self::READ_WRITE:
        return self::READ_WRITE;

      case self::READ_ONLY:
        $type = $this->classifier->classify($sql);
        switch ($type) {
          case Classifier::TYPE_BUFFER:
            $this->buffer[] = $sql;
            return self::READ_ONLY;

          case Classifier::TYPE_READ:
            return self::READ_ONLY;

          case Classifier::TYPE_WRITE:
            $this->state = self::READ_WRITE;
            $this->buffer[] = $sql;
            return self::REPLAY;
        }

      default:
        throw new \RuntimeException("Rpow: Encountered unknown state");

    }
  }

  /**
   * Get the list of replayable statements.
   *
   * @return array
   *   List of SQL statements.
   */
  public function getBuffer() {
    return $this->buffer;
  }

  /**
   * Reset the buffer content.
   *
   * @return array
   *   The old buffer content.
   */
  public function clearBuffer() {
    $oldBuffer = $this->buffer;
    $this->buffer = [];
    return $oldBuffer;
  }

  /**
   * @return string
   *   Ex: READ_ONLY (ro) or READ_WRITE (rw)
   */
  public function getState() {
    return $this->state;
  }

  /**
   * Force the system to use read-write, even if there have been no hints otherwise.
   *
   * @return $this
   */
  public function forceWriteMode() {
    $this->state = self::READ_WRITE;
    return $this;
  }

}
