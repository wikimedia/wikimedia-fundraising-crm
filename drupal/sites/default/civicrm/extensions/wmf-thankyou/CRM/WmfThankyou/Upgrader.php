<?php
use CRM_WmfThankyou_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_WmfThankyou_Upgrader extends CRM_WmfThankyou_Upgrader_Base {

  /**
   * Add the relevant activity type.
   */
  public function install() {
    CRM_Core_BAO_OptionValue::ensureOptionValueExists([
      'label' => 'Sent year-end summary receipt',
      'name' => 'wmf_eoy_receipt_sent',
      'weight' => '1',
      'description' => 'Sent an email receipt summarizing all donations in a given year',
      'option_group_id' => 'activity_type',
    ]);
  }

  /**
   * Add year field.
   *
   * @return bool
   */
  public function upgrade_0001(): bool {
    $this->ctx->log->info('Applying update 0001 - add year field');
    CRM_Core_DAO::executeQuery('
      ALTER TABLE wmf_eoy_receipt_donor
      ADD COLUMN `year` INT(10) UNSIGNED DEFAULT NULL,
      ADD INDEX `email_year` (email, year),
      DROP INDEX `email`,
      DROP INDEX wmf_eoy_receipt_donor_job_id_email
    ');
    return TRUE;
  }

  /**
   * Drop old table/fields.
   *
   * @return bool
   */
  public function upgrade_0002(): bool {
    $this->ctx->log->info('Applying update 0002 - drop old table & fields');
    CRM_Core_DAO::executeQuery('
      ALTER TABLE wmf_eoy_receipt_donor
      DROP COLUMN contributions_rollup,
      DROP COLUMN preferred_language,
      DROP COLUMN name,
      DROP COLUMN job_id
    ');

    CRM_Core_DAO::executeQuery('
      DROP TABLE wmf_eoy_receipt_job
    ');
    return TRUE;
  }

  /**
   * Add id field.
   *
   * @return bool
   */
  public function upgrade_0003(): bool {
    $this->ctx->log->info('Applying update 0003 - add id field');
    CRM_Core_DAO::executeQuery('
      ALTER TABLE wmf_eoy_receipt_donor
      ADD COLUMN `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT "EOY email job ID",
      ADD PRIMARY KEY (`id`)
    ');
    return TRUE;
  }

}
