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
      'year' => "financial year end year (e.g 2018 for 2017-2018)"
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
  // This is the year to filter by but any row we update we will do for all years.
  $batchYear = (int) drush_get_option('year', 2018);
  $batch_size = (integer) drush_get_option('batch', 5000);
  $updates = [];

  for ($year = WMF_MIN_ROLLUP_YEAR; $year <= WMF_MAX_ROLLUP_YEAR; $year++) {
    $nextYear = $year +1;
    $selects[] = "SUM(COALESCE(IF(receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as total_{$year}_{$nextYear}";
    $updates [] = "donor.total_{$year}_{$nextYear} = totals.total_{$year}_{$nextYear}";
  }

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

  CRM_Core_DAO::executeQuery("
     UPDATE wmf_donor donor
     LEFT JOIN civicrm_contribution c ON c.contact_id = donor.entity_id
     INNER JOIN (
       SELECT d.entity_id, " . implode(',', $selects) . "
       FROM  wmf_donor d
       LEFT JOIN civicrm_contribution c ON c.contact_id = d.entity_id
       WHERE d.entity_id <= $maxId
       AND is_" . ($batchYear - 1) . "_donor = 1 AND total_" . ($batchYear - 1) . "_{$batchYear}  = 0
       GROUP BY d.entity_id
     ) as totals ON totals.entity_id = donor.entity_id
     SET " . implode(',', $updates)  . "
   ");

  // not running the query takes > 20 sec but leaving in for reference if
  drush_print(dt("civicrm_fill_donor_totals :
    Filled to %maxId  - to get remaining rows for this year run :
    SELECT COUNT(entity_id) FROM wmf_donor WHERE is_" . ($batchYear - 1) . "_donor = 1 AND total_" . ($batchYear - 1) . "_{$batchYear}  = 0", ['%maxId' => $maxId]));

}
