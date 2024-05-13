<?php
/**
 * Hook listeners for the wmf_civicrm module.
 *
 * TODO: move all hooks from wmf_civicrm.module here
 */

use Civi\WMFStatistic\PrometheusReporter;

/**
 * Listener for hook defined in CRM_SmashPig_Hook::smashpigOutputStats
 * @param array $stats
 */
function wmf_civicrm_civicrm_smashpig_stats($stats) {
  $metrics = [];
  foreach ($stats as $status => $count) {
    $lcStatus = strtolower($status);
    $metrics["recurring_smashpig_$lcStatus"] = $count;
  }
  $prometheusPath = \Civi::settings()->get('metrics_reporting_prometheus_path');
  $reporter = new PrometheusReporter($prometheusPath);
  $reporter->reportMetrics('recurring_smashpig', $metrics);
}
