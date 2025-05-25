<?php

use CRM_WMFFraud_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_WMFFraud_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * Note that if a file is present sql\auto_install that will run regardless of this hook.
   *
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function install(): void {
    $this->createFredgeTables();
    $this->createFredgeViews();
  }

  /**
   * Create the tables in the fredge database.
   *
   * Long term we should either move these into CiviCRM DB or maybe create during
   * buildkit or docker install.
   *
   * @return void
   * @throws \Civi\Core\Exception\DBQueryException
   */
  protected function createFredgeTables(): void {
    CRM_Core_DAO::executeQuery(
      "CREATE TABLE `fredge`.`payments_initial` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contribution_tracking_id` int(11) DEFAULT NULL,
  `gateway` varchar(255) DEFAULT NULL,
  `order_id` varchar(255) DEFAULT NULL,
  `gateway_txn_id` varchar(255) DEFAULT NULL,
  `validation_action` varchar(16) DEFAULT NULL,
  `payments_final_status` varchar(16) DEFAULT NULL,
  `payment_method` varchar(16) DEFAULT NULL,
  `payment_submethod` varchar(32) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `amount` float DEFAULT NULL,
  `currency_code` varchar(3) DEFAULT NULL,
  `server` varchar(64) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contribution_tracking_id` (`contribution_tracking_id`),
  KEY `order_id` (`order_id`),
  KEY `gateway` (`gateway`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='Tracks user experience through donation pipeline.'");
    CRM_Core_DAO::executeQuery(
      "CREATE TABLE IF NOT EXISTS `fredge`.`payments_fraud` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `contribution_tracking_id` int(10) unsigned DEFAULT NULL,
      `gateway` varchar(255) DEFAULT NULL,
      `order_id` varchar(255) DEFAULT NULL,
      `validation_action` varchar(16) DEFAULT NULL,
      `user_ip` varbinary(16) DEFAULT NULL,
      `payment_method` varchar(16) DEFAULT NULL,
      `risk_score` float DEFAULT NULL,
      `server` varchar(64) DEFAULT NULL,
      `date` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `contribution_tracking_id` (`contribution_tracking_id`),
      KEY `order_id` (`order_id`),
      KEY `gateway` (`gateway`),
      KEY `date` (`date`),
      KEY `user_ip` (`user_ip`),
      KEY `risk_score` (`risk_score`),
      KEY `payment_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='Tracks donation fraud scores for all donations.'");

    CRM_Core_DAO::executeQuery(
      "CREATE TABLE IF NOT EXISTS `fredge`.`payments_fraud_breakdown` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `payments_fraud_id` bigint(20) unsigned DEFAULT NULL,
      `filter_name` varchar(64) DEFAULT NULL,
      `risk_score` float DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `payments_fraud_id` (`payments_fraud_id`),
      KEY `filter_name` (`filter_name`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='Tracks breakdown of donation fraud scores for all donations.'"
    );
  }

  /**
   * @return void
   * @throws \Civi\Core\Exception\DBQueryException
   */
  protected function createFredgeViews(): void {
    CRM_Core_DAO::executeQuery('
      CREATE OR REPLACE VIEW payments_fraud AS
      SELECT * FROM fredge.payments_fraud
   ');

    CRM_Core_DAO::executeQuery(
      'CREATE OR REPLACE VIEW payments_fraud_breakdown AS
      SELECT * FROM fredge.payments_fraud_breakdown'
    );

    CRM_Core_DAO::executeQuery('
      CREATE OR REPLACE VIEW payments_initial AS
      SELECT * FROM fredge.payments_initial'
    );
  }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  public function upgrade_4200(): bool {
    $this->ctx->log->info('Applying update 4200');
    $this->createFredgeViews();
    CRM_Core_DAO::executeQuery("UPDATE civicrm_option_value SET name = 'CRM_WMFFraud_Form_Report_Fraud' WHERE name = 'CRM_Wmffraud_Form_Report_Fraud'");
    CRM_Core_DAO::executeQuery("UPDATE civicrm_option_value SET name = 'CRM_WMFFraud_Form_Report_Fredge' WHERE name = 'CRM_Wmffraud_Form_Report_Fredge'");
    CRM_Core_DAO::executeQuery("UPDATE civicrm_option_value SET name = 'CRM_WMFFraud_Form_Report_PaymentAttempts' WHERE name = 'CRM_Wmffraud_Form_Report_PaymentAttempts'");
    return TRUE;
  }

  public function upgrade_4205(): bool {
    $this->ctx->log->info('Applying update 4205');
    $this->createFredgeViews();
    return TRUE;
  }

}
