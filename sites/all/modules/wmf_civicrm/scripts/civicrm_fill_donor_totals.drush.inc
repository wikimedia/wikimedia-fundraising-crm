<?php

/**
* Implementation of hook_drush_command()
*/
function civicrm_fill_donor_totals_drush_command() {
  $items = array();
  $items['civicrm-fill-donor-totals'] = array(
    'description' => 'Fill in missing totals info',
    'options' => array(
      'batch' => "Batch size",
      'threshold' => 'Threshold for aborting. If there are more than this number of contributions in the threshold period then abort.',
      'threshold_period' => 'Number of minutes in the threshold period',
      //'year' => "financial year end year (e.g 2018 for 2017-2018)"
    ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help().
 */
function fill_donor_totals_drush_help($section) {
  switch ( $section ) {
  case 'drush:civicrm-fill-donor-totals':
    return dt('Fill in missing totals info');
  }
}

/**
 * Fill donor totals data.
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_fill_donor_totals() {
  module_invoke('civicrm', 'initialize');

  require_once 'drush_threshholds.php';
  $threshold = (int) drush_get_option('threshold');
  if ($threshold) {
    $thresholdNumberOfMinutes = (int) drush_get_option('threshold_period', 5);
    if (_drush_civicrm_queue_is_backed_up($threshold, $thresholdNumberOfMinutes)) {
      return;
    }
  }
  $batch_size = (integer) drush_get_option('batch', 5000);
  /*
   Commenting all this out as I want to just 'got from the start' & I can look at targetting more recent ones
   in round 2 (assuming it's pretty slow)
  This is the year to filter by but any row we update we will do for all years.
  $batchYear = (int) drush_get_option('year', 2018);
  $batch_size = (integer) drush_get_option('batch', 5000);

  $maxId = CRM_Core_DAO::singleValueQuery("
    SELECT max(entity_id)
    FROM (
      SELECT entity_id FROM wmf_donor
      WHERE is_" . ($batchYear - 1) . "_donor = 1 AND total_" . ($batchYear - 1) . "_{$batchYear}  = 0
      ORDER BY entity_id LIMIT $batch_size
    ) as s
  ");

  if (empty($maxId)) {
    watchdog('civicrm_fill_donor_totals', 'Nothing to do on filling donor totals for %year (limit %batch )', array('%year' => $year, '%batch' => $batch_size), WATCHDOG_INFO);
    return;
  }
  watchdog('civicrm_fill_donor_totals', 'Filling donor totals for %year (limit %batch )', array('%year' => $year, '%batch' => $batch_size), WATCHDOG_INFO);
  */

  $maxId = CRM_Core_DAO::singleValueQuery("
    SELECT max(entity_id) FROM wmf_donor
      WHERE lifetime_usd_total > 0 AND largest_donation = 0
  ");
  if (!$maxId) {
    // Finished - let's not have a barrage of failmail when we get here. Once every 2 mins.....
    return;
  }
  $startID = $maxId - $batch_size;
  $endowmentFinancialType = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');

  // The query isn't compatible with full group by because only the date_of_largest_donation uses an aggregate in the
  // outer query - but all the rest of the aggregation is in the subquery so we can get away with it.
  CRM_Core_DAO::disableFullGroupByMode();
  CRM_Core_DAO::executeQuery("
  UPDATE wmf_donor INNER JOIN
    (
        SELECT
            # note that all totals are calculated in the totals subquery and only join values
            # in the main query. This is important as the left joins could catch more than one donation at
            # exactly the same time or more than one max donation amount & throw out the totals.
            COALESCE(earliest.total_amount, 0)   as first_donation_usd,
            MAX(largest.receive_date)  as date_of_largest_donation,
            totals.*

        FROM (
          SELECT MAX(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS largest_donation,
            MAX(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0))  AS endowment_largest_donation,
            SUM(COALESCE(total_amount, 0))   AS lifetime_including_endowment,
            SUM(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS lifetime_usd_total,
            SUM(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0))  AS endowment_lifetime_usd_total,
            MAX(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS last_donation_date,
            MAX(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL))  AS endowment_last_donation_date,
            MIN(IF(financial_type_id <> $endowmentFinancialType AND total_amount, receive_date, NULL)) AS first_donation_date,
            MIN(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL))  AS endowment_first_donation_date,
            COUNT(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS number_donations,
            COUNT( IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL))  AS endowment_number_donations,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2006-01-01' AND '2006-12-31 23:59:59', c.total_amount, 0), 0))  as total_2006,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2007-01-01' AND '2007-12-31 23:59:59', c.total_amount, 0), 0))  as total_2007,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2008-01-01' AND '2008-12-31 23:59:59', c.total_amount, 0), 0))  as total_2008,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2009-01-01' AND '2009-12-31 23:59:59', c.total_amount, 0), 0))  as total_2009,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2010-01-01' AND '2010-12-31 23:59:59', c.total_amount, 0), 0))  as total_2010,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2011-01-01' AND '2011-12-31 23:59:59', c.total_amount, 0), 0))  as total_2011,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2012-01-01' AND '2012-12-31 23:59:59', c.total_amount, 0), 0))  as total_2012,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2013-01-01' AND '2013-12-31 23:59:59', c.total_amount, 0), 0))  as total_2013,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2014-01-01' AND '2014-12-31 23:59:59', c.total_amount, 0), 0)) as total_2014,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2015-01-01' AND '2015-12-31 23:59:59', c.total_amount, 0), 0)) as total_2015,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2016-01-01' AND '2016-12-31 23:59:59', c.total_amount, 0), 0)) as total_2016,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2017-01-01' AND '2017-12-31 23:59:59', c.total_amount, 0), 0)) as total_2017,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0),0))  as total_2018,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0),  0))  as total_2019,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0), 0))  as total_2020,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0), 0))
              - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2017-01-01' AND '2017-12-31 23:59:59', c.total_amount, 0), 0))
              as change_2017_2018,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0), 0))
              - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0), 0)) 
              as change_2018_2019,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0), 0))
              - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0), 0))
              as change_2019_2020,
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2021-01-01' AND '2021-12-31 23:59:59', c.total_amount, 0),0))
              - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0), 0)) 
               as change_2020_2021,
            SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2018-07-01' AND '2019-06-30 23:59:59', c.total_amount, 0), 0))  as endowment_total_2018_2019,
            SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2019-07-01' AND '2020-06-30 23:59:59', c.total_amount, 0), 0))  as endowment_total_2019_2020,
            SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2020-07-01' AND '2021-06-30 23:59:59', c.total_amount, 0), 0))  as endowment_total_2020_2021,
            SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0), 0))  as endowment_total_2018,
            SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0), 0))  as endowment_total_2019,
            SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0), 0))  as endowment_total_2020,

            contact_id
  FROM civicrm_contribution c
  #ok our batches might wind up less than our batch size but we can adjust the batch size for speed
  WHERE contact_id > $startID AND contact_id <= $maxId  AND contribution_status_id = 1
  GROUP BY contact_id
             ) as totals

  LEFT JOIN civicrm_contribution earliest
            USE INDEX (FK_civicrm_contribution_contact_id)
            ON earliest.contact_id = totals.contact_id
 AND earliest.receive_date = totals.first_donation_date
 AND earliest.contribution_status_id = 1
 AND earliest.total_amount > 0
 AND (earliest.trxn_id NOT LIKE 'RFD %' OR earliest.trxn_id IS NULL)

  LEFT JOIN civicrm_contribution largest
            USE INDEX (FK_civicrm_contribution_contact_id)
            ON largest.contact_id = totals.contact_id
 AND largest.total_amount = totals.largest_donation
 AND largest.contribution_status_id = 1
 AND largest.total_amount > 0
 AND (largest.trxn_id NOT LIKE 'RFD %' OR largest.trxn_id IS NULL)

    GROUP BY contact_id
    ) as updates ON wmf_donor.entity_id = updates.contact_id

  SET
    wmf_donor.first_donation_usd = updates.first_donation_usd,
    wmf_donor.largest_donation = updates.largest_donation,
    wmf_donor.date_of_largest_donation = updates.date_of_largest_donation,
    wmf_donor.lifetime_usd_total = updates.lifetime_usd_total,
    wmf_donor.last_donation_date = updates.last_donation_date,
    wmf_donor.first_donation_date = updates.first_donation_date,
    wmf_donor.number_donations = updates.number_donations,
    wmf_donor.endowment_largest_donation = updates.endowment_largest_donation,
    wmf_donor.lifetime_including_endowment = updates.lifetime_including_endowment,
    wmf_donor.endowment_lifetime_usd_total = updates.endowment_lifetime_usd_total,
    wmf_donor.endowment_last_donation_date = updates.endowment_last_donation_date,
    wmf_donor.endowment_first_donation_date = updates.endowment_first_donation_date,
    wmf_donor.endowment_number_donations = updates.endowment_number_donations,
    wmf_donor.total_2006 = updates.total_2006,
    wmf_donor.total_2007 = updates.total_2007,
    wmf_donor.total_2008 = updates.total_2008,
    wmf_donor.total_2009 = updates.total_2009,
    wmf_donor.total_2010 = updates.total_2010,
    wmf_donor.total_2011 = updates.total_2011,
    wmf_donor.total_2012 = updates.total_2012,
    wmf_donor.total_2013 = updates.total_2013,
    wmf_donor.total_2014 = updates.total_2014,
    wmf_donor.total_2015 = updates.total_2015,
    wmf_donor.total_2016 = updates.total_2016,
    wmf_donor.total_2017 = updates.total_2017,
    wmf_donor.total_2018 = updates.total_2018,
    wmf_donor.total_2019 = updates.total_2019,
    wmf_donor.total_2020 = updates.total_2020,
    wmf_donor.change_2017_2018 = updates.change_2017_2018,
    wmf_donor.change_2019_2020 = updates.change_2019_2020,
    wmf_donor.change_2018_2019 = updates.change_2018_2019,
    wmf_donor.change_2020_2021 = updates.change_2020_2021,
    wmf_donor.endowment_total_2018_2019 = updates.endowment_total_2018_2019,
    wmf_donor.endowment_total_2018 = updates.endowment_total_2018,
    wmf_donor.endowment_total_2019_2020 = updates.endowment_total_2019_2020,
    wmf_donor.endowment_total_2019 = updates.endowment_total_2019,
    wmf_donor.endowment_total_2020_2021 = updates.endowment_total_2020_2021,
    wmf_donor.endowment_total_2020 = updates.endowment_total_2020
  ");

  // not running the query takes > 20 sec but leaving in for reference if
  drush_print(dt("civicrm_fill_donor_totals : Ran successfully"));

}
