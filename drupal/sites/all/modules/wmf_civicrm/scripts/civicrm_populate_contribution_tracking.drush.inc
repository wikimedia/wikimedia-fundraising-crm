<?php
/**
 * Implementation of hook_drush_command()
 */
function civicrm_populate_contribution_tracking_drush_command(): array {
  return [
    'civicrm-populate-contribution-tracking' => [
      'description' => 'One off population of new civicrm_contribution_tracking, delete when done',
      'options' => [
        'batch' => "Batch size",
        'threshold' => 'Threshold for aborting. If there are more than this number of contributions in the threshold period then abort.',
        'threshold_period' => 'Number of minutes in the threshold period',
      ],
    ],
  ];
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_populate_contribution_tracking_drush_help($section) {
  switch ($section) {
    case 'drush:civicrm-populate-contribution-tracking':
      return dt( 'One off batched table population');
  }
}

/**
 * Merge a batch of contacts.
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_populate_contribution_tracking() {
  require_once 'drush_threshholds.php';
  module_invoke('civicrm', 'initialize');
  global $databases;
  $drupalDB = $databases['default']['default']['database'];
  $threshold = (int) drush_get_option('threshold');
  if ($threshold) {
    $thresholdNumberOfMinutes = (int) drush_get_option('threshold_period', 5);
    if (_drush_civicrm_queue_is_backed_up($threshold, $thresholdNumberOfMinutes)) {
      return;
    }
  }
  $batch_size = (integer) drush_get_option('batch',15000);
  $paymentMethods = CRM_Wmf_BAO_ContributionTracking::buildOptions('payment_method_id', 'validate');

  // Regex to retrieve values between the dots. mariaDB is a bit less functional than mysql on REGEX - REGEXP_REPLACE is weird but works.
  // https://stackoverflow.com/questions/53119343/regex-replace-function-in-cases-of-no-match-1-returns-full-line-instead-of-null end in |.*
  $utmSourceRegex = "'([^\.]*)\.([^\.]*)\.([^\.]+)\.?([^\.]+)?|.*'";

  $crazyPaymentMethodCases = [];
  $recurringTypes = [];
  foreach ($paymentMethods as $paymentMethodID => $paymentMethodName) {
    $crazyPaymentMethodCases[] = "WHEN '$paymentMethodName' THEN $paymentMethodID";
    $crazyPaymentMethodCases[] = "WHEN 'r$paymentMethodName' THEN $paymentMethodID";
    $recurringTypes[] = 'r' . $paymentMethodName;
  }
  $paymentMethodStatement = "( CASE REGEXP_REPLACE(t.utm_source, $utmSourceRegex,'\\\\3') " . implode("\n", $crazyPaymentMethodCases) . ' END ) as payment_method_id';
  $recurringTypesStatement = "( IF (REGEXP_REPLACE(t.utm_source, $utmSourceRegex,'\\\\3') IN ('" . implode("','", $recurringTypes) . "'), 1 ,0)) as is_recurring";
  $recurringChoiceStatement = "( IF (REGEXP_REPLACE(t.utm_source, $utmSourceRegex,'\\\\3') IN ('" . implode("','", $recurringTypes) . "'), IF(t.utm_key RLIKE 'Upsell' OR t.utm_key RLIKE 'donateMC_1',1,2),NULL)) as recurring_choice_id";
  $paymentSubMethods = [
    238 => 'upi',
    235 => 'netbanking',
    236 => 'paytmwallet',
    9 => 'rtbt_ideal',
  ];
  $crazyPaymentMethodCases = [];
  foreach ($paymentSubMethods as $paymentMethodID => $paymentMethodName) {
    if (!$paymentMethodName) {
      continue;
    }
    $crazyPaymentMethodCases[] = "WHEN '$paymentMethodName' THEN $paymentMethodID";
  }
  $paymentSubMethodStatement = "( CASE REGEXP_REPLACE(t.utm_source, $utmSourceRegex,'\\\\4') " . implode("\n", $crazyPaymentMethodCases) . ' END ) as payment_sub_method_id';
  $query = "
  INSERT INTO civicrm_contribution_tracking
(id, contribution_id, currency, amount, usd_amount, referrer, utm_medium, utm_campaign, utm_key,utm_source,
gateway,appeal,payments_form_variant,
banner,landing_page, payment_method_id, payment_submethod_id,is_recurring,recurring_choice_id,device_type_id, banner_size_id,
 is_test_variant, banner_variant,is_pay_fee, mailing_identifier,language, country, tracking_date)
SELECT
t.id,
cc.id,
SUBSTRING(t.form_amount, 1, LOCATE(' ', form_amount)-1) AS original_currency,
SUBSTRING(t.form_amount, LOCATE(' ', form_amount)+1) AS original_amount,
t.usd_amount,
t.referrer,
t.utm_medium,
t.utm_campaign,
t.utm_key,
t.utm_source,
SUBSTRING(t.payments_form, 1, LOCATE('.', payments_form)-1) AS gateway,
IF(LOCATE('v=',payments_form),NULL, SUBSTRING(t.payments_form, LOCATE('.', payments_form)+1)) as appeal,
IF(LOCATE('v=',payments_form), SUBSTRING(t.payments_form, LOCATE('.', payments_form)+3),NULL) as payments_form_variant,

REGEXP_REPLACE(t.utm_source, $utmSourceRegex,'\\\\1') as landing_page,
REGEXP_REPLACE(t.utm_source, $utmSourceRegex,'\\\\2') as banner,
$paymentMethodStatement,
$paymentSubMethodStatement,
$recurringTypesStatement,
$recurringChoiceStatement,
IF(t.utm_source RLIKE '_dsk_',1,IF(t.utm_source RLIKE '_m_',2,NULL)) as device_type_id,
IF(t.utm_source RLIKE '_lg_',1,IF(t.utm_source RLIKE '_sm_',2,3)) as banner_size_id,
IF(t.utm_source RLIKE '_cnt_',1,0)  as is_test_variant,
SUBSTRING_INDEX(SUBSTRING_INDEX(REGEXP_REPLACE(t.utm_source, $utmSourceRegex,'\\\\2'), '_', 8), '_', -1) as banner_variant,
 IF(t.utm_key RLIKE 'ptf_1',1,0) as is_pay_fee,
CASE
WHEN LEFT(t.utm_source,10) IN ('70761231.d')
 THEN 'sp70761231'
 ELSE IF(LEFT(t.utm_source,2) = 'sp', LEFT(t.utm_source,10), NULL)
 END AS mailing_identifier,
t.language,
t.country,
STR_TO_DATE(t.ts, '%Y%m%d%H%i%s') as tracking_date
FROM (
SELECT ct.*
-- note we need to use a php variable rather than the query substition or it puts quotes around it
FROM $drupalDB.`contribution_tracking` as ct
LEFT JOIN civicrm_contribution_tracking civi_table ON civi_table.id = ct.id
WHERE civi_table.id IS NULL
ORDER BY ct.id DESC
LIMIT %1
) AS t
LEFT OUTER JOIN civicrm_contribution cc ON t.contribution_id = cc.id
";
  \Civi::log('wmf')->info('running query {query}', ['query' => CRM_Core_DAO::composeQuery($query, [1 => [$batch_size, 'Int']])]);
  CRM_Core_DAO::executeQuery($query, [1 => [$batch_size, 'Int']]);
  \Civi::log('wmf')->info('processed {batch_size} contribution tracking rows', ['batch_size' => $batch_size]);
}