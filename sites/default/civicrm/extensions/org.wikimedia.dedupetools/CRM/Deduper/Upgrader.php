<?php
use CRM_Deduper_ExtensionUtil as E;
use League\Csv\Reader;

/**
 * Collection of upgrade steps.
 */
class CRM_Deduper_Upgrader extends CRM_Deduper_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   */
  public function install() {
    $this->executeSqlFile('sql/auto_install.sql');
    $this->prePopulateNameMatchTable();
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
   */
  public function uninstall() {
   $this->executeSqlFile('sql/auto_uninstall.sql');
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

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4200() {
    $this->ctx->log->info('Applying update 4200');
    CRM_Core_DAO::executeQuery("
CREATE TABLE IF NOT EXISTS `civicrm_contact_name_pair` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
  `name_a` varchar(64) NOT NULL DEFAULT '',
  `name_b` varchar(64) NOT NULL DEFAULT '',
  `is_name_b_nickname` tinyint(10) NOT NULL DEFAULT '0',
  `is_name_b_inferior` tinyint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `name_a` (`name_a`),
  KEY `name_b` (`name_b`),
  KEY `is_name_b_nickname` (`is_name_b_nickname`),
  KEY `is_name_b_inferior` (`is_name_b_inferior`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

");

    $this->prePopulateNameMatchTable();
    return TRUE;
  }

  /**
   * Pre-populate name match table with common mis-spellings & alternatives.
   */
  public function prePopulateNameMatchTable() {
    $reader = Reader::createFromPath(__DIR__ . '/name_matches.csv', 'r');
    $reader->setHeaderOffset(0);
    foreach ($reader as $row) {
      CRM_Core_DAO::executeQuery(
        'INSERT INTO civicrm_contact_name_pair
        (name_a, name_b, is_name_b_nickname, is_name_b_inferior)
         VALUES (%1, %2, %3, %4)
      ', [
        1 => [$row['name_a'], 'String'],
        2 => [$row['name_b'], 'String'],
        3 => [$row['is_name_b_nickname'], 'Integer'],
        4 => [$row['is_name_b_inferior'], 'Integer'],
      ]);
    }

  }

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

    $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
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
      $title = E::ts('Upgrade Batch (%1 => %2)', array(
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
