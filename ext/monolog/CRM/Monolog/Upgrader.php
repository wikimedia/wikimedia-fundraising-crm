<?php
use CRM_Monolog_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Monolog_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   */
  public function upgrade_0001(): true {
    $this->ctx->log->info('Altering name to 32 char');
    CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_monolog MODIFY COLUMN name VARCHAR(32) DEFAULT NULL');
    CRM_Core_DAO::executeQuery('ALTER TABLE log_civicrm_monolog MODIFY COLUMN name VARCHAR(32) DEFAULT NULL');
    CRM_Core_DAO::executeQuery('UPDATE civicrm_monolog SET name = "cli_std_out_logger" WHERE name = "cli_std_out_l..."');
    return TRUE;
  }

}
