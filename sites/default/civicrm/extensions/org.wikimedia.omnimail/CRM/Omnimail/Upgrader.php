<?php

/**
 * Collection of upgrade steps.
 */
class CRM_Omnimail_Upgrader extends CRM_Omnimail_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
  public function install() {
    $this->executeSqlFile('sql/myinstall.sql');
  }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   *
  public function postInstall() {
    $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
      'return' => array("id"),
      'name' => "customFieldCreatedViaManagedHook",
    ));
    civicrm_api3('Setting', 'create', array(
      'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
    ));
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
  public function uninstall() {
   $this->executeSqlFile('sql/myuninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled.
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled.
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }
   */

  /**
   * Convert setting tracking to table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1000() {
    $this->ctx->log->info('Applying update 1000');
    CRM_Core_DAO::executeQuery('
      CREATE TABLE civicrm_omnimail_job_progress (
       `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
       `mailing_provider` VARCHAR(32) NOT NULL,
       `job` VARCHAR(32) NULL,
       `job_identifier` VARCHAR(32) NULL,
       `last_timestamp` timestamp NULL,
       `progress_end_timestamp` timestamp NULL,
       `retrieval_parameters` VARCHAR(255) NULL,
       `offset` INT(10) unsigned,
         PRIMARY KEY (`id`)
      ) ENGINE=InnoDB CHARSET=utf8 COLLATE=utf8_unicode_ci');

    foreach (array('omnimail_omnigroupmembers_load', 'omnimail_omnirecipient_load') as $job) {
      $settings = civicrm_api3('Setting', 'get', array('return' => $job));
      foreach ($settings['values'][CRM_Core_Config::domainId()][$job] as $mailingProvider => $setting) {
        $mailingProviderParts = explode('_', $mailingProvider);
        $jobIdentifier = isset($mailingProviderParts[1]) ? "'" . $mailingProviderParts[1] . "'" : 'NULL';
        $progressEndTimestamp = isset($setting['progress_end_date']) ? 'FROM_UNIXTIME(' . $setting['progress_end_date'] . ')' : 'NULL';
        $retrievalParameters = isset($setting['retrieval_parameters']) ? "'" . json_encode($setting['retrieval_parameters']) . "'" : 'NULL';
        $offset = isset($setting['offset']) ? $setting['offset'] : 0;
        CRM_Core_DAO::executeQuery(
          "INSERT INTO civicrm_omnimail_job_progress
        (`mailing_provider`, `job`, `job_identifier`, `last_timestamp`, `progress_end_timestamp`, `retrieval_parameters`, `offset`)
        values ('{$mailingProviderParts[0]}', '{$job}', $jobIdentifier, FROM_UNIXTIME( {$setting['last_timestamp']} ), $progressEndTimestamp, $retrievalParameters, $offset)
         "
        );
      }
    }
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_setting WHERE name IN ('omnimail_omnigroupmembers_load', 'omnimail_omnirecipient_load')");

    return TRUE;
  }

  /**
   * Extend job_identifier table as it needs to store emails with a json outer wrapper.
   *
   * Email in theory could be 255 so 512
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1001() {
    $this->ctx->log->info('Applying update 1001, increasing length of job_identifier');
    CRM_Core_DAO::executeQuery('
      ALTER TABLE civicrm_omnimail_job_progress
      MODIFY job_identifier VARCHAR(512)
    ');
    return TRUE;
  }
   /*


  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
