<?php
use CRM_Wmf_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Wmf_Upgrader extends CRM_Wmf_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   */
  public function install() {
    $settings = new CRM_Wmf_Upgrader_Settings();
    $settings->setWmfSettings();
  }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  // public function postInstall() {
  //  $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
  //    'return' => array("id"),
  //    'name' => "customFieldCreatedViaManagedHook",
  //  ));
  //  civicrm_api3('Setting', 'create', array(
  //    'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
  //  ));
  // }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   */
  // public function uninstall() {
  //  $this->executeSqlFile('sql/myuninstall.sql');
  // }

  /**
   * Example: Run a simple query when a module is enabled.
   */
  // public function enable() {
  //  CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a simple query when a module is disabled.
   */
  // public function disable() {
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  // }

  /**
   * Run sql to remove end dates from ongoing recurring contributions.
   *
   * This appears to be a historical housekeeping issue. Some ongoing
   * contributions have end dates that appear to have been added a while ago.
   *
   * Bug: T283798
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4200(): bool {
    CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
SET end_date = NULL WHERE id IN
(
  SELECT cr.id
  FROM civicrm_contribution_recur cr
  INNER JOIN civicrm_contribution c ON c.contribution_recur_id = cr.id
    AND cr.end_date IS NOT NULL
    AND cr.contribution_status_id NOT IN (3, 4)
    AND cr.cancel_reason IS NULL
  GROUP BY cr.id
  HAVING max(receive_date) > '2021-05-01'
);");
    return TRUE;
  }

  /**
   * Run sql to add end dates to old recurring contributions.
   *
   * This just adds them to those that are a couple of years
   * in an attempt to deal with the more legacy data.
   *
   * Bug: T283798
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4201(): bool {
    // tested on staging Query OK, 4040 rows affected (24.646 sec)
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_contribution_recur cr
      INNER JOIN (
        SELECT cr.id, MAX(receive_date) mx
        FROM civicrm_contribution_recur cr
          LEFT JOIN civicrm_contribution c ON c.contribution_recur_id = cr.id
        WHERE end_date IS NULL
        AND cr.cancel_date IS NULL AND cr.contribution_status_id NOT IN(3,4)
        GROUP BY cr.id HAVING mx < '2019-06-01'
      ) as a
        ON a.id = cr.id
      SET cr.end_date = a.mx
    ");
    return TRUE;
  }

  /**
   * Run sql to remove end dates from ongoing recurring contributions.
   *
   * This appears to be a historical housekeeping issue. Some ongoing
   * contributions have end dates that appear to have been added a while ago.
   *
   * Note that the above query did this but required cancel_reason to be null.
   * There are 8796 rows where cancel reason is one of the 2 in the new query.
   * All of these have cancel_dates in 2018 and in fact the inclusion of both
   * cr.end_date < '2019-01-01' AND
   * cr.cancel_reason IN ('(auto) backfilled automated cancel', '(auto) backfilled automated Expiration notification')
   *
   * is for clarity - either can be removed without altering the result set.
   *
   * Bug: T283798
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4203(): bool {
    CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
SET end_date = NULL WHERE id IN
(
  SELECT cr.id
  FROM civicrm_contribution_recur cr
  INNER JOIN civicrm_contribution c ON c.contribution_recur_id = cr.id
    AND cr.end_date IS NOT NULL
    AND cr.end_date < '2019-01-01'
    AND cr.contribution_status_id NOT IN (3, 4)
    AND cr.cancel_reason IN ('(auto) backfilled automated cancel', '(auto) backfilled automated Expiration notification')
  GROUP BY cr.id
  HAVING max(receive_date) > '2021-05-01'
);");

    // Also set contribution_status_id to IN PROGRESS for contributions
    // with future planned payments and a status of 'Completed'
    // I found 328 of these - all created before our fix to have
    // a default of 'Pending' in Feb 2021.
    CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur SET contribution_status_id = 5  WHERE next_sched_contribution_date > '2021-09-01' AND contribution_status_id = 1");

    // And set end dates for past 'completed' recurring contributions.
    // 4084 rows in set (0.267 sec)
    // Note I'm setting the goal of all completed contributions
    // having an end_date to get us to some sort of data integrity.
    // This doesn't quite get us there - but gets the number down
    // for a bit more analysis
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_contribution_recur cr
      INNER JOIN (
        SELECT cr.id, cr.contact_id, start_date, MAX(receive_date) mx
        FROM civicrm_contribution_recur cr
          LEFT JOIN civicrm_contribution c ON c.contribution_recur_id = cr.id
        WHERE end_date IS NULL
        AND cr.cancel_date IS NULL
        AND cr.contribution_status_id = 1
        AND next_sched_contribution_date < '2021-05-01'
        GROUP BY cr.id HAVING mx < '2021-05-01'
      ) as a
        ON a.id = cr.id
      SET cr.end_date = a.mx
    ");

    return TRUE;
  }

  /**
  * Run sql to update the contribution_status of duplicated Adyen records to cancelled (3)
  *
  * Bug: T290177
  *
  * @return TRUE on success
  * @throws Exception
  */
  public function upgrade_4205(): bool {
    // 144 expected updates
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_contribution c_payments
      SET
          c_payments.contribution_status_id = 3
      WHERE
          id IN (SELECT
                  c_payments.id
              FROM
                  civicrm_contribution c_payments
                      INNER JOIN
                  wmf_contribution_extra x_payments ON x_payments.entity_id = c_payments.id
                      AND x_payments.source_type = 'payments'
                      AND x_payments.gateway = 'adyen'
                      INNER JOIN
                  civicrm_contribution c_ipn ON c_payments.contact_id = c_ipn.contact_id
                      AND c_payments.total_amount = c_ipn.total_amount
                      AND c_ipn.receive_date > '2021-08-31'
                      INNER JOIN
                  wmf_contribution_extra x_ipn ON x_ipn.entity_id = c_ipn.id
                      AND x_ipn.source_type = 'listener'
              WHERE
                  c_payments.receive_date > '2021-08-31'
              GROUP BY c_payments.id
              ORDER BY c_payments.id DESC)");
    return TRUE;
  }

  /**
   * Fix up the last 'completed' oddities for 'Completed' recurring contributions.
   *
   * After previous fix ups we still have 1971 'completed' contributions with
   * no end date or cancel date. I have determined that 10 of these are in progress
   * and the rest should have an end date. The 3 queries in this update should clear
   * them all out & we should hopefully be done with this data snaffu.
   * Bug: T283798
   *
   * @return TRUE on success
   */
  public function upgrade_4206(): bool {
    // Step 1 - set status to 'in progress' where the contributions are genuinely ongoing
    // there are 10 of these (only!).
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_contribution_recur SET contribution_status_id = 5
      WHERE id IN (
        SELECT cr.id
        FROM civicrm_contribution_recur cr
          LEFT JOIN civicrm_contribution c ON c.contribution_recur_id = cr.id
        WHERE end_date IS NULL
           AND cr.cancel_date IS NULL
           AND cr.contribution_status_id = 1
           AND next_sched_contribution_date > NOW()
        GROUP BY cr.id HAVING max(receive_date) > '2021-08-01'
        )
    ");

    // Step 2 - set end_date to max(receive_date) where contributions have been
    // received but have stopped (76)
    // This is the same query as in 4203 but considering the ones
    // with no payments since the start of August as being completed.
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_contribution_recur cr
      INNER JOIN (
        SELECT cr.id, cr.contact_id, start_date, MAX(receive_date) mx
        FROM civicrm_contribution_recur cr
          LEFT JOIN civicrm_contribution c ON c.contribution_recur_id = cr.id
        WHERE end_date IS NULL
        AND cr.cancel_date IS NULL
        AND cr.contribution_status_id = 1
        AND next_sched_contribution_date < NOW()
        GROUP BY cr.id HAVING mx < '2021-08-01'
      ) as a
        ON a.id = cr.id
      SET cr.end_date = a.mx
    ");

    // Step 3 - update those with no contributions. Note that none of these started
    // after 28 Jan
    // 1885 rows.
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_contribution_recur SET end_date = start_date WHERE id IN (
        SELECT cr.id
        FROM civicrm_contribution_recur cr
          LEFT JOIN civicrm_contribution c ON c.contribution_recur_id = cr.id
        WHERE end_date IS NULL
          AND cr.cancel_date IS NULL
          AND cr.contribution_status_id = 1
          -- The start date filter is for clarity only
          -- no additional rows are filtered as a result
          AND cr.start_date < '2021-02-01'
          AND c.id IS NULL
     )
    ");
    return TRUE;
  }

  /**
   * Drop indexes on wmf_donor fields identified as not required to be searchable.
   *
   * Bug: T288721
   *
   * @return TRUE on success
   */
  public function upgrade_4207(): bool {
    $indexesToDrop = [
      'total_2006_2007',
      'total_2007_2008',
      'total_2008_2009',
      'total_2009_2010',
      'total_2010_2011',
      'total_2011_2012',
      'total_2012_2013',
      'total_2013_2014',
      'total_2014_2015',
      'total_2006',
      'total_2007',
      'total_2008',
      'total_2009',
      'total_2010',
      'total_2011',
      'total_2012',
      'total_2013',
      'total_2014',
      'change_2017_2018',
      'change_2018_2019',
      'change_2019_2020',
      'change_2020_2021',
      'change_2021_2022',
    ];
    foreach ($indexesToDrop as $oldIndex) {
      // I see the first format for some keys on live, the second on dev.
      $indexesToDrop[] = 'index_' . $oldIndex;
      $indexesToDrop[] = 'INDEX_' . $oldIndex;
    }
    $sql = [];
    $existing = CRM_Core_DAO::executeQuery("SHOW INDEX FROM wmf_donor");
    $toDrop = [];
    while ($existing->fetch()) {
      if (in_array($existing->Key_name, $indexesToDrop, TRUE)) {
        $toDrop[] = $existing->Key_name;
      }
    }

    foreach ($toDrop as $index) {
      if (CRM_Core_BAO_SchemaHandler::checkIfIndexExists('wmf_donor', $index)){
        $sql[] = ' DROP INDEX ' . $index;
      }
    }
    CRM_Core_DAO::executeQuery('ALTER TABLE wmf_donor' . implode(' , ', $sql));

    return TRUE;
  }

  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
   */
  // public function upgrade_4202() {
  //   $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

  //   $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
  //   $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
  //   $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
  //   return TRUE;
  // }
  // public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  // public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  // public function processPart3($arg5) { sleep(10); return TRUE; }

  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
   */
  // public function upgrade_4203() {
  //   $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

  //   $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
  //   $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
  //   for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
  //     $endId = $startId + self::BATCH_SIZE - 1;
  //     $title = E::ts('Upgrade Batch (%1 => %2)', array(
  //       1 => $startId,
  //       2 => $endId,
  //     ));
  //     $sql = '
  //       UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
  //       WHERE id BETWEEN %1 and %2
  //     ';
  //     $params = array(
  //       1 => array($startId, 'Integer'),
  //       2 => array($endId, 'Integer'),
  //     );
  //     $this->addTask($title, 'executeSql', $sql, $params);
  //   }
  //   return TRUE;
  // }

}
