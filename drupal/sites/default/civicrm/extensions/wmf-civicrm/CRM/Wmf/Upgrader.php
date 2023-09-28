<?php /** @noinspection PhpUnused */

use Civi\Api4\CustomField;
use Civi\Api4\OptionGroup;
use Civi\Api4\WMFConfig;
use Civi\Api4\OptionValue;
use Civi\Queue\QueueHelper;
use Civi\WMFHooks\CalculatedData;

/**
 * Collection of upgrade steps.
 */
class CRM_Wmf_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \API_Exception
   */
  public function install(): void {
    // This is a temporary static to allow us to add segment fields on install
    // on our dev sites while we are in this half-way state of not having the
    // fields on live yet.
    \Civi::$statics['is_install_mode'] = TRUE;
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

    $this->syncGeocoders();
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
   *
   * In our case we should be confident the wmf donor table exists before adding
   * an index.
   */
  public function postInstall(): void {

    /* Add combined index on entity_id and lifetime_usd_total on wmf_donor table.
     *
     * In testing this made a significant difference when filtering for donors with
     * giving over x - which is a common usage.
     *
    */
    CRM_Core_DAO::executeQuery('ALTER TABLE wmf_donor ADD INDEX entity_total (entity_id, lifetime_usd_total)');
    CRM_Core_BAO_SchemaHandler::safeRemoveFK('civicrm_activity', 'FK_civicrm_activity_original_id');
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
   * Run sql to reset the accidentally cancelled ideal recurring contributions during the token update when switching from Adyen to
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
   * @return true
   * remove the trailing number from email
   */
  public function upgrade_4219(): bool {
    // replace c0m to com
    CRM_Core_DAO::executeQuery( "update civicrm_email set email = replace(email,'c0m' , 'com'), on_hold=0, hold_date=NULL where email like ('%@%.c0m')" );
    // remove trailing number from domain if email contains '@'
    CRM_Core_DAO::executeQuery( "update civicrm_email set email = left( email, length(email) - length(REGEXP_SUBSTR(reverse(email),'[0-9]+'))), on_hold=0, hold_date=NULL where SUBSTRING(email, length(email), 1) REGEXP '[0-9]' and email LIKE '%@%.%'" );
    return TRUE;
  }

  /**
   * Remove unused option values.
   *
   * @return true
   * @throws \API_Exception
   */
  public function upgrade_4220(): bool {
    $optionGroups = OptionGroup::get(FALSE)
      ->addWhere('name', 'IN', ['individual_prefix', 'individual_suffix', 'languages'])
      ->addSelect('id', 'name')->execute()->indexBy('name');

    $prefixOptions = $this->getInUseOptions('prefix_id');
    CRM_Core_DAO::executeQuery('
      DELETE FROM civicrm_option_value
      WHERE option_group_id = ' . $optionGroups['individual_prefix']['id'] . '
        AND value NOT IN (' . implode(',', $prefixOptions) .')');

    $suffixOptions = $this->getInUseOptions('suffix_id');
    CRM_Core_DAO::executeQuery('
      DELETE FROM civicrm_option_value
      WHERE option_group_id = ' . $optionGroups['individual_suffix']['id'] . '
        AND value NOT IN (' . implode(',', $suffixOptions) .')');

    $languages = $this->getInUseOptions('preferred_language');
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_option_value
      WHERE option_group_id = {$optionGroups['languages']['id']}
        -- extra cautionary check - only ones with cruft-for-labels
        AND label = name
        AND name NOT IN ('" . implode("', '", $languages) ."')");

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
  public function upgrade_4250() : bool {
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
  public function upgrade_4253() : bool {
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
  public function upgrade_4260() : bool {
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
  public function upgrade_4284() : bool {
    // Segment 1 (GROUP ID: 1685 Gift of $50+ cumulatively in the last 5 years (Jan 1, 2018 - today)
    $segmentOneGroupId = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_group WHERE title = 'Data Axle $50+ cumulative gift since 2018 T336891'");
    // below for local test, otherwise civicrm production already have this id as 1685 created
    if(!$segmentOneGroupId) {
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
    AND a.is_opt_out = 0;") ;
    $segmentOneContactIds = [];
    while ($segmentOneContacts->fetch()) {
      if( isset($segmentOneContacts->id) ) {
        $segmentOneContactIds[] = $segmentOneContacts->id;
      }
    }
    $this->insertContacts($segmentOneContactIds, $segmentOneGroupId);

    // Segment 6 (GROUP ID: 1686) Given at least $20+ each year for the last 3 years
    $segmentSixGroupId = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_group WHERE title = 'Data Axle $20+ each year since 2020 T336891'");
    // below for local test, otherwise civicrm production already have this id as 1686 created
    if(!$segmentSixGroupId) {
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
      if( isset($segmentSixContacts->id) ) {
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
   * @return bool
   */
  public function upgrade_4286() : bool {
    // NEW Segment 1 (GROUP ID: 1685 Gift at least $50+ each year in the last 5 years (Jan 1, 2018 - today)
    $segmentOneGroupId = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_group WHERE title = 'Data Axle $50+ each year since 2018 T336891'");
    // below for local test, otherwise civicrm production already have this id as 1685 created
    if(!$segmentOneGroupId) {
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
      if( isset($segmentOneContacts->id) ) {
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
    if( count($contacts) > 0 ) {
      $sql = 'INSERT INTO civicrm_group_contact (`group_id`, `contact_id`, `status`) VALUES ';
      foreach ($contacts as $contact_id) {
        $sql .= '(' .$groupId .','. $contact_id. ', "Added"),';
      }
      $sql = substr_replace($sql ,';', -1);
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
  public function upgrade_4300() : bool {
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
      'all_funds_change_2018_2019',
      'all_funds_change_2019_2020',
      'all_funds_total_2018_2019',
      'all_funds_total_2019_2020',
      'endowment_total_2018',
      'endowment_total_2018_2019',
      'endowment_total_2019',
      'endowment_total_2019_2020',
    ], 'wmf_donor');
    return TRUE;
  }

  /**
   * Add new segment & wmf donor fields
   *
   * Bug: T331919 & T339067
   *
   * @return bool
   * @throws \API_Exception
   */
  public function upgrade_4305() : bool {
    // This is a temporary static which forces the new segment fields to be included.
    // In theory we can remove the whole isSegmentReady() function once this upgrade
    // has run - although it might be nice to see the requirements settle down
    // a little first in case we can't include them in triggers & want to re-purpose that function
    \Civi::$statics['is_install_mode'] = TRUE;
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
   * @throws \API_Exception
   */
  public function upgrade_4310() : bool {
    $fieldFinder = new CalculatedData();
    $fields = $fieldFinder->getWMFDonorFields();
    foreach(['donor_segment_id', 'donor_status_id'] as $fieldName) {
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
  public function upgrade_4325() : bool {
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
  public function upgrade_4330() : bool {
    // To see what is found swap the first part with
    // SELECT a.id as `id`, a.contribution_recur_id, a.receive_date, a.contact_id FROM
    $sql = '
     UPDATE
     civicrm_contribution a
       -- join to previous contributions with the same contribution recur id which happened before the given one
       LEFT JOIN civicrm_contribution c2 ON c2.contribution_recur_id = a.contribution_recur_id AND c2.receive_date < a.receive_date
       LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
       ON a.id = Gift_Data.entity_id
     SET a.financial_type_id = 31,
         Gift_Data.campaign = "Online Gift"
     WHERE `a`.`receive_date` > "20230701000000"
       AND `a`.`contribution_recur_id` > 0
       -- financial type not already of recurring type
       AND `a`.`financial_type_id` NOT IN(31, 32)
       AND `a`.`is_test` = 0
       AND c2.id IS NULL
        LIMIT 100';

    $this->queueSQL($sql);

    $sql = '
     UPDATE
     civicrm_contribution a
       -- join to previous contributions with the same contribution recur id which happened before the given one
       LEFT JOIN civicrm_contribution c2 ON c2.contribution_recur_id = a.contribution_recur_id
       AND c2.receive_date < a.receive_date
       LEFT JOIN `civicrm_value_1_gift_data_7` Gift_Data
       ON a.id = Gift_Data.entity_id
     SET a.financial_type_id = 32,
         Gift_Data.campaign = "Online Gift"
     WHERE `a`.`receive_date` > "20230701000000"
       AND `a`.`contribution_recur_id` > 0
       -- financial type not already of recurring type
       AND `a`.`financial_type_id` NOT IN(31, 32)
       AND `a`.`is_test` = 0
       AND c2.id IS NOT NULL
        LIMIT 100';
    $this->queueSQL($sql);
    return TRUE;
  }

  /**
   * @param string $sql
   */
  private function queueSQL(string $sql): void {
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
    $queue->sql($sql, [], QueueHelper::ITERATE_UNTIL_DONE);
  }

}
