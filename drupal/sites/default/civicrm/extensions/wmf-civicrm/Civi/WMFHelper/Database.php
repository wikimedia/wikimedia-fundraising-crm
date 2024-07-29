<?php

namespace Civi\WMFHelper;

use Civi\Core\Transaction\Manager;

class Database {

  /**
   * Multiple-database transaction around your callback.
   *
   * FIXME: This is not even two-phase locking.  If any commit fails, the dbs
   * become inconsistent.
   *
   * @param callable $callback
   * @param array $params
   *
   * @return mixed
   *   The result of the function call.
   *
   * @throws \Exception
   */
  static function transactionalCall($callback, $params) {
    \Civi::log('wmf')->info('Beginning DB transaction');
    $drupal_transaction = Drupal::getTransaction('drupal');
    $crm_transaction = Drupal::getTransaction('civicrm');
    $native_civi_transaction = new \CRM_Core_Transaction();

    try {
      // Do the thing itself
      $result = call_user_func_array($callback, $params);

      // Detect if anything has marked the native Civi transaction for
      // rollback, and do not proceed if so.
      if (self::isNativeTxnRolledBack()) {
        throw new \RuntimeException(
          'Civi Transaction was marked for rollback and Exception was suppressed'
        );
      }
    }
    catch (\Exception $ex) {
      \Civi::log('wmf')->info('wmf_common: Aborting DB transaction.');
      $native_civi_transaction->rollback();
      $crm_transaction->rollback();
      $drupal_transaction->rollback();

      throw $ex;
    }

    \Civi::log('wmf')->info('wmf_common: Committing DB transaction');
    $native_civi_transaction->commit();
    unset($crm_transaction);
    unset($drupal_transaction);
    return $result;
  }

  public static function isNativeTxnRolledBack() {
    if (!empty($GLOBALS['CIVICRM_TEST_CASE'])) {
      return FALSE;
    }
    $frame = Manager::singleton()->getFrame();
    return $frame && $frame->isRollbackOnly();
  }

}
