<?php

use Civi\Api4\WMFConfig;
use Civi\Api4\OptionValue;
use CRM_Wmf_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Wmf_Upgrader extends CRM_Wmf_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \API_Exception
   */
  public function install(): void {
    $settings = new CRM_Wmf_Upgrader_Settings();
    $settings->setWmfSettings();
    $this->addCustomFields();
    // Reset navigation on install.
    civicrm_api3('Navigation', 'reset', ['for' => 'report']);

    // Update es_MX display name to "Spanish (Latin America)"
   OptionValue::update(FALSE)
      ->addWhere('option_group_id:name', '=', 'languages')
      ->addWhere('name', '=', 'es_MX')
      ->addValue('label', 'Spanish (Latin America)')
      ->addValue('value', 'es_MX')
      ->execute();

    $this->syncGeocoders();
    // Bug: T115044 Add index to nick_name column as we have decided to use it for Benevity imports.
    CRM_Core_BAO_SchemaHandler::createIndexes(['civicrm_contact' => ['nick_name']]);

    // Bug: T228106 Add index to civicrm_activity.location.
    CRM_Core_BAO_SchemaHandler::createIndexes(['civicrm_activity' => ['location']]);
  }

  /**
   * Create WMF specific custom fields.
   *
   * @throws \API_Exception
   */
  public function addCustomFields(): void {
    WMFConfig::syncCustomFields(FALSE)->execute();
  }

  /**
   * Create WMF specific custom fields.
   *
   * @throws \API_Exception
   */
  public function syncGeocoders(): void {
    WMFConfig::syncGeocoders(FALSE)->execute();
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
      'change_2021_2022'
    ];

    $this->dropIndexes($indexesToDrop, 'wmf_donor');

    return TRUE;
  }

  /**
   * Drop indexes on wmf_donor fields identified as not required to be searchable.
   *
   * Bug: T288721
   *
   * @return TRUE on success
   */
  public function upgrade_4208(): bool {
    $this->dropIndexes(['total_2015', 'total_2014_2015'], 'wmf_donor');
    return TRUE;
  }

  /**
   * Drop indexes.
   *
   * @param array $oldIndexes
   * @param string $table
   */
  protected function dropIndexes(array $oldIndexes, string $table): void {
    $sql = 'ALTER TABLE ' . $table;
    foreach ($oldIndexes as $index) {
      if (CRM_Core_BAO_SchemaHandler::checkIfIndexExists($table, $index)) {
        $sql .= ' DROP INDEX ' . $index;
      }
      if (CRM_Core_BAO_SchemaHandler::checkIfIndexExists($table, 'index_' . $index)) {
        $sql .= ' DROP INDEX index_' . $index;
      }
    }
    CRM_Core_DAO::executeQuery($sql);
  }

  /**
   * Fill new wmf_donor fields.
   *
   * Bug: T288721
   *
   * @return TRUE on success
   */
  public function upgrade_4209(): bool {
    CRM_Core_DAO::executeQuery("UPDATE wmf_donor
SET
    all_funds_total_2018_2019 = endowment_total_2018_2019 + total_2018_2019,
    all_funds_total_2019_2020 = endowment_total_2019_2020 + total_2019_2020,
    all_funds_total_2020_2021 = endowment_total_2020_2021 + total_2020_2021,
    all_funds_total_2021_2022 = endowment_total_2021_2022 + total_2021_2022,
    endowment_change_2020_2021 = endowment_total_2021 - endowment_total_2020,
    all_funds_change_2018_2019 = endowment_total_2019 + total_2019 - endowment_total_2018 - total_2018,
    all_funds_change_2019_2020 = endowment_total_2020 + total_2020 - endowment_total_2019 - total_2019,
    all_funds_change_2020_2021 = endowment_total_2021 + total_2021 - endowment_total_2020 - total_2020,
    all_funds_largest_donation = endowment_largest_donation + wmf_donor.largest_donation,
    all_funds_last_donation_date = IF(endowment_last_donation_date IS NOT NULL AND endowment_last_donation_date > last_donation_date, endowment_last_donation_date, last_donation_date),
    all_funds_first_donation_date = IF(endowment_first_donation_date IS NOT NULL AND endowment_first_donation_date < first_donation_date, endowment_first_donation_date, first_donation_date),
    all_funds_number_donations = number_donations + wmf_donor.endowment_number_donations
");
    return TRUE;
  }

  /**
   * Remove legacy field while triggers are off.
   *
   * Bug: T288721
   *
   * @return TRUE on success
   */
  public function upgrade_4210(): bool {
    CRM_Core_BAO_SchemaHandler::dropColumn('civicrm_event_carts', 'coupon_code');
    return TRUE;
  }

  /**
   * Fix column field type.
   *
   * Bug: T288721
   *
   * @return TRUE on success
   */
  public function upgrade_4212(): bool {
    CRM_Core_DAO::executeQuery(
      'ALTER TABLE wmf_donor

  MODIFY COLUMN `change_2017_2018` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `change_2018_2019` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `change_2019_2020` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `change_2020_2021` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `change_2021_2022` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `all_funds_change_2018_2019` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `all_funds_change_2019_2020` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `endowment_change_2020_2021` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `all_funds_change_2020_2021` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `all_funds_total_2021_2022` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `endowment_change_2021_2022` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `all_funds_change_2021_2022` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `change_2022_2023` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `endowment_change_2022_2023` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `all_funds_change_2022_2023` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `change_2023_2024` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `endowment_change_2023_2024` decimal(20,2) DEFAULT 0.00,
  MODIFY COLUMN `all_funds_change_2023_2024` decimal(20,2) DEFAULT 0.00

      '
    );
    return TRUE;
  }

  /**
   * Fix column field type in custom field table.
   *
   * Bug: T288721
   *
   * @return TRUE on success
   */
  public function upgrade_4215(): bool {
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_custom_field SET data_type = 'Money'
      WHERE data_type = 'Float' AND name IN (
      'change_2017_2018',
      'change_2018_2019',
      'change_2019_2020',
      'change_2020_2021',
      'change_2021_2022',
      'all_funds_change_2018_2019',
      'all_funds_change_2019_2020',
      'endowment_change_2020_2021',
      'all_funds_change_2020_2021',
      'all_funds_total_2021_2022',
      'endowment_change_2021_2022',
      'all_funds_change_2021_2022',
      'change_2022_2023',
      'endowment_change_2022_2023',
      'all_funds_change_2022_2023',
      'change_2023_2024',
      'endowment_change_2023_2024',
      'all_funds_change_2023_2024'
      )
    ");
    return TRUE;
  }

  /**
   * Run sql to reset the accidentally cancelled ideal recurrings during the token update when switching from Adyen to
   * Adyen Checkout
   *
   * Bug: T277120
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_4217(): bool {
    // 61 records
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_contribution_recur
      SET
          cancel_date = NULL,
          failure_count = 2,
          cancel_reason = NULL,
          contribution_status_id = 5,
          next_sched_contribution_date = '2021-10-08 00:00:00'
      WHERE
          id IN (SELECT
                  civicrm_contribution_recur.id
              FROM
                  civicrm_contribution_recur
                      INNER JOIN
                  civicrm_contact ON civicrm_contribution_recur.contact_id = civicrm_contact.id
              WHERE civicrm_contribution_recur.cycle_day = 2
                  AND civicrm_contribution_recur.payment_processor_id = 1
                  AND civicrm_contribution_recur.invoice_id IS NOT NULL
                  AND civicrm_contribution_recur.cancel_date > '2021-10-04 00:00'
                  AND civicrm_contribution_recur.cancel_date < '2021-10-04 23:59'
                  AND civicrm_contribution_recur.currency = 'EUR'
                  AND civicrm_contact.preferred_language = 'nl_NL')");
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

  public function upgrade_4218(): bool {
    $oldOptionGroupId = CRM_Core_DAO::singleValueQuery(
      "SELECT option_group_id FROM civicrm_custom_field WHERE name = 'Endowment_Level'");
      if(!$oldOptionGroupId){
        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_group ( `name`, `title`, `is_active` ) VALUES ('Benefactor_Page_Listing_Endowment_Level', 'Benefactor Page Listing :: Endowment Level', 1)");
        $newOptionGroupId = CRM_Core_DAO::singleValueQuery(
          "SELECT id FROM civicrm_option_group WHERE name = 'Benefactor_Page_Listing_Endowment_Level'");
        if ($newOptionGroupId)
        {
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_value ( `option_group_id`, `label`, `value`, `name`, `filter`, `is_default`, `weight`, `description`, `is_optgroup`, `is_reserved`, `is_active` )
  VALUES ($newOptionGroupId, '$5m+', 1, '$5m+',  0,  0, 1, '', 0, 0, 1)");
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_value ( `option_group_id`, `label`, `value`, `name`, `filter`, `is_default`, `weight`, `description`, `is_optgroup`, `is_reserved`, `is_active` )
  VALUES ($newOptionGroupId, '$1m+', 2, '$5m+',  0,  0, 2, '', 0, 0, 1)");
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_value ( `option_group_id`, `label`, `value`, `name`, `filter`, `is_default`, `weight`, `description`, `is_optgroup`, `is_reserved`, `is_active` )
  VALUES ($newOptionGroupId, '$100k+', 3, '$5m+',  0,  0, 3, '', 0, 0, 1)");
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_value ( `option_group_id`, `label`, `value`, `name`, `filter`, `is_default`, `weight`, `description`, `is_optgroup`, `is_reserved`, `is_active` )
  VALUES ($newOptionGroupId, '$50k+', 4, '$5m+',  0,  0, 4, '', 0, 0, 1)");
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_value ( `option_group_id`, `label`, `value`, `name`, `filter`, `is_default`, `weight`, `description`, `is_optgroup`, `is_reserved`, `is_active` )
  VALUES ($newOptionGroupId, '$5k+', 5, '$5m+',  0,  0, 5, '', 0, 0, 1)");
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_value ( `option_group_id`, `label`, `value`, `name`, `filter`, `is_default`, `weight`, `description`, `is_optgroup`, `is_reserved`, `is_active` )
  VALUES ($newOptionGroupId, '$1k+', 6, '$5m+',  0,  0, 6, '', 0, 0, 1)");
          CRM_Core_DAO::executeQuery( "UPDATE civicrm_custom_field SET option_group_id=$newOptionGroupId, html_type='Select' WHERE name = 'Endowment_Level'" );
        }
      }
    return TRUE;
  }

  /**
   * @return bool
   * remove the trailing number from email
   */
  public function upgrade_4219(): bool {
    // replace c0m to com
    CRM_Core_DAO::executeQuery( "update civicrm_email set email = replace(email,'c0m' , 'com'), on_hold=0, hold_date=NULL where email like ('%@%.c0m')" );
    // remove trailing number from domain if email contains '@'
    CRM_Core_DAO::executeQuery( "update civicrm_email set email = left( email, length(email) - length(REGEXP_SUBSTR(reverse(email),'[0-9]+'))), on_hold=0, hold_date=NULL where SUBSTRING(email, length(email), 1) REGEXP '[0-9]' and email LIKE '%@%.%'" );
    return TRUE;
  }
}
