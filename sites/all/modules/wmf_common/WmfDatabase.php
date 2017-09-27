<?php

//TODO: namespace
class WmfDatabase {
  /**
   * Multiple-database transaction around your callback.
   *
   * FIXME: This is not even two-phase locking.  If any commit fails, the dbs become inconsistent.
   *
   * @param callable $callback
   * @param array $params
   *
   * @return mixed
   *   The result of the function call.
   *
   * @throws \Exception
   */
    static function transactionalCall( $callback, $params ) {
        watchdog( 'wmf_common', "Beginning DB transaction", NULL, WATCHDOG_INFO );
        $drupal_transaction = db_transaction( 'wmf_default', array( 'target' => 'default' ) );
        $ct_transaction = db_transaction( 'wmf_donations', array( 'target' => 'donations' ) );
        $crm_transaction = db_transaction( 'wmf_civicrm', array( 'target' => 'civicrm' ) );
        $native_civi_transaction = new CRM_Core_Transaction();

        try {
            // Do the thing itself
            $result = call_user_func_array( $callback, $params );

            // Detect if anything has marked the native Civi transaction for
			// rollback, and do not proceed if so.
            if (\Civi\Core\Transaction\Manager::singleton()->getFrame()->isRollbackOnly()) {
            	throw new RuntimeException(
            		'Civi Transaction was marked for rollback and Exception was suppressed'
				);
			}
        }
        catch ( Exception $ex ) {
            watchdog( 'wmf_common', "Aborting DB transaction.", NULL, WATCHDOG_INFO );
            $native_civi_transaction->rollback();
            $crm_transaction->rollback();
            $ct_transaction->rollback();
            $drupal_transaction->rollback();

            throw $ex;
        }

        watchdog( 'wmf_common', "Committing DB transaction", NULL, WATCHDOG_INFO );
        $native_civi_transaction->commit();
        unset( $crm_transaction );
        unset( $ct_transaction );
        unset( $drupal_transaction );
        return $result;
    }
}
