<?php
use CRM_Forgetme_ExtensionUtil as E;
use Civi\Api4\ContributionTracking;

/**
 * Fredge.get API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_fredge_get_spec(&$spec) {
  $spec['contact_id']['api.required'] = 1;
  $spec['gateway']['title'] = ts('Gateway');
  $spec['order_id']['title'] = ts('Order ID');
  $spec['user_ip']['title'] = ts('IP Address');
  $spec['validation_action']['title'] = ts('Validation');
  $spec['payment_method']['title'] = ts('Payment Method');
  $spec['risk_score']['title'] = ts('Risk Score');
  $spec['date']['title'] = ts('Date');
}

/**
 * fredge.get API
 *
 * The point of this api is to get all data about a fredge with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_fredge_get($params) {
  $contributions = array_keys(civicrm_api3('Contribution', 'get', ['contact_id' => $params['contact_id'], 'return' => 'id'])['values']);
  if (empty($contributions)) {
    return civicrm_api3_create_success([], $params);
  }
  $result = ContributionTracking::get(FALSE)
    ->addSelect('id')
    ->addWhere('contribution_id', 'IN', $contributions)
    ->execute();
    $contributionTrackingIds = [];

  foreach($result as $tracking) {
    $contributionTrackingIds[] = $tracking['id'];
  }
  if (count($result) === 0) {
    return civicrm_api3_create_success([], $params);
  }

  $dbs = wmf_civicrm_get_dbs();
  $dbs->push('fredge');
  $paymentsFrauds = db_select( 'payments_fraud', 'payments_fraud')
    ->fields('payments_fraud')
    ->condition('contribution_tracking_id', array_values($contributionTrackingIds), 'IN')
    ->execute()
    ->fetchAllAssoc('id');

  $values = [];
  foreach ($paymentsFrauds as $paymentsFraud) {
    foreach ($paymentsFraud as $key => $value) {
      if ($key === 'user_ip') {
        $value = long2ip($value);
      }
      $values[$paymentsFraud->id][$key] = $value;
    }
  }
  $dbs->push('default');

  return civicrm_api3_create_success($values, $params);
}
