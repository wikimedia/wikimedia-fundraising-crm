<?php /** @noinspection PhpUnused */

use Civi\Api4\ContributionRecur;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\ExchangeRate;
use Civi\Api4\OptionGroup;
use Civi\Api4\OptionValue;
use Civi\Api4\WMFConfig;
use Civi\QueueHelper;
use Civi\WMFHook\CalculatedData;
use CRM_Wmf_ExtensionUtil as E;
use League\Csv\Reader;

/**
 * Collection of upgrade steps.
 */
class CRM_Wmf_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * @throws \CRM_Core_Exception
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

    // Our name formatter likes to go dotless. Add this here so
    // it runs for dev installs but not again on prod
    OptionValue::update(FALSE)
      ->addWhere('option_group_id:name', '=', 'individual_suffix')
      ->addWhere('name', '=', 'Jr.')
      ->addValue('label', 'Jr')
      ->addValue('name', 'Jr')
      ->execute();

    // Bug: T115044 Add index to nick_name column as we have decided to use it for Benevity imports.
    CRM_Core_BAO_SchemaHandler::createIndexes(['civicrm_contact' => ['nick_name']]);

    // Bug: T228106 Add index to civicrm_activity.location.
    CRM_Core_BAO_SchemaHandler::createIndexes(['civicrm_activity' => ['location']]);

    /**
     * Add index to civicrm_country.iso_code.
     *
     * To hone the silverpop queries we really want to join on this so we need an index.
     *
     * Bug: T253152
     */
    $tables = ['civicrm_country' => ['iso_code']];
    CRM_Core_BAO_SchemaHandler::createIndexes($tables);
    $this->executeSqlFile('sql/create_smashpig_pending_table.sql');
  }

  /**
   * Create WMF specific custom fields.
   *
   * @throws \CRM_Core_Exception
   */
  public function addCustomFields(): void {
    WMFConfig::syncCustomFields(FALSE)->execute();
  }

  /**
   * Create WMF specific custom fields.
   *
   * @throws \CRM_Core_Exception
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
   *
   * In our case we should be confident the wmf donor table exists before adding
   * an index.
   */
  public function postInstall(): void {
    $this->syncGeocoders();
    /* Add combined index on entity_id and lifetime_usd_total on wmf_donor table.
     *
     * In testing this made a significant difference when filtering for donors with
     * giving over x - which is a common usage.
     *
    */
    CRM_Core_DAO::executeQuery('ALTER TABLE wmf_donor ADD INDEX entity_total (entity_id, lifetime_usd_total)');
    CRM_Core_BAO_SchemaHandler::safeRemoveFK('civicrm_activity', 'FK_civicrm_activity_original_id');
    $this->upgrade_4640();
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   */
  // public function uninstall() {
  //  $this->executeSqlFile('sql/my-uninstall.sql');
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
   * @noinspection SqlWithoutWhere
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
   *
   * @noinspection SqlWithoutWhere
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
   * them all out & we should hopefully be done with this data snafu.
   * Bug: T283798
   *
   * @return TRUE on success
   * @noinspection SqlWithoutWhere
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
    $actions = [];
    foreach ($oldIndexes as $index) {
      if (CRM_Core_BAO_SchemaHandler::checkIfIndexExists($table, $index)) {
        $actions[] = ' DROP INDEX ' . $index;
      }
      if (CRM_Core_BAO_SchemaHandler::checkIfIndexExists($table, 'index_' . $index)) {
        $actions[] = ' DROP INDEX index_' . $index;
      }
    }
    CRM_Core_DAO::executeQuery('ALTER TABLE ' . $table . ' ' . implode(',', $actions));
  }

  /**
   * Fill new wmf_donor fields.
   *
   * Bug: T288721
   *
   * @return TRUE on success
   * @noinspection SqlWithoutWhere
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
   * Run sql to reset the accidentally cancelled ideal recurring contributions during the token update when switching
   * from Adyen to Adyen Checkout
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
    if (!$oldOptionGroupId) {
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_option_group ( `name`, `title`, `is_active` ) VALUES ('Benefactor_Page_Listing_Endowment_Level', 'Benefactor Page Listing :: Endowment Level', 1)");
      $newOptionGroupId = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_option_group WHERE name = 'Benefactor_Page_Listing_Endowment_Level'");
      if ($newOptionGroupId) {
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
        CRM_Core_DAO::executeQuery("UPDATE civicrm_custom_field SET option_group_id=$newOptionGroupId, html_type='Select' WHERE name = 'Endowment_Level'");
      }
    }
    return TRUE;
  }

  /**
   * @return true
   * remove the trailing number from email
   */
  public function upgrade_4219(): bool {
    // replace c0m to com
    CRM_Core_DAO::executeQuery("update civicrm_email set email = replace(email,'c0m' , 'com'), on_hold=0, hold_date=NULL where email like ('%@%.c0m')");
    // remove trailing number from domain if email contains '@'
    CRM_Core_DAO::executeQuery("update civicrm_email set email = left( email, length(email) - length(REGEXP_SUBSTR(reverse(email),'[0-9]+'))), on_hold=0, hold_date=NULL where SUBSTRING(email, length(email), 1) REGEXP '[0-9]' and email LIKE '%@%.%'");
    return TRUE;
  }

  /**
   * Remove unused option values.
   *
   * @return true
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4220(): bool {
    $optionGroups = OptionGroup::get(FALSE)
      ->addWhere('name', 'IN', [
        'individual_prefix',
        'individual_suffix',
        'languages',
      ])
      ->addSelect('id', 'name')->execute()->indexBy('name');

    $prefixOptions = $this->getInUseOptions('prefix_id');
    CRM_Core_DAO::executeQuery('
      DELETE FROM civicrm_option_value
      WHERE option_group_id = ' . $optionGroups['individual_prefix']['id'] . '
        AND value NOT IN (' . implode(',', $prefixOptions) . ')');

    $suffixOptions = $this->getInUseOptions('suffix_id');
    CRM_Core_DAO::executeQuery('
      DELETE FROM civicrm_option_value
      WHERE option_group_id = ' . $optionGroups['individual_suffix']['id'] . '
        AND value NOT IN (' . implode(',', $suffixOptions) . ')');

    $languages = $this->getInUseOptions('preferred_language');
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_option_value
      WHERE option_group_id = {$optionGroups['languages']['id']}
        -- extra cautionary check - only ones with cruft-for-labels
        AND label = name
        AND name NOT IN ('" . implode("', '", $languages) . "')");

    return TRUE;
  }

  /**
   * Remove unused option values.
   *
   * @return true
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4222(): bool {
    OptionValue::update(FALSE)
      ->addWhere('option_group_id.name', '=', 'email_greeting')
      ->addWhere('is_default', '=', TRUE)
      ->addWhere('filter', '=', 1)
      ->setValues([
        'label' => "Dear {if {contact.first_name|boolean} && {contact.last_name|boolean}}{contact.first_name}{else}donor{/if}",
        // Only the label really seems to matter but both feels more
        // consistent with the others.
        'name' => "Dear {if {contact.first_name|boolean} && {contact.last_name|boolean}}{contact.first_name}{else}donor{/if}",
      ])
      ->execute();
    return TRUE;
  }

  /**
   * Remove unused option values.
   *
   * @return true
   */
  public function upgrade_4230(): bool {
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_address
      SET street_address = NULL
      WHERE street_address IN
      ('" . implode("','", $this->getBadStrings()) . "')"
    );
    return TRUE;
  }

  /**
   * Upgrade 4240 - Re-add new contribution tracking table, with minor tweaks.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4246(): bool {
    $this->ctx->log->info('Re-Add the contribution tracking table.');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_contribution_tracking');
    if (!CRM_Core_DAO::singleValueQuery("SHOW TABLES LIKE 'civicrm_contribution_tracking'")) {
      $this->executeSqlFile('sql/auto_install.sql');
    }
    return TRUE;
  }

  /**
   * Update addresses whose geocoding has gotten out of sync over the years.
   *
   * Bug: T334152
   *
   * On staging this was not insanely slow but we should probably turn
   * off queues to run.
   *
   * Query OK, 1102514 rows affected (1 min 24.345 sec)
   * Rows matched: 1102514  Changed: 1102514  Warnings: 0
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4250(): bool {
    CRM_Core_DAO::executeQuery('
      UPDATE civicrm_address a
      LEFT JOIN civicrm_geocoder_zip_dataset z
        ON z.postal_code = a.postal_code
      SET a.geo_code_1 = latitude,  a.geo_code_2 = longitude
      WHERE country_id = 1228 AND a.postal_code IS NOT NULL
        AND (z.latitude <> a.geo_code_1 OR z.longitude <> a.geo_code_2)
    ');
    return TRUE;
  }

  /**
   * Force dlocal trxn_id back to normal case instead of upper
   * for both civicrm_contribution trxn_id and wmf_contribution_extra gateway_txn_id
   * (see T335057)
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4251(): bool {
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_contribution
       SET trxn_id=CONCAT(UPPER(SUBSTRING(trxn_id,1,POSITION('-' IN trxn_id))),LOWER(SUBSTRING(trxn_id,POSITION('-' IN trxn_id)+1)))
       WHERE trxn_id like '%DLOCAL%';"
    );
    CRM_Core_DAO::executeQuery(
      "UPDATE wmf_contribution_extra
       SET gateway_txn_id=CONCAT(UPPER(SUBSTRING(gateway_txn_id,1,1)),LOWER(SUBSTRING(gateway_txn_id,2)))
       WHERE gateway = 'dlocal';"
    );
    return TRUE;
  }

  /**
   * Set duplicate email thank you dates from accidental audit import. This will stop us from sending donors a
   * second thank you email.
   *
   * Bug:T324347
   *
   * There should be 3301 that did not get the second thank you email
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4253(): bool {
    CRM_Core_DAO::executeQuery('
      UPDATE civicrm_contribution
      SET thankyou_date="2023-04-22"
      WHERE trxn_id LIKE "DLOCAL 3%"
        AND thankyou_date IS NULL
        AND receive_date > "2023-04-20"
        AND receive_date < "2023-04-24"
    ');
    return TRUE;
  }

  /**
   * Restore some values that Rosie inadvertently deleted when she deleted the wrong option value.
   *
   * See https://phabricator.wikimedia.org/T337051#8915540
   *
   * Bug: T337051
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4260(): bool {
    CRM_Core_DAO::executeQuery("CREATE TEMPORARY TABLE rosie
     Select entity_id as contribution_id
      FROM log_civicrm_value_1_gift_data_7
     LEFT JOIN civicrm_contribution c ON c.id = entity_id
     WHERE log_conn_id ='6454c2d96e481JbZa'");
    CRM_Core_DAO::executeQuery('ALTER TABLE rosie ADD INDEX(contribution_id)');
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_value_1_gift_data_7
      INNER JOIN rosie ON entity_id = contribution_id
      SET appeal = 'Annual Fund Appeal 2022 - Mailing'
    ");
    return TRUE;
  }

  /**
   * T336891 Data Axle project - List pull from Civi.
   *
   * Segment 1 (GROUP ID: 1685 Gift of $50+ cumulatively in the last 5 years (Jan 1, 2018 - today)
   * Segment 6 (GROUP ID: 1686) Given at least $20+ each year for the last 3 years
   */
  public function upgrade_4284(): bool {
    // Segment 1 (GROUP ID: 1685 Gift of $50+ cumulatively in the last 5 years (Jan 1, 2018 - today)
    $segmentOneGroupId = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_group WHERE title = 'Data Axle $50+ cumulative gift since 2018 T336891'");
    // below for local test, otherwise civicrm production already have this id as 1685 created
    if (!$segmentOneGroupId) {
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_group (`name`, `title`, `description`, `is_active`, `visibility`, `group_type`) VALUES ('Data_Axle_50_cumulative_gift_si_1685' ,'Data Axle $50+ cumulative gift since 2018 T336891', 'Gift of $50+ cumulatively in the last 5 years (Jan 1, 2018 - today)', 1, 'User and User Admin Only', '1')");
      $segmentOneGroupId = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_group WHERE title = 'Data Axle $50+ cumulative gift since 2018 T336891'");
    }
    $segmentOneContacts = CRM_Core_DAO::executeQuery(
      "SELECT a.id FROM civicrm_contact a JOIN (SELECT contact_id FROM civicrm_contribution WHERE receive_date > '2018-01-01' GROUP BY contact_id HAVING sum(total_amount) >= 50) b ON a.id = b.contact_id
    JOIN civicrm_address ca ON a.id = ca.id AND ca.is_primary = 1
    AND ca.country_id = 1228 AND ca.street_address <> ''
    WHERE a.contact_type = 'Individual' AND a.is_deceased = 0 AND a.is_deleted = 0
    AND a.is_opt_out = 0;");
    $segmentOneContactIds = [];
    while ($segmentOneContacts->fetch()) {
      if (isset($segmentOneContacts->id)) {
        $segmentOneContactIds[] = $segmentOneContacts->id;
      }
    }
    $this->insertContacts($segmentOneContactIds, $segmentOneGroupId);

    // Segment 6 (GROUP ID: 1686) Given at least $20+ each year for the last 3 years
    $segmentSixGroupId = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_group WHERE title = 'Data Axle $20+ each year since 2020 T336891'");
    // below for local test, otherwise civicrm production already have this id as 1686 created
    if (!$segmentSixGroupId) {
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_group (`name`, `title`, `description`, `is_active`, `visibility`, `group_type`) VALUES ('Data_Axle_20_each_year_since_20_1686', 'Data Axle $20+ each year since 2020 T336891', 'Given at least $20+ each year for the last 3 years', 1, 'User and User Admin Only', '1')");
      $segmentSixGroupId = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_group WHERE title = 'Data Axle $20+ each year since 2020 T336891'");
    }
    $segmentSixContacts = CRM_Core_DAO::executeQuery(
      "SELECT a.id FROM civicrm_contact a JOIN (SELECT contact_id as contact2223, sum(total_amount) AS amount2223 FROM civicrm_contribution WHERE receive_date BETWEEN '2022-06-08' AND '2023-06-07' GROUP BY contact_id HAVING amount2223 >= 20) b ON b.contact2223 = a.id
    JOIN (SELECT contact_id AS contact2122, sum(total_amount) AS amount2122 FROM civicrm_contribution WHERE receive_date BETWEEN '2021-06-08' AND '2022-06-07' GROUP BY contact_id HAVING amount2122 >= 20) c ON c.contact2122 = a.id
    JOIN (SELECT contact_id AS contact2021, sum(total_amount) AS amount2021 FROM civicrm_contribution WHERE receive_date BETWEEN '2020-06-08' AND '2021-06-07' GROUP BY contact_id HAVING amount2021 >= 20) d ON d.contact2021 = a.id
    JOIN civicrm_address ca ON a.id = ca.id AND ca.is_primary = 1
    AND ca.country_id = 1228 AND ca.street_address <> ''
    WHERE a.contact_type = 'Individual' AND a.is_deceased = 0 AND a.is_deleted = 0
    AND a.is_opt_out = 0");
    $segmentSixContactIds = [];
    while ($segmentSixContacts->fetch()) {
      if (isset($segmentSixContacts->id)) {
        $segmentSixContactIds[] = $segmentSixContacts->id;
      }
    }
    $this->insertContacts($segmentSixContactIds, $segmentSixGroupId);
    return TRUE;
  }

  /**
   * T336891 Data Axle project
   *
   * NEW Segment 1 (GROUP ID: 1685 Gift at least $50+ each year in the last 5 years (Jan 1, 2018 - today)
   *
   * @return bool
   */
  public function upgrade_4286(): bool {
    // NEW Segment 1 (GROUP ID: 1685 Gift at least $50+ each year in the last 5 years (Jan 1, 2018 - today)
    $segmentOneGroupId = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_group WHERE title = 'Data Axle $50+ each year since 2018 T336891'");
    // below for local test, otherwise civicrm production already have this id as 1685 created
    if (!$segmentOneGroupId) {
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_group (`name`, `title`, `description`, `is_active`, `visibility`, `group_type`) VALUES ('Data_Axle_50_each_year_since_20_1703' ,'Data Axle $50+ each year since 2018 T336891', 'Given at least $50+ each year for the last 5 years (Jan 1, 2018 - today)', 1, 'User and User Admin Only', '1')");
      $segmentOneGroupId = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_group WHERE title = 'Data Axle $50+ each year since 2018 T336891'");
    }
    // remove
    $segmentOneContacts = CRM_Core_DAO::executeQuery(
      "SELECT a.id FROM civicrm_contact a JOIN (SELECT contact_id as contact2223, sum(total_amount) AS amount2223 FROM civicrm_contribution WHERE receive_date BETWEEN '2022-06-13' AND '2023-06-12' GROUP BY contact_id HAVING amount2223 >= 50) b ON b.contact2223 = a.id
    JOIN (SELECT contact_id AS contact2122, sum(total_amount) AS amount2122 FROM civicrm_contribution WHERE receive_date BETWEEN '2021-06-13' AND '2022-06-12' GROUP BY contact_id HAVING amount2122 >= 50) c ON c.contact2122 = a.id
    JOIN (SELECT contact_id AS contact2021, sum(total_amount) AS amount2021 FROM civicrm_contribution WHERE receive_date BETWEEN '2020-06-13' AND '2021-06-12' GROUP BY contact_id HAVING amount2021 >= 50) d ON d.contact2021 = a.id
    JOIN (SELECT contact_id AS contact1920, sum(total_amount) AS amount1920 FROM civicrm_contribution WHERE receive_date BETWEEN '2019-06-13' AND '2020-06-12' GROUP BY contact_id HAVING amount1920 >= 50) e ON e.contact1920 = a.id
    JOIN (SELECT contact_id AS contact1819, sum(total_amount) AS amount1819 FROM civicrm_contribution WHERE receive_date BETWEEN '2018-06-13' AND '2019-06-12' GROUP BY contact_id HAVING amount1819 >= 50) f ON f.contact1819 = a.id
    JOIN civicrm_address ca ON a.id = ca.id AND ca.is_primary = 1
    AND ca.country_id = 1228 AND ca.street_address <> ''
    WHERE a.contact_type = 'Individual' AND a.is_deceased = 0 AND a.is_deleted = 0
    AND a.is_opt_out = 0");
    $segmentOneContactIds = [];
    while ($segmentOneContacts->fetch()) {
      if (isset($segmentOneContacts->id)) {
        $segmentOneContactIds[] = $segmentOneContacts->id;
      }
    }
    $this->insertContacts($segmentOneContactIds, $segmentOneGroupId);
    return TRUE;
  }

  /**
   * Add Contacts to Group
   *
   * @param array $contacts
   * @param int $groupId
   */
  protected function insertContacts(array $contacts, int $groupId): void {
    if (count($contacts) > 0) {
      $sql = 'INSERT INTO civicrm_group_contact (`group_id`, `contact_id`, `status`) VALUES ';
      foreach ($contacts as $contact_id) {
        $sql .= '(' . $groupId . ',' . $contact_id . ', "Added"),';
      }
      $sql = substr_replace($sql, ';', -1);
      CRM_Core_DAO::executeQuery($sql);
    }
  }

  /**
   * Remove indexes from WMF Donor fields to permit adding new ones...
   *
   * Bug: T331919
   *
   * @return bool
   */
  public function upgrade_4300(): bool {
    $this->dropIndexes([
      'total_2015_2016',
      'total_2016',
      'total_2016_2017',
      'total_2017',
      'total_2017_2018',
      'total_2018',
      'total_2018_2019',
      'total_2019',
      'total_2019_2020',
      'total_2020',
      'total_2021',
      'total_2022',
      'all_funds_change_2018_2019',
      'all_funds_change_2019_2020',
      'all_funds_total_2018_2019',
      'all_funds_total_2019_2020',
      'endowment_total_2018',
      'endowment_total_2018_2019',
      'endowment_total_2019',
      'endowment_total_2019_2020',
      'endowment_total_2020',
      'endowment_total_2021',
      'endowment_total_2022',
    ], 'wmf_donor');
    return TRUE;
  }

  /**
   * Add new segment & wmf donor fields
   *
   * Bug: T331919 & T339067
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4305(): bool {
    $this->addCustomFields();
    return TRUE;
  }

  /**
   * Add new segment & status labels
   *
   * Bug: T339067
   *
   * Add the missing labels for segment fields.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4310(): bool {
    $fieldFinder = new CalculatedData();
    $fields = $fieldFinder->getWMFDonorFields();
    foreach (['donor_segment_id', 'donor_status_id'] as $fieldName) {
      $optionGroupID = CustomField::get(FALSE)
        ->addWhere('name', '=', $fieldName)
        ->addSelect('option_group_id')
        ->execute()->first()['option_group_id'];
      foreach ($fields[$fieldName]['option_values'] as $values) {
        $values['option_group_id'] = $optionGroupID;
        OptionValue::create(FALSE)
          ->setValues($values)->execute();
      }
    }
    return TRUE;
  }

  /**
   * Get the values actually used for the option.
   *
   * @param string $field
   *
   * @return array
   */
  private function getInUseOptions(string $field): array {
    $dbResult = CRM_Core_DAO::executeQuery("
      SELECT distinct $field as option_field FROM civicrm_contact
      WHERE $field IS NOT NULL
    ");
    $usedOptions = [];
    while ($dbResult->fetch()) {
      $usedOptions[] = $dbResult->option_field;
    }
    return $usedOptions;
  }

  /**
   * Use default in tokens rather than Smarty to prevent bus errors.
   *
   * Token defaults were added in our last upgrade or two.
   *
   * This is less nuanced than our earlier 'if first & last name, use first name'
   * but it allows us to use the templating system & skip calling smarty.
   *
   * The bus errors are coming from Smarty - although I think upgrading to smarty
   * 3 would likely address.
   *
   * @return true
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4320(): bool {
    OptionValue::update(FALSE)
      ->addWhere('option_group_id.name', '=', 'email_greeting')
      ->addWhere('is_default', '=', TRUE)
      ->addWhere('filter', '=', 1)
      ->setValues([
        'label' => 'Dear {contact.first_name|default:"donor"}',
        // Only the label really seems to matter but both feels more
        // consistent with the others.
        'name' => 'Dear {contact.first_name|default:"donor"}',
      ])
      ->execute();
    return TRUE;
  }

  /**
   * Get strings that can be considered non-data in street address.
   *
   * @return string[]
   */
  protected function getBadStrings(): array {
    return [
      'n/a',
      '123 Fake Street',
      'A',
      'Anonymous',
      'C',
      'X',
      'NA',
      'xxx',
      'z',
      '1',
      '3',
      'Q',
      'r',
      'aa',
      'k',
      'asd',
      'w',
    ];
  }

  /**
   * Queue updates to change tax amount to 0 where it is NULL.
   *
   * This is an upgrade that we skipped during our last update to run during maintenance.
   *
   * However, the main point of this it to establish a method of queuing sql updates
   * with the actual update being trivial.
   *
   * Note that the helper class is likely to be incorporated into core in
   * some form in future - but we can proceed with our flavour until that
   * is worked through.
   *
   * For local dev testing run `UPDATE civicrm_contribution SET tax_amount = NULL`.
   * before starting... Depending on the state of your database you may need to first run
   * `alter table civicrm_contribution modify total_amount decimal(20,2) not null`
   *
   * @return bool
   */
  public function upgrade_4325(): bool {
    $sql = 'UPDATE civicrm_contribution SET tax_amount = 0
      WHERE tax_amount IS NULL
      -- limit to 10k records for now as we actually want to deploy this live in a measured fashion
      AND id < 10000 LIMIT 10';

    $this->queueSQL($sql);
    return TRUE;
  }

  /***
   * Update recurring contributions financial type & campaign.
   *
   * https://phabricator.wikimedia.org/T344012
   *
   * - financial type = Recurring Gift (31)
   * - campaign = OnlineGift
   *
   * Note we join onto civicrm_contribution with recurring in the same
   * series with a lower receive data to find the first in the series
   * (ie there are no entries in the joined table).
   *
   * Then we do a similar join to find those that DO have earlier gifts.
   *
   * Note that these are kinda crazy without the group by but the batch size is
   * so small that hopefully it's worth running lots of small batches over
   * an expensive distinct.
   *
   * @return bool
   */
  public function upgrade_4330(): bool {
    $recurringGiftTypeID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Recurring Gift');
    $recurringCashTypeID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Recurring Gift - Cash');
    // To see what is found swap the first part with
    // SELECT a.id as `id`, a.contribution_recur_id, a.receive_date, a.contact_id FROM
    $sql = '
     UPDATE
     civicrm_contribution a
       -- join to previous contributions with the same contribution recur id which happened before the given one
       LEFT JOIN civicrm_contribution c2 ON c2.contribution_recur_id = a.contribution_recur_id AND c2.receive_date < a.receive_date
       LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
       ON a.id = Gift_Data.entity_id
     SET a.financial_type_id = ' . $recurringGiftTypeID . ',
         Gift_Data.campaign = "Online Gift"
     WHERE `a`.`receive_date` > "20230701000000"
       AND `a`.`contribution_recur_id` > 0
       -- financial type not already of recurring type
       AND `a`.`financial_type_id` NOT IN(' . $recurringGiftTypeID . ')
       AND `a`.`is_test` = 0
       AND c2.id IS NULL
        LIMIT 5000';

    $this->queueSQL($sql);

    $sql = '
     UPDATE
     civicrm_contribution a
       -- join to previous contributions with the same contribution recur id which happened before the given one
       LEFT JOIN civicrm_contribution c2 ON c2.contribution_recur_id = a.contribution_recur_id
       AND c2.receive_date < a.receive_date
       LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
       ON a.id = Gift_Data.entity_id
     SET a.financial_type_id = ' . $recurringCashTypeID . ',
         Gift_Data.campaign = "Online Gift"
     WHERE `a`.`receive_date` > "20230701000000"
       AND `a`.`contribution_recur_id` > 0
       -- financial type not already of recurring type
       AND `a`.`financial_type_id` NOT IN(' . $recurringCashTypeID . ')
       AND `a`.`is_test` = 0
       AND c2.id IS NOT NULL
        LIMIT 5000';
    $this->queueSQL($sql);
    return TRUE;
  }

  /**
   * Update all places where gift source is community gift or benefactor gift.
   *
   * The goal is that from 1 July these should all be online gift.
   *
   * T344012
   *
   * @return bool
   */
  public function upgrade_4335(): bool {
    $sql = "
     UPDATE
     civicrm_contribution a
       LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
       ON a.id = Gift_Data.entity_id
     SET Gift_Data.campaign = 'Online Gift'
     WHERE
       (Gift_Data.campaign IN ('Community Gift', 'Benefactor Gift') OR Gift_Data.campaign IS NULL)
       AND `a`.`receive_date` > '20230701000000'
     LIMIT 1000";
    $this->queueSQL($sql);
    return TRUE;
  }

  /**
   * Ensure gift source (campaign) is broadly set to online gift.
   *
   * We are always setting it for recurring & hopefully all online
   * contributions now but the above only did updates, not inserts.
   *
   * - sql to track gift sources:
   * SELECT campaign, count(*),contact_id FROM civicrm_contribution a
   * LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
   * ON a.id = Gift_Data.entity_id
   * WHERE `a`.`receive_date` > "20230701000000" GROUP BY campaign;
   *
   * Since production has default values for 3 fields in the GiftData
   * set I have set the other 2 fields to their default values.
   *
   * The field default for gift source/ campaign is Individual Gift.
   * Presumably anyone manually entering gifts saves this & the rest
   * should be Online Gift. However, I did a check on payment instruments
   * and have excluded 'Cash', 'Check' and 'Stock' from the update.
   *
   * SELECT value,name,label  FROM civicrm_option_value
   * WHERE option_group_id = 10
   *   AND value IN (
   *     SELECT payment_instrument_id FROM civicrm_contribution a
   *       LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data ON a.id = Gift_Data.entity_id
   *     WHERE `a`.`receive_date` > '20230701000000' AND campaign_id IS NULL
   *     GROUP BY payment_instrument_id)
   *
   * +-------+--------------------------------+--------------------------------+
   * | value | name                           | label                          |
   * +-------+--------------------------------+--------------------------------+
   * | 1     | Credit Card                    | Credit Card                    |
   * | 3     | Cash                           | Cash                           |
   * | 4     | Check                          | Check                          |
   * | 5     | EFT                            | EFT                            |
   * | 9     | iDeal                          | iDeal                          |
   * | 14    | Bank Transfer                  | Bank Transfer                  |
   * | 15    | Credit Card: Visa              | Credit Card: Visa              |
   * | 16    | Credit Card: MasterCard        | Credit Card: MasterCard        |
   * | 17    | Credit Card: American Express  | Credit Card: American Express  |
   * | 21    | Credit Card: JCB               | Credit Card: JCB               |
   * | 22    | Credit Card: Discover          | Credit Card: Discover          |
   * | 23    | Credit Card: Carte Bleue       | Credit Card: Carte Bleue       |
   * | 25    | Paypal                         | Paypal                         |
   * | 31    | Boleto                         | Boleto                         |
   * | 188   | Stock                          | Stock                          |
   * | 189   | Amazon                         | Amazon                         |
   * | 193   | Citibank International         | Citibank International         |
   * | 195   | Credit Card: Visa Electron     | Credit Card: Visa Electron     |
   * | 196   | Credit Card: Visa Debit        | Credit Card: Visa Debit        |
   * | 197   | Credit Card: MasterCard Debit  | Credit Card: MasterCard Debit  |
   * | 198   | Credit Card: Diners            | Credit Card: Diners            |
   * | 205   | Credit Card: Elo               | Credit Card: Elo               |
   * | 216   | Bancomer                       | Bancomer                       |
   * | 219   | OXXO                           | OXXO                           |
   * | 223   | Rapi Pago                      | Rapi Pago                      |
   * | 224   | Santander                      | Santander                      |
   * | 235   | Bank Transfer: Netbanking      | Bank Transfer: Netbanking      |
   * | 236   | Bank Transfer: PayTM Wallet    | Bank Transfer: PayTM Wallet    |
   * | 237   | Credit Card: RuPay             | Credit Card: RuPay             |
   * | 238   | Bank Transfer: UPI             | Bank Transfer: UPI             |
   * | 239   | Money Order                    | Money Order                    |
   * | 240   | Apple Pay                      | Apple Pay                      |
   * | 241   | Abitab                         | Abitab                         |
   * | 242   | Pago Efectivo                  | Pago Efectivo                  |
   * | 243   | Google Pay                     | Google Pay                     |
   * | 248   | Bank Transfer: Banco do Brasil | Bank Transfer: Banco do Brasil |
   * | 251   | Bank Transfer: Bradesco        | Bank Transfer: Bradesco        |
   * | 253   | Bank Transfer: Itau            | Bank Transfer: Itau            |
   * | 257   | Bank Transfer: PSE             | Bank Transfer: PSE             |
   * | 258   | Bank Transfer: Santander       | Bank Transfer: Santander       |
   * | 260   | Apple Pay: Visa                | Apple Pay: Visa                |
   * | 261   | Apple Pay: American Express    | Apple Pay: American Express    |
   * | 263   | Apple Pay: Carte Bleue         | Apple Pay: Carte Bleue         |
   * | 264   | Apple Pay: Discover            | Apple Pay: Discover            |
   * | 266   | Apple Pay: JCB                 | Apple Pay: JCB                 |
   * | 267   | Apple Pay: MasterCard          | Apple Pay: MasterCard          |
   * | 268   | Google Pay: American Express   | Google Pay: American Express   |
   * | 269   | Google Pay: Discover           | Google Pay: Discover           |
   * | 272   | Google Pay: MasterCard         | Google Pay: MasterCard         |
   * | 273   | Google Pay: Visa               | Google Pay: Visa               |
   * | 274   | Venmo                          | Venmo                          |
   * +-------+--------------------------------+--------------------------------+
   *
   * T344012
   *
   * @return bool
   */
  public function upgrade_4340(): bool {
    $sql = "
     INSERT INTO
      `civicrm_value_1_gift_data_7` (entity_id, fund, campaign, appeal)
      SELECT a.id, 'Unrestricted - General', 'Online Gift', 'spontaneousdonation'
       FROM civicrm_contribution a
       LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
       ON a.id = Gift_Data.entity_id
       WHERE Gift_Data.id IS NULL
       AND `a`.`receive_date` > '20230701000000'
       AND a.payment_instrument_id NOT IN (
         -- cash
         3,
         -- check
         4,
         -- stock
         188
       )
       LIMIT 1000";
    $this->queueSQL($sql);
    return TRUE;
  }

  /**
   * Let's bring our mailing id  & mailing_job.id for thank you back to 1 for simplicity.
   *
   * We need to do these steps with the queue off & then can continue on
   * & new ones should be attached to these, allowing us to move the existing
   * records over via co-worker.
   *
   * Bug: T346194
   *
   * @return bool
   */
  public function upgrade_4350(): bool {
    // First change the name of the mailing that is operating as our main mailing to something pattern-similar to the others.
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailing SET NAME = "thank_you|thank_you.en.html|123456" WHERE id = 102297');
    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_mailing
      (id, domain_id, name, subject, body_html, is_completed, scheduled_id, visibility, email_selection_method, template_type)
      VALUES(1, 1, 'thank_you', 'Thank you for your gift', 'WMF Thank you message', 1, 1, 'User and User Admin Only', 'automatic',  'traditional')");
    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_mailing_job (id, mailing_id, start_date, end_date, status)
    -- start date & end date are dummy fields here.
    VALUES(1, 1, NOW(), NOW(),'complete')");
    return TRUE;
  }

  /**
   * Update mailing job records to link to the same queue.
   *
   * Note this just brings across the one update we already started into
   * this upgrader. The next goal is to figure out all the records to
   * consolidate.
   *
   * Bug: T346194
   *
   * @return bool
   */
  public function upgrade_4355(): bool {
    $sql = 'UPDATE  civicrm_mailing_event_queue queue
INNER JOIN civicrm_mailing_job j ON j.id = queue.job_id
  SET job_id = 1
WHERE j.mailing_id = 373 AND job_id <> 1
LIMIT 2000';
    $this->queueSQL($sql);
    return TRUE;
  }

  /**
   * Fix the field default for the campaign field.
   *
   * Note this was basically instant on staging.
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4360(): bool {
    CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_value_1_gift_data_7 MODIFY COLUMN campaign varchar(255) DEFAULT 'Online Gift'");
    // And re-run this update to get any that are left.
    return $this->upgrade_4335();
  }

  /**
   * Fix mis-coded subsequent recurring contributions.
   *
   * Our original fix for the recurring contributions was mis-coding
   * some subsequent contributions to 'Recurring Gift' (31) rather
   * than 'Recurring Gift - Cash' (32).
   *
   * This is hopefully fixed as of
   * https://gerrit.wikimedia.org/r/c/wikimedia/fundraising/crm/+/963428/
   * but we need to fix the mis-coded ones. This just re-runs the earlier update
   * - which has been broadened to only treat the 'right' financial type as 'done'
   * in the where clause (it was previously excluding those with type 31 OR 32
   * regardless of which was correct in the instance).
   *
   * Note running this will add the new tasks into the queue. The old ones will
   * continue to take turns with them until done. We could kill the one ones in
   * favour of the new - but the old ones are still doing updates towards
   * the general goal each time they run so hey.
   *
   * @return bool
   */
  public function upgrade_4370(): bool {
    return $this->upgrade_4330();
  }

  /**
   * Queue updates to change tax amount to 0 where it is NULL.
   *
   * As with the previous tax_amount update update this is primary for local dev
   * testing purposes - ie it is easier to test the iteration mechanism on this locally
   * than it is for the next queued update.
   *
   * For local dev testing run `UPDATE civicrm_contribution SET tax_amount = NULL`.
   * before starting... Depending on the state of your database you may need to first run
   * `alter table civicrm_contribution modify total_amount decimal(20,2) not null`
   *
   * @return bool
   */
  public function upgrade_4375(): bool {
    $sql = 'UPDATE civicrm_contribution SET tax_amount = 0
      WHERE tax_amount IS NULL
      -- limit to the next 10k records for now as we actually want to deploy this live in a measured fashion
      AND id BETWEEN %1 AND %2';

    $this->queueSQL($sql, [
      1 => [
        'value' => 0,
        'type' => 'Integer',
        'increment' => 5,
      ],
      2 => [
        'value' => 5,
        'type' => 'Integer',
        'increment' => 5,
      ],
    ],
      [
        'sql_returns_none' => '
        SELECT id FROM civicrm_contribution
      WHERE tax_amount IS NULL AND id < 11000 LIMIT 1',
      ]);
    return TRUE;
  }

  /**
   * Update mailing job records to link to the same queue.
   *
   * Note this just brings across the one update we already started into
   * this upgrader. The next goal is to figure out all the records to
   * consolidate.
   *
   * Bug: T346194
   *
   * @return bool
   */
  public function upgrade_4380(): bool {
    $sql = 'UPDATE  civicrm_mailing_event_queue queue
INNER JOIN civicrm_mailing_job j ON j.id = queue.job_id
INNER JOIN civicrm_mailing m ON j.mailing_id = m.id
  SET job_id = 1
WHERE m.name LIKE "thank_you|thank_you%" AND job_id <> 1
  -- this job _id seems to help the query speed.
  -- some runs might be have less than 2k in them
AND queue.id BETWEEN %1 AND %2';
    $this->queueSQL($sql, [
      1 => [
        'value' => 0,
        'type' => 'Integer',
        'increment' => 2000,
      ],
      2 => [
        'value' => 2000,
        'type' => 'Integer',
        'increment' => 2000,
      ],
    ],
      [
        'sql_returns_none' => 'SELECT queue.id FROM civicrm_mailing_event_queue queue
INNER JOIN civicrm_mailing_job j ON j.id = queue.job_id
INNER JOIN civicrm_mailing m ON j.mailing_id = m.id
WHERE m.name LIKE "thank_you|thank_you%" AND job_id <> 1 LIMIT 1',
      ], 2);
    return TRUE;
  }

  /**
   * Delete now-orphaned records in civicrm_mailing.
   *
   * @return bool
   */
  public function upgrade_4385(): bool {
    $sql = 'DELETE j
      FROM civicrm_mailing_job j
      LEFT JOIN civicrm_mailing_event_queue q ON q.job_id = j.id
      WHERE q.id IS NULL
        AND j.id BETWEEN %1 AND %2';
    $this->queueSQL($sql, [
      1 => [
        'value' => 0,
        'type' => 'Integer',
        'increment' => 2000,
      ],
      2 => [
        'value' => 2000,
        'type' => 'Integer',
        'increment' => 2000,
      ],
    ],
      [
        'sql_returns_none' => '
           SELECT j.id
           FROM civicrm_mailing_job j
           LEFT JOIN civicrm_mailing_event_queue q ON q.job_id = j.id
           WHERE q.id IS NULL LIMIT 1',
      ], 20);
    return TRUE;
  }

  /**
   *
   * Cull some mailing details.
   *
   * Even though we are busily updating the job_id on the civicrm_mailing_event_queue
   * table I've concluded we could slip in a delete to run before it
   * and delete a bunch of mailing queue records to save us updating them.
   *
   * I'm giving this job a weight of -1 so that once we deploy it it
   * will actually get preference over the already running (at least on
   * staging, not yet +2d for prod) updates above it. This is fun.
   *
   * The query at https://wikitech.wikimedia.org/wiki/Fundraising/Internal-facing/CiviCRM#CiviMail_records
   * finds we have a tonne of mailing rows.
   *
   * | civicrm_mailing_event_bounce             | 2023-10-10 02:10:04 | 2008-10-31 05:00:02 |  1333353 |
   * | civicrm_mailing_event_delivered          | 2023-10-10 02:20:26 | 2008-10-25 08:10:11 | 52720715 |
   * | civicrm_mailing_event_forward            | NULL                | NULL                |        0 |
   * | civicrm_mailing_event_opened             | 2013-04-10 21:15:40 | 2008-10-25 08:20:54 |   421457 |
   * | civicrm_mailing_event_reply              | 2023-06-11 12:55:03 | 2008-10-31 05:00:03 |      402 |
   * | civicrm_mailing_event_subscribe          | NULL                | NULL                |        0 |
   * | civicrm_mailing_event_trackable_url_open | 2018-08-22 03:12:33 | 2008-10-25 13:26:52 |    62816 |
   * | civicrm_mailing_event_unsubscribe        | 2021-11-16 17:10:23 | 2008-10-28 04:38:05 |     9168 |
   *
   * Per comments on wikitech these are of little value except when current.
   * The exception is that bounce & reply information do give us some valuable contact
   * history - this is now captured in activities.
   *
   * This change removes queue &, by cascade delete, mailing event data where the email
   * was delivered more than a year ago. I think there is an appetite for a more aggressive
   * approach
   */
  public function upgrade_4390(): bool {
    $sql = "DELETE q
FROM civicrm_mailing_event_queue q
LEFT JOIN civicrm_mailing_event_delivered d ON d.event_queue_id = q.id

WHERE
d.time_stamp < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
AND q.id BETWEEN %1 AND %2";
    $this->queueSQL($sql, [
      1 => [
        'value' => 0,
        'type' => 'Integer',
        'increment' => 2000,
      ],
      2 => [
        'value' => 2000,
        'type' => 'Integer',
        'increment' => 2000,
      ],
    ],
      [
        'sql_returns_none' => '
         SELECT q.id
         FROM civicrm_mailing_event_queue q
           LEFT JOIN civicrm_mailing_event_delivered d ON d.event_queue_id = q.id
         WHERE d.time_stamp < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) LIMIT 1',
      ],
      -5);
    return TRUE;
  }

  public function upgrade_4391(): bool {
    return $this->upgrade_4390();
  }

  /**
   * Disable the fields that we intend to delete during maintenance window.
   *
   * This is mostly by way of confirming agreement on the affected fields.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4395(): bool {
    $fieldsToDisable = [
      'all_funds_change_2018_2019',
      'all_funds_change_2019_2020',
      'all_funds_change_2020_2021',
      'all_funds_change_2021_2022',
      'change_2017_2018',
      'change_2018_2019',
      'change_2019_2020',
      'change_2020_2021',
      'change_2021_2022',
      'total_2006',
      'total_2007',
      'total_2008',
      'total_2009',
      'total_2010',
      'total_2011',
      'total_2012',
      'total_2013',
      'total_2014',
      'total_2015',
      'total_2016',
      'total_2017',
      'total_2018',
      'total_2019',
      'total_2020',
      'total_2021',
      'total_2022',
      'endowment_total_2018',
      'endowment_total_2019',
      'endowment_total_2020',
      'endowment_total_2021',
      'endowment_total_2022',
      'total_2006_2007',
      'total_2007_2008',
      'total_2008_2009',
      'total_2009_2010',
      'total_2010_2011',
      'total_2011_2012',
      'total_2012_2013',
      'total_2013_2014',
      'total_2014_2015',
      'total_2015_2016',
      'total_2016_2017',
    ];
    CRM_Core_DAO::executeQuery('
      UPDATE civicrm_custom_field SET is_active = 0
      WHERE name in ("' . implode('", "', $fieldsToDisable) . '")');
    return TRUE;
  }

  /**
   * Add an index for lookup up payment tokens. This should speed up donations queue processing
   * for initial recurring donations.
   *
   * @return bool
   */
  public function upgrade_4400(): bool {
    $tables = ['civicrm_payment_token' => ['token']];
    CRM_Core_BAO_SchemaHandler::createIndexes($tables);
    return TRUE;
  }

  public function upgrade_4405(): bool {
    $fieldsToDrop = [
      'all_funds_change_2018_2019',
      'all_funds_change_2019_2020',
      'all_funds_change_2020_2021',
      'all_funds_change_2021_2022',
      'change_2017_2018',
      'change_2018_2019',
      'change_2019_2020',
      'change_2020_2021',
      'change_2021_2022',
      'total_2006',
      'total_2007',
      'total_2008',
      'total_2009',
      'total_2010',
      'total_2011',
      'total_2012',
      'total_2013',
      'total_2014',
      'total_2015',
      'total_2016',
      'total_2017',
      'total_2018',
      'total_2019',
      'total_2020',
      'total_2021',
      'total_2022',
      'endowment_total_2018',
      'endowment_total_2019',
      'endowment_total_2020',
      'endowment_total_2021',
      'endowment_total_2022',
      'total_2006_2007',
      'total_2007_2008',
      'total_2008_2009',
      'total_2009_2010',
      'total_2010_2011',
      'total_2011_2012',
      'total_2012_2013',
      'total_2013_2014',
      'total_2014_2015',
      'total_2015_2016',
      'total_2016_2017',
    ];
    foreach ($fieldsToDrop as $fieldName) {
      if (CRM_Core_BAO_SchemaHandler::checkIfFieldExists('wmf_donor', $fieldName, FALSE)) {
        $dropSQL[] = ' DROP COLUMN ' . $fieldName;
      }
    }
    if (!empty($dropSQL)) {
      $sql = ' ALTER TABLE wmf_donor ' . implode(",\n", $dropSQL);
      CRM_Core_DAO::executeQuery($sql);
    }
    CRM_Core_DAO::executeQuery('DELETE f FROM civicrm_custom_field f INNER JOIN civicrm_custom_group g ON f.custom_group_id = g.id AND g.name = "wmf_donor"
      WHERE f.name IN ("' . implode('", "', $fieldsToDrop) . '")
   ');
    civicrm_api3('System', 'flush');
    return TRUE;
  }

  /**
   * Rename External_Identifiers table to wmf_external_contact_identifiers
   *
   * @return bool
   * @throws CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4402(): bool {
    // Bail out if not needed
    $currentTableName = CRM_Core_DAO::singleValueQuery(
      "SELECT table_name FROM civicrm_custom_group WHERE name='External_Identifiers'"
    );
    if ($currentTableName === 'wmf_external_contact_identifiers') {
      return TRUE;
    }
    CRM_Core_DAO::executeQuery("
      CREATE TABLE `wmf_external_contact_identifiers` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Default MySQL primary key',
        `entity_id` int(10) unsigned NOT NULL COMMENT 'Table that this extends',
        `fundraiseup_id` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_entity_id` (`entity_id`),
        KEY `index_fundraiseup_id` (`fundraiseup_id`),
        CONSTRAINT `FK_wmf_external_contact_identifiers_entity_id` FOREIGN KEY (`entity_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Move over the data
    CRM_Core_DAO::executeQuery("
      INSERT INTO wmf_external_contact_identifiers (entity_id, fundraiseup_id)
      SELECT entity_id, fundraiseup_id
      FROM External_Identifiers
    ");
    // Triggers are not yet applied to the old table, so the old log table is empty
    CRM_Core_DAO::executeQuery("
      CREATE TABLE `log_wmf_external_contact_identifiers` (
        `id` int(10) unsigned DEFAULT NULL COMMENT 'Default MySQL primary key',
        `entity_id` int(10) unsigned DEFAULT NULL COMMENT 'Table that this extends',
        `log_date` timestamp NOT NULL DEFAULT current_timestamp(),
        `log_conn_id` varchar(17) DEFAULT NULL,
        `log_user_id` int(11) DEFAULT NULL,
        `log_action` enum('Initialization','Insert','Update','Delete') DEFAULT NULL,
        `fundraiseup_id` varchar(255) DEFAULT NULL,
        KEY `index_id` (`id`),
        KEY `index_log_conn_id` (`log_conn_id`),
        KEY `index_log_date` (`log_date`),
        KEY `index_entity_id` (`entity_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=4
    ");
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_custom_group
      SET table_name = 'wmf_external_contact_identifiers' WHERE name='External_Identifiers'
    ");

    return TRUE;
  }

  /**
   * Clean up some unused tables
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4404(): bool {
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS T349358');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS External_Identifiers');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS log_External_Identifiers');
    return TRUE;
  }

  /**
   * Run the recurring gift financial type update once more.
   *
   * After the last fix on monthly convert ones they don't seem
   * to be being created anymore but 1550 remain to be fixed up.
   *
   * Note I found one that would be recoded possibly incorrectly through this
   * - ie
   * https://civicrm.wikimedia.org/civicrm/contact/view/contribution?reset=1&id=96033970&cid=26220292&action=view&context=contribution&selectedChild=contribute
   * - perhaps the type is correct given the first in the series was a refund
   * but it is a $1 test transaction so we can ignore that I think.
   *
   * SQL to find
   * SELECT count(*),max(a.receive_date)
   * FROM   civicrm_contribution a
   * LEFT JOIN civicrm_contribution c2 ON c2.contribution_recur_id = a.contribution_recur_id
   *  AND c2.receive_date < a.receive_date
   * LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
   *   ON a.id = Gift_Data.entity_id
   * WHERE `a`.`receive_date` > "20230701000000"
   *   AND `a`.`contribution_recur_id` > 0
   *   AND `a`.`financial_type_id` NOT IN (31)   AND `a`.`is_test` = 0   AND c2.id IS NULL
   *
   * @return bool
   */
  public function upgrade_4410(): bool {
    return $this->upgrade_4330();
  }

  /**
   * Re-run upgrade 4405 - I forgot to clean up civicrm_custom_field.
   *
   * Bug: T347724
   *
   * @return bool
   */
  public function upgrade_4415(): bool {
    return $this->upgrade_4405();
  }

  /**
   * Mop up mailing_event_queue records with no mailing_id.
   *
   * We added the new columns to civicrm_mailing_event_queue before
   * we applied the full CiviCRM upgrade. During this window 4918
   * civicrm_mailing_event_queue records were created. These records have
   * no value in the mailing_id field.
   *
   * We can just run the query without queueing as it only affects < 5k rows.
   *
   * Bug: T350209
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4420(): bool {
    CRM_Core_DAO::executeQuery('
      UPDATE civicrm_mailing_event_queue
      SET mailing_id = 1
      -- this job_id = 1 does not actually further reduce the results
      -- so it is mostly there for clarity that we are actually
      -- only altering rows with job_id = 1
      WHERE job_id = 1
        AND mailing_id IS NULL');
    return TRUE;
  }

  /**
   * external data from fundraiseup's venmo should still fill to
   * fundraiseup_id instead of venmo_user_name.
   *
   * Bug: T351345
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4425(): bool {
    CRM_Core_DAO::executeQuery('
    UPDATE wmf_external_contact_identifiers
    SET fundraiseup_id = venmo_user_name, venmo_user_name = NULL
    WHERE venmo_user_name <> ""
     AND venmo_user_name NOT LIKE "@%"
     AND LENGTH(venmo_user_name) = 8
     ');
    return TRUE;
  }

  /**
   * Update the value for the realized planned giving option to match the database.
   *
   * On 2023-11-29 22:44:57 Melanie did 'something' that altered the values in the
   * database for the users with this option value from 'Realized PG Gift' to
   * 'Realized Bequest'. However, the option value value was not updated.
   *
   * I don't feel like this should have been possible through the UI - so will ask
   * more questions. However, in the meantime we can need to update
   * one of the tables to get them back in sync & changing the option value table
   * gets them to the preferred value.
   *
   * SELECT * FROM log_civicrm_value_1_gift_data_7 WHERE log_conn_id = '6567bee97431be0YY';
   *
   * Bug: TT352343
   * Bug: T352574
   *
   * @return bool
   *
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4430(): bool {
    CRM_Core_DAO::executeQuery('
    UPDATE civicrm_option_value SET value = "Realized Bequest", name = "Realized_Bequest" WHERE id = 7009');
    return TRUE;
  }

  /**
   * Fix deprecation notices in our civi-log.
   *
   * Port https://github.com/civicrm/civicrm-core/pull/28491
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4435(): bool {
    CRM_Core_DAO::executeQuery("UPDATE civicrm_custom_field SET default_value = NULL WHERE default_value = '' AND data_type = 'Float'");
    return TRUE;
  }

  /**
   * Add options for Employee Giving Volunteer match.
   *
   * https://phabricator.wikimedia.org/T354911
   *
   * Bug: T354911
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4440(): bool {
    $optionGroupID = CustomField::get(FALSE)
      ->addSelect('option_group_id')
      ->addWhere('name', '=', 'Campaign')
      ->execute()
      ->first()['option_group_id'];
    OptionValue::create(FALSE)->setValues([
      'option_group_id' => $optionGroupID,
      'label' => 'Employee Giving',
      'value' => 'Employee Giving',
      'name' => 'Employee_Giving',
    ])->execute();
    OptionValue::create(FALSE)->setValues([
      'option_group_id' => $optionGroupID,
      'label' => 'Volunteer Match',
      'value' => 'Volunteer Match',
      'name' => 'Volunteer_Match',
    ])->execute();
    return TRUE;
  }

  /**
   * Set Financial Type to Cash for contribution recur records with NULL
   * Should update 1512116 rows.
   *
   * @return bool
   */
  public function upgrade_4450(): bool {
    $cashId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash');
    $sql = "UPDATE civicrm_contribution_recur SET financial_type_id = $cashId
      WHERE financial_type_id IS NULL
      LIMIT 2000";

    $this->queueSQL($sql);
    return TRUE;
  }

  /**
   * Allow manual creation of 'Recurring Upgrade Decline' activities
   *
   * Bug: T362087
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4460(): bool {
    CRM_Core_DAO::executeQuery('
    UPDATE civicrm_option_value
    SET is_reserved = 0, filter = 0
    WHERE name=\'Recurring Upgrade Decline\'
    ');
    return TRUE;
  }

  /**
   * Backfill payment_instrument_id on contribution_recur
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4475(): bool {
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_contribution_recur cr
      INNER JOIN wmf_contribution_extra x ON x.gateway_txn_id = cr.processor_id
      INNER JOIN civicrm_contribution c ON c.id = x.entity_id
      SET cr.payment_instrument_id = c.payment_instrument_id
      WHERE cr.payment_instrument_id IS NULL
      AND cr.create_date > '2024-03-01'"
    );
    return TRUE;
  }

  public function upgrade_4480(): bool {
    $optionValues = ['option_group_id:name' => 'Address_Data_Source', 'is_active' => TRUE];
    $paypal = [
      'label' => 'PayPal',
      'name' => 'paypal',
      'value' => 'paypal',
    ] + $optionValues;
    $fundraiseUp = [
      'label' => 'Fundraise Up',
      'name' => 'fundraiseup',
      'value' => 'fundraiseup',
    ] + $optionValues;
    OptionValue::create(FALSE)
      ->setValues($paypal)
      ->execute();
    OptionValue::create(FALSE)
      ->setValues($fundraiseUp)
      ->execute();
    return TRUE;
  }

  public function upgrade_4484(): bool {
    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_contribution_tracking', 'banner_history_log_id', FALSE)) {
      CRM_Core_DAO::executeQuery("
        ALTER TABLE civicrm_contribution_tracking ADD COLUMN
         `banner_history_log_id` varchar(255) COMMENT 'Temporary banner history log ID to associate banner history EventLogging events.'
      ");
    }
    global $databases;
    CRM_Core_DAO::executeQuery("
        UPDATE civicrm_contribution_tracking t
        LEFT JOIN " . $databases['default']['default']['database'] . ".banner_history_contribution_associations b
          ON b.contribution_tracking_id = t.id
        SET t.banner_history_log_id = b.banner_history_log_id
        WHERE t.banner_history_log_id IS NULL
    ");
    return TRUE;
  }

  /**
   * Copy existing exchange rates from drupal table
   * @return bool
   */
  public function upgrade_4485(): bool {
    // Skip if old table is already gone
    if (!CRM_Core_DAO::singleValueQuery(
      "SELECT 1 FROM information_schema.tables WHERE table_schema='drupal' AND table_name='exchange_rates'"
    )) {
      return TRUE;
    }
    $existingRates = CRM_Core_DAO::executeQuery('SELECT * FROM drupal.exchange_rates');
    while ($existingRates->fetch()) {
      ExchangeRate::create(FALSE)->setValues([
        'currency' => $existingRates->currency,
        'value_in_usd' => $existingRates->value_in_usd,
        'bank_update' => date('Y-m-d H:i:s', $existingRates->bank_update),
        'local_update' => date('Y-m-d H:i:s', $existingRates->local_update),
      ])->execute();
    }
    return TRUE;
  }

  /**
   * Copy existing exchange rates from drupal table
   * @return bool
   */
  public function upgrade_4490(): bool {
    OptionValue::update(FALSE)
      ->addWhere('name', '=', 'NOCA_update')
      ->setValues([
        'name' => 'NCOA_update',
        'value' => 'ncoa',
        'label' => 'NCOA Update',
      ])->execute();
    CRM_Core_DAO::executeQuery('UPDATE civicrm_value_address_data SET source = "ncoa" WHERE source = "noca"');
    return TRUE;
  }

  /**
   * Update segment values.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4495(): bool {
    OptionValue::update(FALSE)
      ->addWhere('name', '=', 'recurring_active')
      ->setValues([
        'value' => 2,
      ])->execute();
    OptionValue::update(FALSE)
      ->addWhere('name', '=', 'recurring_delinquent')
      ->addWhere('value', '=', 85)
      ->setValues([
        'value' => 4,
      ])->execute();
    OptionValue::update(FALSE)
      ->addWhere('name', '=', 'recurring_lapsed_recent')
      ->setValues([
        'value' => 6,
      ])->execute();
    OptionValue::update(FALSE)
      ->addWhere('name', '=', 'recurring_delinquent')
      ->addWhere('value', '=', 95)
      ->setValues([
        'value' => 8,
        'name' => 'recurring_deep_lapsed',
      ])->execute();
    return TRUE;
  }

  /**
   * Update segment values for non-donors.
   *
   * These have generally NOT been populated but
   * obviously in some early runs we got some donor
   * segments populated without the donor status.
   *
   * The script won't touch these unless we merge
   * https://gerrit.wikimedia.org/r/c/wikimedia/fundraising/crm/+/1034643
   * but there are only 200 k of these so let's just tidy
   * them up.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4500(): bool {
    CRM_Core_DAO::executeQuery(
      'UPDATE wmf_donor SET donor_status_id = 100 WHERE donor_segment_id = 1000 AND donor_status_id IS NULL'
    );
    return TRUE;
  }

  /**
   * Delete bad contributions caused by IPN boolean parsing bug
   * Bug: T365519
   *
   * @return bool
   */
  public function upgrade_4510(): bool {
    $sql = 'DELETE FROM civicrm_contribution WHERE id in (
SELECT contribution_id FROM T365519 t WHERE t.id BETWEEN %1 AND %2)';

    $this->queueSQL($sql, [
      1 => [
        'value' => 0,
        'type' => 'Integer',
        'increment' => 200,
      ],
      2 => [
        'value' => 200,
        'type' => 'Integer',
        'increment' => 200,
      ],
    ]);
    return TRUE;
  }

  /**
   * Another attempt to delete bad contributions caused by IPN boolean parsing bug
   * Bug: T365519
   *
   * @return bool
   */
  public function upgrade_4511(): bool {
    if (!CRM_Core_DAO::singleValueQuery('SHOW TABLES LIKE "T365519"')) {
      return TRUE;
    }
    $queue = new QueueHelper(\Civi::queue('wmf_data_upgrades', [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 100,
      'reset' => FALSE,
      'error' => 'abort',
    ]));
    $contributionsToDelete = CRM_Core_DAO::executeQuery('SELECT distinct contribution_id FROM T365519');
    while ($contributionsToDelete->fetch()) {
      $queue->api4('Contribution', 'delete', [
        'where' => [['id', '=', $contributionsToDelete->contribution_id]],
        'checkPermissions' => FALSE,
      ], ['weight' => 100]);
    }
    return TRUE;
  }

  /**
   * Cancel recurring subscriptions with autorescue ended IPNs.
   * Bug: T367451
   *
   * @return bool
   */
  public function upgrade_4516(): bool {
    if (!CRM_Core_DAO::singleValueQuery('SHOW TABLES LIKE "T365519"')) {
      return TRUE;
    }
    $queue = new QueueHelper(\Civi::queue('wmf_data_upgrades', [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 100,
      'reset' => FALSE,
      'error' => 'abort',
    ]));
    $recursToCancel = CRM_Core_DAO::executeQuery(
      'SELECT contribution_recur_id, date FROM T367451 WHERE contribution_recur_id IS NOT NULL');
    while ($recursToCancel->fetch()) {
      $queue->api4('ContributionRecur', 'update', [
        'values' => [
          'cancel_date' => $recursToCancel->date,
          'cancel_reason' => 'Payment cannot be rescued: maximum failures reached',
          // Cancelled
          'contribution_status_id' => 3,
          'contribution_recur_smashpig.rescue_reference' => '',
        ],
        'where' => [['id', '=', $recursToCancel->contribution_recur_id]],
        'checkPermissions' => FALSE,
      ], ['weight' => 100]);
    }
    return TRUE;
  }

  /**
   * Annual upgrade for the WMF Donor segment & status.
   *
   * Bug: T368974
   * @return bool
   */
  public function upgrade_4520(): bool {
    // The highest contact ID at the point at which the triggers were updated.
    // $maxContactID = 64261802;
    // This is set low for testing - when testing is done we can re-queue with a larger number
    // and also larger increments.
    $maxContactID = 20000;
    $this->queueApi4('WMFDonor', 'update', [
      'values' => [
        'donor_segment_id' => '',
        'donor_status_id' => '',
      ],
      'where' => [
        ['id', 'BETWEEN', ['%1', '%2']],
      ],
    ],
    [
      1 => [
        'value' => 0,
        'type' => 'Integer',
        'increment' => 2,
        'max' => $maxContactID,
      ],
      2 => [
        'value' => 2,
        'type' => 'Integer',
        'increment' => 2,
      ],
    ]);
    return TRUE;
  }

  /**
   * Annual upgrade for the WMF Donor segment & status.
   *
   * Take 2 - try a bunch more in larger batches, see how we go
   *
   * Bug: T368974
   * @return bool
   */
  public function upgrade_4525(): bool {
    // The highest contact ID at the point at which the triggers were updated.
    // $maxContactID = 64261802;
    // This is set low for testing - when testing is done we can re-queue with a larger number
    // and also larger increments.
    $maxContactID = 2000000;
    $this->queueApi4('WMFDonor', 'update', [
      'values' => [
        'donor_segment_id' => '',
        'donor_status_id' => '',
      ],
      'where' => [
        ['id', 'BETWEEN', ['%1', '%2']],
      ],
    ],
      [
        1 => [
          'value' => 20000,
          'type' => 'Integer',
          'increment' => 10000,
          'max' => $maxContactID,
        ],
        2 => [
          'value' => 30000,
          'type' => 'Integer',
          'increment' => 10000,
        ],
      ]);
    return TRUE;
  }

  /**
   * Annual upgrade for the WMF Donor segment & status.
   *
   * Take 2 - try a bunch more in larger batches, see how we go
   *
   * Bug: T368974
   * @return bool
   */
  public function upgrade_4530(): bool {
    // The highest contact ID at the point at which the triggers were updated.
    // $maxContactID = 64261802;
    // This is set low for testing - when testing is done we can re-queue with a larger number
    // and also larger increments.
    $maxContactID = 20000000;
    $this->queueApi4('WMFDonor', 'update', [
      'values' => [
        'donor_segment_id' => '',
        'donor_status_id' => '',
      ],
      'where' => [
        ['id', 'BETWEEN', ['%1', '%2']],
      ],
    ],
      [
        1 => [
          'value' => 2000000,
          'type' => 'Integer',
          'increment' => 100000,
          'max' => $maxContactID,
        ],
        2 => [
          'value' => 2100000,
          'type' => 'Integer',
          'increment' => 100000,
        ],
      ]);
    return TRUE;
  }

  /**
   * Annual upgrade for the WMF Donor segment & status.
   *
   * This increment seems to be churning through pretty smoothly - let's
   * let her rip.
   *
   * Bug: T368974
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4535(): bool {
    $this->doAnnualWMFDonorRollover();
    return TRUE;
  }

  /**
   * Retry dropping the event cart tables, field now the triggers are gone.
   *
   * Bug: T368999
   *
   * @return bool
   */
  public function upgrade_4550(): bool {
    // This delete is slightly more aggressive than the upstream ... cos.
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_msg_template WHERE workflow_name = 'event_registration_receipt'");
    CRM_Core_DAO::executeQuery('DROP table IF EXISTS log_civicrm_event_cart_participant');
    CRM_Core_DAO::executeQuery('DROP table IF EXISTS  log_civicrm_event_carts');
    \CRM_Core_BAO_SchemaHandler::safeRemoveFK('civicrm_participant', 'FK_civicrm_participant_cart_id');
    \CRM_Core_BAO_SchemaHandler::dropColumn('civicrm_participant', 'cart_id', FALSE, TRUE);
    return TRUE;
  }

  /**
   * Fix cancel dates for already cancelled paypal recurrings
   * Bug: T367623
   *
   * @return bool
   */
  public function upgrade_4555(): bool {
    // Get all the paypal recurrings that were automatically cancelled between 2024-05-23 and 2024-06-19
    // 15079 in total
    $contributionRecurs = ContributionRecur::get(TRUE)
      ->addSelect('id')
      ->addWhere('payment_processor_id:name', 'IN', ['paypal', 'paypal_ec'])
      ->addWhere('cancel_date', '>', '2024-05-22')
      ->addWhere('cancel_date', '<', '2024-06-20')
      ->addWhere('cancel_reason', '=', 'Automatically cancelled for inactivity')
      ->execute();

    foreach($contributionRecurs as $recur) {
      $cancel_date = CRM_Core_DAO::singleValueQuery(
        'SELECT cancel_date FROM log_civicrm_contribution_recur WHERE id='.$recur['id'].'
        AND cancel_date IS NOT NULL AND cancel_date < "2024-05-24" LIMIT 1');

      if ($cancel_date) {
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $recur['id'])
          ->setValues([
            'cancel_date' => $cancel_date,
          ])->execute();
      }
    }

    return TRUE;
  }

  /**
   * Fix dlocal emails that got updated to 0000@dlocal.com
   * Bug: T371911
   *
   * @return bool
   */
  public function upgrade_4560(): bool {
    // Get all the contacts that were affected, they are in the group 0000@dlocal email addresses
    // 3159 in total
    // $emails = \Civi\Api4\Contact::get(TRUE)
    //   ->addSelect('email_primary')
    //   ->addWhere('groups:name', 'IN', ['0000_dlocal_com_email_addresses_2158'])
    //   ->execute();
    //
    // foreach($emails as $email) {
    //   $emailAddress = CRM_Core_DAO::singleValueQuery(
    //     'SELECT email FROM log_civicrm_email WHERE id='.$email['email_primary'].'
    //     AND log_date < "2024-07-30" LIMIT 1');
    //
    //   if ($emailAddress) {
    //     \Civi\Api4\Email::update(FALSE)
    //       ->addWhere('id', '=', $email['email_primary'])
    //       ->setValues([
    //         'email' => $emailAddress,
    //       ])->execute();
    //   }
    // }
    //
    return TRUE;
  }

  /**
   * Add last-name, first_name index for improved dedupe queries.
   *
   * This took about a minute of doing a dedupe find on the first_name,
   *
   * Bug: T353971
   *
   * @return bool
   */
  public function upgrade_4565(): bool {
    CRM_Core_BAO_SchemaHandler::createIndexes(['civicrm_contact' => [['last_name', 'first_name']]]);
    return TRUE;
  }

  /**
   * Alter column_name of new fields to be saner.
   *
   * These were created through the UI by Nora & hence got the appended _377, _378.
   *
   * Since we will have to interact with these using code it makes sense to use cleaner names
   * for these fields.
   *
   * Bug: T353971
   *
   * @return bool
   */
  public function upgrade_4570(): bool {
    $isAdded = CRM_Core_DAO::executeQuery("SHOW columns FROM civicrm_value_1_gift_data_7 WHERE Field LIKE 'package_377' OR Field LIKE 'channel_378'");
    if ($isAdded->N) {
      CRM_Core_DAO::executeQuery('ALTER TABLE  civicrm_value_1_gift_data_7
        ADD COLUMN package varchar(255) DEFAULT NULL,
        ADD COLUMN channel varchar(255) DEFAULT NULL,
        ADD INDEX `index_package` (`package`),
        ADD INDEX `index_channel` (`channel`)
      ');
      CRM_Core_DAO::executeQuery('UPDATE civicrm_custom_field SET column_name = "package" WHERE column_name = "package_377"');
      CRM_Core_DAO::executeQuery('UPDATE civicrm_custom_field SET column_name = "channel" WHERE column_name = "channel_378"');
      civicrm_api3('System', 'flush');
      CRM_Core_DAO::executeQuery('UPDATE civicrm_value_1_gift_data_7 SET package = package_377 WHERE package_377 IS NOT NULL');

      CRM_Core_DAO::executeQuery('UPDATE civicrm_value_1_gift_data_7 SET channel = channel_378 WHERE civicrm_value_1_gift_data_7.channel_378 IS NOT NULL');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_value_1_gift_data_7
         DROP INDEX index_package_377,
         DROP INDEX index_channel_378');

      // We also want to drop columns - but AFTER triggers are re-loaded
      // DROP COLUMN package_377,
      // DROP COLUMN channel_378,
    }
    return TRUE;
  }

  /**
   * Finish removing the fields from 4570.
   *
   * Bug: T353971
   *
   * @return bool
   */
  public function upgrade_4575(): bool {
    $isAdded = CRM_Core_DAO::executeQuery("SHOW columns FROM civicrm_value_1_gift_data_7 WHERE Field LIKE 'package_377' OR Field LIKE 'channel_378'");
    if ($isAdded) {
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_value_1_gift_data_7
         DROP column package_377,
         DROP column channel_378');
    }
    return TRUE;
  }

  /**
   * Finish finishing removing the fields from 4570.
   *
   * Bug: T353971
   *
   * @return bool
   */
  public function upgrade_4580(): bool {
    $isAdded = CRM_Core_DAO::executeQuery("SHOW columns FROM log_civicrm_value_1_gift_data_7 WHERE Field LIKE 'package_377' OR Field LIKE 'channel_378'");
    if ($isAdded) {
      CRM_Core_DAO::executeQuery('ALTER TABLE log_civicrm_value_1_gift_data_7
         DROP column package_377,
         DROP column channel_378');
    }
    return TRUE;
  }

  /**
   * Add labels for new status and segment options
   * @return bool
   * @throws CRM_Core_Exception
   */
  public function upgrade_4585(): bool {
    $fieldFinder = new CalculatedData();
    $fields = $fieldFinder->getWMFDonorFields();
    foreach (['donor_segment_id', 'donor_status_id'] as $fieldName) {
      $optionGroupID = CustomField::get(FALSE)
        ->addWhere('name', '=', $fieldName)
        ->addSelect('option_group_id')
        ->execute()->first()['option_group_id'];
      foreach ($fields[$fieldName]['option_values'] as $values) {
        if (in_array($values['name'], ['recurring_annual', 'annual_recurring_active'])) {
          $values['option_group_id'] = $optionGroupID;
          OptionValue::create( FALSE )
            ->setValues( $values )->execute();
        }
      }
    }
    return TRUE;
  }

  /**
   * Fix value for 'Other Offline' gift data option
   * @return bool
   * @throws CRM_Core_Exception
   */
  public function upgrade_4590(): bool {
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_value_1_gift_data_7 SET channel='Other Offline' WHERE channel='1'"
    );
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_option_value SET value='Other Offline' WHERE name='Other Offline'"
    );
    return TRUE;
  }

  /**
   * Adjust stock_qty to float to deal with fractional amounts.
   *
   * Bug: T380804
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4595(): bool {
    CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_value_1_stock_information_10 MODIFY COLUMN stock_qty DOUBLE DEFAULT NULL");
    CRM_Core_DAO::executeQuery("ALTER TABLE log_civicrm_value_1_stock_information_10 MODIFY COLUMN stock_qty DOUBLE DEFAULT NULL");
    CRM_Core_DAO::executeQuery("UPDATE civicrm_custom_field SET data_type= 'Float' WHERE column_name = 'stock_qty'");
    return TRUE;
  }

  /**
   * Fix invalid receipt dates.
   *
   * I checked a couple of these & the are old & we don't really use receipt_date anyway.
   *
   * Bug: T383162
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4600(): bool {
    CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution SET receipt_date = NULL
      WHERE day(receipt_date) = 0");
    return TRUE;
  }


  /**
   * Fix invalid source_record_id.
   *
   * As of writing we have around 23,000 activity records with the invalid value of 0 in the source record_id column
   * SELECT activity_type_id, MIN(activity_date_time), MAX(activity_date_time),
   * count(*) FROM civicrm_activity WHERE source_record_id = 0 GROUP BY activity_type_id;
   * +------------------+-------------------------+-------------------------+----------+
   * | activity_type_id | MIN(activity_date_time) | MAX(activity_date_time) | count(*) |
   * +------------------+-------------------------+-------------------------+----------+
   * |               80 | 2024-10-09 07:40:02     | 2025-01-14 00:08:03     |     2141 |
   * |              129 | 2020-10-28 00:00:00     | 2020-10-28 00:00:00     |    21604 |
   *
   * The 2 activity types have different origin stories. The ones that have activity_type_id are old. End of.
   *
   * The ones with activity_type_id = 80 started in October last year after
   * 35b0ba5ddd29879a730d4b3d6b8003f18b675c92 was deployed. That commit mistakenly mapped
   * the transaction id value to source_record_id instead of the contribution_id (fixed in prior patch).
   * In most cases the transaction ID was not also a valid contribution ID - however as of writing there are 4 instances
   * where it is - ie - the 2141 above is 2155 below.
   *
   * select count(*),max(activity_date_time), MIN(activity_date_time), MAX(source_record_id), MIN(source_record_id) FROM civicrm_activity WHERE subject = 'refund reason';
   * +----------+-------------------------+-------------------------+-----------------------+-----------------------+
   * | count(*) | max(activity_date_time) | MIN(activity_date_time) | MAX(source_record_id) | MIN(source_record_id) |
   * +----------+-------------------------+-------------------------+-----------------------+-----------------------+
   * |     2145 | 2025-01-14 00:08:03     | 2024-10-09 07:40:02     |               7569094 |                     0 |
   *
   * I did a spot check and in these cases the non-NULL value is A valid contribution ID but not THE valid
   * contribution ID - so they should be set to NULL too - hence the query does an OR to pick these up too.
   *
   * Bug: T383162
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4605(): bool {
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_activity SET source_record_id = NULL
      WHERE activity_date_time > '2024-10-09'
      AND (subject = 'refund reason' OR source_record_id = 0)
      AND activity_type_id IN (80, 129)
    ");
    return TRUE;
  }

  /**
   * Clean up empty website records.
   *
   * select count(*) FROM civicrm_website WHERE url IS NULL OR url = '';
   * +----------+
   * | count(*) |
   * +----------+
   * |    10693 |
   * +----------+
   *
   * Bug: T385898
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4610(): bool {
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_website WHERE url IS NULL OR url = ''
    ");
    return TRUE;
  }

  /**
   * Fix the data type of the original_amount field.
   *
   * Note that on our locals this is already 'Money' and the
   * data type of the field in wmf_contribution_extra and
   * log_wmf_contribution_extra is already correct - ie.
   * `original_amount` decimal(20,2) DEFAULT NULL,
   *
   * I avoided the api as the underlying table field is correct already.
   *
   * Also note that some cache clearing was required on staging before the notices
   * disappeared.
   *
   * Bug: T385898
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_4615(): bool {
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_custom_field SET data_type = 'Money' WHERE name = 'original_amount'
    ");
    return TRUE;
  }

  /**
   * Bug: T383195
   *
   * Update WMF donor values for new financial year.
   *
   * @throws \CRM_Core_Exception
   */
  public function upgrade_4620(): bool {
    $this->doAnnualWMFDonorRollover();
    return TRUE;
  }

  /**
   * Remove old prospect fields
   *
   * Bug: T395961
   *
   * @return bool
   * @throws CRM_Core_Exception
   */
  public function  upgrade_4630(): bool {
    CustomField::delete(FALSE)
      ->addWhere('is_active', '=', FALSE)
      ->addWhere('name', 'IN', [
        // disabled Mar 2024 was marked 'Legacy data point - Disable by 2023'
        'Steward',
        // disabled Feb 2024
        'Endowment_Stage',
        'Net_Worth',
        'data_axle_net_worth',
        'data_axle_expendable_income',
        'data_axle_donation_interest',
        'data_axle_is_single',
        'data_axle_is_grandparent',
        'data_axle_is_parent',
        'data_axle_number_children',
        'data_axle_security_investor_likelihood',
        'data_axle_stock_investor_likelihood',
        'data_axle_homeowner_investor_likelihood',
        'data_axle_marital_status',
      ])
      ->execute();
    return TRUE;
  }

  /**
   * Install settlement transactions table.
   *
   * This table is likely to be a temporary table but I think it will be
   * helpful as we work through this process for validation.
   */
  public function upgrade_4635(): bool {
    $this->ctx->log->info('Applying update 1001: Create transactions table');
    E::schema()->createEntityTable('schema/SettlementTransaction.entityType.php');
    return TRUE;
  }

  public function upgrade_4640() : bool {
    // On new installs the managed TransactionLog entityType will have created
    // a regular table, but we just want the view.
    CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS civicrm_transaction_log");
    CRM_Core_DAO::executeQuery("CREATE VIEW civicrm_transaction_log as
      SELECT *
      FROM smashpig.pending");
    return TRUE;
  }

  public function upgrade_4650() : bool {
    $contributionRecurs = ContributionRecur::get(FALSE)
      ->addSelect('address.country_id:abbr', 'contact_id', 'contribution_status_id:name', 'contact.legal_identifier', 'currency')
      ->addJoin('Contact AS contact', 'LEFT', ['contact_id', '=', 'contact.id'])
      ->addJoin('Address AS address', 'LEFT', ['address.contact_id', '=', 'contact.id'], ['address.is_primary', '=', 1])
      ->addWhere('payment_processor_id', '=', 19)
      ->addWhere('contribution_status_id:name', 'IN', ['Pending', 'In Progress', 'Failing'])
      ->addWhere('contribution_recur_smashpig.original_country', 'IS EMPTY')
      ->addWhere('contact.legal_identifier', 'IS NOT EMPTY')
      ->execute();
    foreach ($contributionRecurs as $contributionRecur) {
      // For donors in a country with a fiscal_number mapping, just set the original_country to current country
      if (in_array($contributionRecur['address.country_id:abbr'], ['AR', 'BR', 'CL', 'CO', 'IN', 'MX', 'ZA', 'UY', 'PE'])) {
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $contributionRecur['id'])
          ->addValue('contribution_recur_smashpig.original_country:abbr', $contributionRecur['address.country_id:abbr'])
          ->execute();
      }
      else {
        // Guess from currency code
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $contributionRecur['id'])
          ->addValue('contribution_recur_smashpig.original_country:abbr', substr($contributionRecur['currency'], 0, 2))
          ->execute();
      }
    }
    return TRUE;
  }

  /**
   * Fix opt_in values skipped because of WmfContact::save update handling
   * Bug: T401353
   *
   * @return bool
   */
  public function upgrade_4655(): bool {
    if (!file_exists('/tmp/optins_to_backfill.csv')) {
      return TRUE;
    }
    $queue = new QueueHelper(\Civi::queue('wmf_data_upgrades', [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 10,
      'reset' => FALSE,
      'error' => 'abort',
    ]));
    $queue->setRunAs(['contactId' => CRM_Core_Session::getLoggedInContactID()]);
    $reader = Reader::createFromPath('/tmp/optins_to_backfill.csv');

    foreach ($reader->getRecords(['email','timestamp','opt_in']) as $record) {
      $queue->api4('WMFContact', 'BackfillOptIn', [
        'email' => $record['email'],
        'date' => $record['timestamp'],
        'opt_in' => (bool)$record['opt_in'],
      ], [], ['weight' => 100]);
    }
    return TRUE;
  }

  /**
   * Add indexes to civicrm_phone_consent.
   *
   * Bug: T379702
   *
   * @return bool
   */
  public function upgrade_4660(): bool {
    CRM_Core_BAO_SchemaHandler::createIndexes(['civicrm_phone_consent' => ['master_recipient_id', 'phone_number']]);
    return TRUE;
  }

  /**
   * Drop 2020_2021 indexes from wmf_donor, make custom fields non-searchable
   * Bug: T404925
   *
   * @return bool
   */
  public function upgrade_4665(): bool {
    CRM_Core_BAO_SchemaHandler::dropIndexIfExists('wmf_donor', 'total_2020_2021');
    CRM_Core_BAO_SchemaHandler::dropIndexIfExists('wmf_donor', 'INDEX_endowment_total_2020_2021');
    CRM_Core_BAO_SchemaHandler::dropIndexIfExists('wmf_donor', 'INDEX_all_funds_total_2020_2021');
    CRM_Core_BAO_SchemaHandler::dropIndexIfExists('wmf_donor', 'index_all_funds_total_2019_2020');

    \Civi\Api4\CustomField::update(FALSE)
      ->addValue('is_searchable', FALSE)
      ->addWhere('name', 'LIKE', '%2020_2021%')
      ->execute();
    return TRUE;
  }

  public function upgrade_4670(): bool {
    $this->ctx->log->info('Applying update 4670: disable obsolete financial types');
    CRM_Core_DAO::executeQuery("
    UPDATE civicrm_financial_type
    SET is_active = 0
    WHERE name IN (
      'reversal',
      'admin_fraud_reversal',
      'unauthorized_spoof',
      'admin_reversal'
    )
  ");
    return TRUE;
  }

  public function upgrade_4675(): bool {
    $this->ctx->log->info('Applying update 4675: Add contribution_id index to settlement_transaction table');
    CRM_Core_BAO_SchemaHandler::createIndexes(['settlement_transaction' => ['contribution_id']]);
    return TRUE;
  }

  public function upgrade_4680(): bool {
    $this->ctx->log->info('Applying update 4680: Clear out test data from ');
    CRM_Core_DAO::executeQuery('TRUNCATE civicrm_value_contribution_settlement');
    return TRUE;
  }

  /**
   * Make activity tracking extend more subtypes
   */
  public function upgrade_4685(): bool {
    $this->addCustomFields();
    $recurringCancelActivityId = OptionValue::get(FALSE)
      ->addWhere('name', '=', 'Cancel Recurring Contribution')
      ->execute()
      ->first()['value'];
    $recurringModifyActivityId = OptionValue::get(FALSE)
      ->addWhere('name', '=', 'Update Recurring Contribution')
      ->execute()
      ->first()['value'];

    CustomGroup::update(FALSE)
      ->addWhere('name', '=', 'activity_tracking')
      ->addValue('extends_entity_column_value', [
        165,
        166,
        168,
        201,
        220,
        $recurringCancelActivityId,
        $recurringModifyActivityId
      ])
      ->execute();
    return TRUE;
  }

  /**
   * Update channel to email where the mailing_identifier is set.
   *
   * I did some checks and these are all currently empty - ie
   * select channel, count(*) FROM civicrm_contribution_tracking
   * LEFT JOIN civicrm_value_1_gift_data_7 ON entity_id = contribution_id
   * WHERE mailing_identifier LIKE '%' GROUP BY channel;
   * +---------+----------+
   * | channel | count(*) |
   * +---------+----------+
   * | NULL    | 24356733 |
   * |         |      183 |
   *
   * I agreed with Joseph that we should set the channel for these - going forwards we
   * will set on ingress. In testing the lowest relevant ID is
   * 3573301 so this will start from 3500000 and update in batches of 250k until
   * it runs a query with no affected rows. At 250k rows the query took about 1 second
   * on staging and I think we should be safe assuming no unaffected rows will
   * be in a 250k batch prior to the end of the road... However, if we update the
   * bulk & then need to pick up a few later that's OK - this will happen anyway if
   * we deploy the update before the ingress code goes out.
   *
   * Bug: T406193
   *
   * @return bool
   */
  public function upgrade_4690(): bool {
    $sql = '
      UPDATE civicrm_value_1_gift_data_7 gift
      INNER JOIN civicrm_contribution_tracking ON entity_id = contribution_id SET channel = "Email"
      WHERE mailing_identifier IS NOT NULL
      AND gift.id BETWEEN %1 AND %2';
    $this->queueSQL($sql, [
      1 => [
        'value' => 3500000,
        'type' => 'Integer',
        'increment' => 250000,
      ],
      2 => [
        'value' => 3750000,
        'type' => 'Integer',
        'increment' => 250000,
      ],
    ]);
    return TRUE;
  }

  /**
   * Bug: T406193
   *
   * @return bool
   */
  public function upgrade_4695(): bool {
    $sql = '
      UPDATE civicrm_value_1_gift_data_7 gift
      INNER JOIN civicrm_contribution_tracking ON entity_id = contribution_id SET channel = "Sidebar"
      WHERE utm_medium = "sidebar"
        AND channel IS NULL
      AND gift.id BETWEEN %1 AND %2';
    $this->queueSQL($sql, [
      1 => [
        'value' => 3500000,
        'type' => 'Integer',
        'increment' => 250000,
      ],
      2 => [
        'value' => 3750000,
        'type' => 'Integer',
        'increment' => 250000,
      ],
    ]);
    return TRUE;
  }

  /**
   * Bug: T406193
   *
   * Set channel = 'Recurring Gift' for all non-first recurrings.
   *
   * On digging this will change 1495 that are currently email
   * and 343 that are currently sidebar - I think that are just ones that happened
   * very recently & hopefully were teething. With the email ones I specifically
   * missed off checking channel is NULL when doing the update.
   *
   * @return bool
   */
  public function upgrade_4700(): bool {
    $sql = '
      UPDATE civicrm_value_1_gift_data_7 gift
      INNER JOIN civicrm_contribution current ON current.id = gift.entity_id
        AND current.contribution_recur_id IS NOT NULL
      INNER JOIN civicrm_contribution first
        ON first.contribution_recur_id = current.contribution_recur_id
        AND first.id < current.id
        AND first.receive_date < current.receive_date
      SET channel = "Recurring Gift"
      WHERE channel <> "Recurring Gift"
      AND gift.id BETWEEN %1 AND %2';
    $this->queueSQL($sql, [
      1 => [
        'value' => 3000000,
        'type' => 'Integer',
        'increment' => 250000,
      ],
      2 => [
        'value' => 3750000,
        'type' => 'Integer',
        'increment' => 250000,
      ],
    ]);
    return TRUE;
  }

  /**
   * Queue up an API4 update.
   *
   * @param string $entity
   * @param $action
   * @param array $params
   * @param array $incrementParameters
   */
  private function queueApi4(string $entity, $action, array $params = [], array $incrementParameters = []): void {
    $queue = new QueueHelper(\Civi::queue('wmf_data_upgrades', [
      'type' => 'Sql',
      'runner' => 'task',
      // This is kinda high but while debugging I was seeing sometime coworker
      // causing this to increment too quickly while debugging. This could be
      // due to the break point process but let's go with this for now.
      'retry_limit' => 100,
      'reset' => FALSE,
      'error' => 'abort',
    ]));
    $queue->setRunAs(['contactId' => 1]);
    $queue->api4($entity, $action, $params, $incrementParameters);
  }

  /**
   * Queue up an SQL update.
   *
   * @param string $sql
   * @param array $queryParameters
   *   Parameters to interpolate in with the keys
   *    - value
   *    - type (Integer, String, etc)
   *    - increment (optional, if present this is added to the value on each subsequent run.
   * @param array $doneCondition
   *   Criteria to determine when it is done. Currently supports one key
   *   - sql_returns_none - a query that should return empty when done.
   * @param int $weight
   */
  private function queueSQL(string $sql, array $queryParameters = [], $doneCondition = [], int $weight = 0): void {
    $queue = new QueueHelper(\Civi::queue('wmf_data_upgrades', [
      'type' => 'Sql',
      'runner' => 'task',
      // This is kinda high but while debugging I was seeing sometime coworker
      // causing this to increment too quickly while debugging. This could be
      // due to the break point process but let's go with this for now.
      'retry_limit' => 100,
      'reset' => FALSE,
      'error' => 'abort',
    ]));
    $queue->sql($sql, $queryParameters, empty($doneCondition) ? QueueHelper::ITERATE_UNTIL_DONE : QueueHelper::ITERATE_UNTIL_TRUE, $doneCondition, $weight);
  }

  /**
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function doAnnualWMFDonorRollover(): void {
    // The highest contact ID at the point at which the triggers were updated.
    $maxContactID = CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contact');
    $this->queueApi4('WMFDonor', 'update', [
      'values' => [
        'donor_segment_id' => '',
        'donor_status_id' => '',
      ],
      'where' => [
        ['id', 'BETWEEN', ['%1', '%2']],
      ],
    ],
      [
        1 => [
          'value' => 0,
          'type' => 'Integer',
          'increment' => 100000,
          'max' => $maxContactID,
        ],
        2 => [
          'value' => 100000,
          'type' => 'Integer',
          'increment' => 100000,
        ],
      ]);
  }

}
