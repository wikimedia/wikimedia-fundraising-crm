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
      'start_id' => 'Contact ID to start from',
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
  $startID = (integer) drush_get_option('start_id');
  if (!$startID) {
    throw new CRM_Core_Exception('currently start id is required');
  }
  $maxId = $startID + $batch_size;

  // The query isn't compatible with full group by because only the date_of_largest_donation uses an aggregate in the
  // outer query - but all the rest of the aggregation is in the subquery so we can get away with it.
  CRM_Core_DAO::disableFullGroupByMode();
  CRM_Core_DAO::executeQuery(_civicrm_fill_donor_trigger_sql($startID, $maxId));

  // not running the query takes > 20 sec but leaving in for reference if
  drush_print(dt("civicrm_fill_donor_totals : Ran successfully parsing from $startID to $maxId "));

}

function _civicrm_fill_donor_trigger_sql($startID, $maxID) {
  $endowmentFinancialType = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');

  return "
  INSERT INTO wmf_donor (
    #### Begin change from stored trigger
    # entity_id,
    #### End change from stored trigger

    last_donation_currency, last_donation_amount, last_donation_usd,
    first_donation_usd,
     ### Begin re-include largest date
    date_of_largest_donation,
    ### End re-include largest date

    #### Begin change from stored trigger
    entity_id,
    #### End change from stored trigger

    largest_donation, endowment_largest_donation, lifetime_including_endowment,
    lifetime_usd_total, endowment_lifetime_usd_total,
    last_donation_date, endowment_last_donation_date, first_donation_date,
    endowment_first_donation_date, number_donations,
    endowment_number_donations, total_2006_2007, total_2006, total_2007_2008, total_2007, total_2008_2009, total_2008, total_2009_2010, total_2009, total_2010_2011, total_2010, total_2011_2012, total_2011, total_2012_2013, total_2012, total_2013_2014, total_2013, total_2014_2015, total_2014, total_2015_2016, total_2015, total_2016_2017, total_2016, total_2017_2018, total_2017, change_2017_2018, total_2018_2019, total_2018, endowment_total_2018_2019, endowment_total_2018, change_2018_2019, total_2019_2020, total_2019, endowment_total_2019_2020, endowment_total_2019, change_2019_2020, total_2020_2021, total_2020, endowment_total_2020_2021, endowment_total_2020, change_2020_2021
  )

    SELECT
      # note that all totals are calculated in the totals subquery and only join values
      # in the main query. This is important as the left joins could catch more than one donation at
      # exactly the same time or more than one max donation amount & throw out the totals.

      #### Begin change from stored trigger
      # OLD.contact_id as entity_id,
      #### End change from stored trigger
      COALESCE(x.original_currency, latest.currency) as last_donation_currency,
      COALESCE(x.original_amount, latest.total_amount, 0) as last_donation_amount,
      COALESCE(latest.total_amount, 0) as last_donation_usd,
      COALESCE(earliest.total_amount, 0) as first_donation_usd,
      ### Begin re-include largest date
      MAX(largest.receive_date) as date_of_largest_donation,
      ### End re-include largest date
      totals.*

    FROM (
      SELECT
        #### Begin change from stored trigger
        c.contact_id,
        #### End change from stored trigger
        MAX(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS largest_donation,
        MAX(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_largest_donation,
        SUM(COALESCE(total_amount, 0)) AS lifetime_including_endowment,
        SUM(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS lifetime_usd_total,
        SUM(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_lifetime_usd_total,
        MAX(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS last_donation_date,
        MAX(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_last_donation_date,
        MIN(IF(financial_type_id <> $endowmentFinancialType AND total_amount, receive_date, NULL)) AS first_donation_date,
        MIN(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_first_donation_date,
        COUNT(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS number_donations,
        COUNT(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_number_donations,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2006-07-01' AND '2007-06-30 23:59:59', c.total_amount, 0),0)) as total_2006_2007,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2006-01-01' AND '2006-12-31 23:59:59', c.total_amount, 0),0)) as total_2006,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2007-07-01' AND '2008-06-30 23:59:59', c.total_amount, 0),0)) as total_2007_2008,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2007-01-01' AND '2007-12-31 23:59:59', c.total_amount, 0),0)) as total_2007,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2008-07-01' AND '2009-06-30 23:59:59', c.total_amount, 0),0)) as total_2008_2009,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2008-01-01' AND '2008-12-31 23:59:59', c.total_amount, 0),0)) as total_2008,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2009-07-01' AND '2010-06-30 23:59:59', c.total_amount, 0),0)) as total_2009_2010,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2009-01-01' AND '2009-12-31 23:59:59', c.total_amount, 0),0)) as total_2009,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2010-07-01' AND '2011-06-30 23:59:59', c.total_amount, 0),0)) as total_2010_2011,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2010-01-01' AND '2010-12-31 23:59:59', c.total_amount, 0),0)) as total_2010,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2011-07-01' AND '2012-06-30 23:59:59', c.total_amount, 0),0)) as total_2011_2012,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2011-01-01' AND '2011-12-31 23:59:59', c.total_amount, 0),0)) as total_2011,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2012-07-01' AND '2013-06-30 23:59:59', c.total_amount, 0),0)) as total_2012_2013,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2012-01-01' AND '2012-12-31 23:59:59', c.total_amount, 0),0)) as total_2012,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2013-07-01' AND '2014-06-30 23:59:59', c.total_amount, 0),0)) as total_2013_2014,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2013-01-01' AND '2013-12-31 23:59:59', c.total_amount, 0),0)) as total_2013,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2014-07-01' AND '2015-06-30 23:59:59', c.total_amount, 0),0)) as total_2014_2015,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2014-01-01' AND '2014-12-31 23:59:59', c.total_amount, 0),0)) as total_2014,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2015-07-01' AND '2016-06-30 23:59:59', c.total_amount, 0),0)) as total_2015_2016,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2015-01-01' AND '2015-12-31 23:59:59', c.total_amount, 0),0)) as total_2015,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2016-07-01' AND '2017-06-30 23:59:59', c.total_amount, 0),0)) as total_2016_2017,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2016-01-01' AND '2016-12-31 23:59:59', c.total_amount, 0),0)) as total_2016,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2017-07-01' AND '2018-06-30 23:59:59', c.total_amount, 0),0)) as total_2017_2018,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2017-01-01' AND '2017-12-31 23:59:59', c.total_amount, 0),0)) as total_2017,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2017-01-01' AND '2017-12-31 23:59:59', c.total_amount, 0),0)) as change_2017_2018,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2018-07-01' AND '2019-06-30 23:59:59', c.total_amount, 0),0)) as total_2018_2019,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0),0)) as total_2018,
        SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2018-07-01' AND '2019-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_2018_2019,
        SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_2018,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2018-01-01' AND '2018-12-31 23:59:59', c.total_amount, 0),0)) as change_2018_2019,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2019-07-01' AND '2020-06-30 23:59:59', c.total_amount, 0),0)) as total_2019_2020,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0),0)) as total_2019,
        SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2019-07-01' AND '2020-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_2019_2020,
        SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_2019,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2019-01-01' AND '2019-12-31 23:59:59', c.total_amount, 0),0)) as change_2019_2020,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2020-07-01' AND '2021-06-30 23:59:59', c.total_amount, 0),0)) as total_2020_2021,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0),0)) as total_2020,
        SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2020-07-01' AND '2021-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_2020_2021,
        SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_2020,
        SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2021-01-01' AND '2021-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '2020-01-01' AND '2020-12-31 23:59:59', c.total_amount, 0),0)) as change_2020_2021
      FROM civicrm_contribution c
      USE INDEX(FK_civicrm_contribution_contact_id)

      #### Begin change from stored trigger
      # WHERE contact_id = OLD.contact_id
      WHERE contact_id >= $startID AND contact_id <= $maxID
      AND contribution_status_id = 1
      AND (trxn_id NOT LIKE 'RFD' OR trxn_id IS NULL)
      GROUP BY contact_id
      #### End change from stored trigger

    ) as totals
  LEFT JOIN civicrm_contribution latest
    USE INDEX(FK_civicrm_contribution_contact_id)

    #### Begin change from stored trigger
    #ON latest.contact_id = OLD.contact_id
    ON latest.contact_id = totals.contact_id
    #### End change from stored trigger

AND latest.receive_date = totals.last_donation_date
AND latest.contribution_status_id = 1
AND latest.total_amount > 0
AND (latest.trxn_id NOT LIKE 'RFD %' OR latest.trxn_id IS NULL)
    AND latest.financial_type_id <> $endowmentFinancialType
  LEFT JOIN wmf_contribution_extra x ON x.entity_id = latest.id

  LEFT JOIN civicrm_contribution earliest
    USE INDEX(FK_civicrm_contribution_contact_id)
    #### Begin change from stored trigger
    # ON earliest.contact_id = OLD.contact_id
    ON earliest.contact_id = totals.contact_id
    #### End change from stored trigger

AND earliest.receive_date = totals.first_donation_date
AND earliest.contribution_status_id = 1
AND earliest.total_amount > 0
AND (earliest.trxn_id NOT LIKE 'RFD %' OR earliest.trxn_id IS NULL)

  ### Begin re-include largest date
    LEFT JOIN civicrm_contribution largest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ### begin alter for backfill
    ON largest.contact_id = totals.contact_id
    ### end alter for backfill
    AND largest.total_amount = totals.largest_donation
    AND largest.contribution_status_id = 1
    AND largest.total_amount > 0
    AND (largest.trxn_id NOT LIKE 'RFD %' OR largest.trxn_id IS NULL)

  GROUP BY totals.contact_id
  ### End re-include largest date

  ON DUPLICATE KEY UPDATE
    last_donation_currency = VALUES(last_donation_currency),
    last_donation_amount = VALUES(last_donation_amount),
    last_donation_usd = VALUES(last_donation_usd),
    first_donation_usd = VALUES(first_donation_usd),
    largest_donation = VALUES(largest_donation),
    ### Begin -re-intro largest-date
    date_of_largest_donation = VALUES(date_of_largest_donation),
    ### End -re-intro largest-date
    lifetime_usd_total = VALUES(lifetime_usd_total),
    last_donation_date = VALUES(last_donation_date),
    first_donation_date = VALUES(first_donation_date),
    number_donations = VALUES(number_donations),
    endowment_largest_donation = VALUES(endowment_largest_donation),
    lifetime_including_endowment = VALUES(lifetime_including_endowment),
    endowment_lifetime_usd_total = VALUES(endowment_lifetime_usd_total),
    endowment_last_donation_date = VALUES(endowment_last_donation_date),
    endowment_first_donation_date = VALUES(endowment_first_donation_date),
    endowment_number_donations = VALUES(endowment_number_donations),
    total_2006_2007 = VALUES(total_2006_2007),total_2006 = VALUES(total_2006),total_2007_2008 = VALUES(total_2007_2008),total_2007 = VALUES(total_2007),total_2008_2009 = VALUES(total_2008_2009),total_2008 = VALUES(total_2008),
    total_2009_2010 = VALUES(total_2009_2010),total_2009 = VALUES(total_2009),total_2010_2011 = VALUES(total_2010_2011),total_2010 = VALUES(total_2010),total_2011_2012 = VALUES(total_2011_2012),total_2011 = VALUES(total_2011),
    total_2012_2013 = VALUES(total_2012_2013),total_2012 = VALUES(total_2012),total_2013_2014 = VALUES(total_2013_2014),total_2013 = VALUES(total_2013),total_2014_2015 = VALUES(total_2014_2015),
    total_2014 = VALUES(total_2014),total_2015_2016 = VALUES(total_2015_2016),total_2015 = VALUES(total_2015),total_2016_2017 = VALUES(total_2016_2017),
    total_2016 = VALUES(total_2016),total_2017_2018 = VALUES(total_2017_2018),total_2017 = VALUES(total_2017),change_2017_2018 = VALUES(change_2017_2018),total_2018_2019 = VALUES(total_2018_2019),total_2018 = VALUES(total_2018),endowment_total_2018_2019 = VALUES(endowment_total_2018_2019),endowment_total_2018 = VALUES(endowment_total_2018),change_2018_2019 = VALUES(change_2018_2019),total_2019_2020 = VALUES(total_2019_2020),total_2019 = VALUES(total_2019),endowment_total_2019_2020 = VALUES(endowment_total_2019_2020),endowment_total_2019 = VALUES(endowment_total_2019),change_2019_2020 = VALUES(change_2019_2020),total_2020_2021 = VALUES(total_2020_2021),total_2020 = VALUES(total_2020),endowment_total_2020_2021 = VALUES(endowment_total_2020_2021),endowment_total_2020 = VALUES(endowment_total_2020),change_2020_2021 = VALUES(change_2020_2021);
";
}
