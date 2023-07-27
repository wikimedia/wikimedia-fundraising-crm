<?php

require_once 'DB/common.php';
require_once 'DB/mysqli.php';

use CRM_Rpow_StateMachine as StateMachine;

/**
 * This is a wrapper for DB_mysqli which swaps the underlying connection
 * in accordance with the "replay-on-write" strategy.
 *
 * NOTE: PEAR DB is designed to pass around a single DSN string, but civirpow
 * needs to choose among many different/possible DSNs -- and it would be
 * a bit ugly to cram them all into one string. Instead, we  use a dummy
 * DSN ('civirpow://`) and then read the actual data from
 * a global variable $civirpow.
 */
class DB_civirpow extends DB_mysqli {

  var $phptype = 'civirpow';

  /**
   * @var StateMachine
   */
  public $stateMachine;

  public function __construct() {
    $config = $this->getConfig();
    $this->stateMachine = $config['stateMachine'];
    if (!empty($config['forceWrite'])) {
      $this->stateMachine->forceWriteMode();
    }

    parent::__construct();
  }

  public function connect($dsn, $persistent = FALSE) {
    $config = $this->getConfig();

    $state = $this->stateMachine->getState();
    switch ($state) {
      case StateMachine::READ_ONLY:
        $dsns = $config['slaves'];
        break;

      case StateMachine::READ_WRITE:
        $dsns = $config['masters'];
    }

    if (count($dsns) > 1) {
      shuffle($dsns);
    }

    $chosenDsn = $dsns[0];
    // If you want to inspect the connection+reconnection process, this is a handy place for a breakpoint. Note $state and $chosenDsn.
    $parsedDSN = DB::parseDSN($chosenDsn);
    $isSSL = 0;
    // Any of these could indicate an ssl connection.
    // https://docs.civicrm.org/installation/en/latest/general/mysql_tls/#dsn-settings
    foreach (['ssl', 'key', 'cert', 'ca', 'capath', 'cipher'] as $key) {
      if (!empty($parsedDSN[$key])) {
        $isSSL = 1;
      }
    }

    $this->setOption('ssl', (int) $isSSL);
    return parent::connect($parsedDSN, $persistent);
  }

  public function simpleQuery($query) {
    // echo "<pre>[[[$query]]]</pre> <br>";
    switch ($this->stateMachine->handle($query)) {
      case StateMachine::READ_ONLY:
      case StateMachine::READ_WRITE:
        return parent::simpleQuery($query);

      case StateMachine::REPLAY:
        return $this->reconnectAndReplay();
    }
  }

  /**
   * Break the connection to the read-only slave; open a connection
   * to the read-write master; replay any buffered steps.
   *
   * @return mixed|null
   *   The result of executing the last replay-query.
   */
  protected function reconnectAndReplay() {
    $config = $this->getConfig();
    $this->disconnect();
    $this->connect([]);

    $result = NULL;
    // dpm(['replaying' => $this->stateMachine->getBuffer()]);
    foreach ($this->stateMachine->getBuffer() as $bufferedSql) {
      $result = parent::simpleQuery($bufferedSql);
    }

    if (isset($config['onReconnect'])) {
      foreach ($config['onReconnect'] as $callback) {
        call_user_func($callback, $config, $this);
      }
    }
    return $result;
  }

  /**
   * Force the system to use the read-write master connection.
   * If we're not using it already, then switch over to it.
   */
  protected function forceWriteMode() {
    if ($this->stateMachine->getState() === StateMachine::READ_WRITE) {
      return;
    }

    $this->stateMachine->forceWriteMode();
    $this->reconnectAndReplay();
  }

  public function commit() {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::commit();
  }

  public function rollback() {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::rollback();
  }

  public function getSequenceName($sqn) {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::getSequenceName($sqn);
  }

  public function tableInfo($result, $mode = NULL) {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::tableInfo($result, $mode);
  }

  /**
   * @return mixed
   */
  protected function getConfig() {
    return $GLOBALS['civirpow'];
  }

}
