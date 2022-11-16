<?php
use SmashPig\PaymentProviders\IDeleteDataProvider;

function _civicrm_api3_contribution_showme_spec(array &$spec): void {
  // Need to specify either the contribution ID in the 'id' parameter
  // (this is done during the contact_showme call) or the contact id in
  // the contact_id parameter (this is done in the contact_forgetme call).
  $spec['id']['type'] = CRM_Utils_Type::T_INT;
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
}

function civicrm_api3_contribution_showme($params) {
  if (empty($params['id']) && empty($params['contact_id'])) {
    throw new CRM_Core_Exception('Need at least one of contact_id or id');
  }
  $customFieldMap = _civicrm_api3_contribution_forgetme_getcustomfields();
  // The next 'get' call seems to throw an error on contacts with no contributions (when it gets to
  // ContributionSoft::getSoftCreditContributionFields), so we check the count first and return if 0.
  $contribCount = civicrm_api3_contribution_getcount($params);
  if ($contribCount === 0) {
    return civicrm_api3_create_success([], $params);
  }
  $getParams = $params + [
    // Add a trivial where condition on the contribution table to work around an API3 bug
    // that adds a phantom row when querying across multiple contact_ids.
    // See https://phabricator.wikimedia.org/T322796#8398570
    'total_amount' => ['IS NOT NULL' => 1],
    'return' => [
      $customFieldMap['gateway'],
      $customFieldMap['gateway_txn_id'],
      $customFieldMap['original_amount'],
      $customFieldMap['original_currency'],
      'currency',
      'receive_date',
      'total_amount',
      'trxn_id'
    ]
  ];
  $contributions = civicrm_api3_contribution_get($getParams)['values'];
  $return = civicrm_api3_create_success($contributions, $params);
  foreach (civicrm_api3_contribution_get($getParams)['values'] as $id => $contribution) {
    // TODO: for installations without WMF custom fields, we could possibly get the
    // gateway name from civicrm_financial_trxn linked via civicrm_entity_financial_trxn.
    // For our current purposes, we expect to have our WMF custom field on any
    // donations processed via the gateways which support forgetme requests.
    if (!empty($contribution[$customFieldMap['gateway']])) {
      $provider = _civicrm_api3_contribution_forgetme_getproviderobject($contribution);
      $return['showme'][$id] = "{$contribution['receive_date']}: {$contribution[$customFieldMap['original_amount']]} " .
        "{$contribution[$customFieldMap['original_currency']]}, ({$contribution[$customFieldMap['gateway']]} " .
        "{$contribution[$customFieldMap['gateway_txn_id']]})";
      if ($provider instanceof IDeleteDataProvider) {
        $return['showme'][$id] .= ' - processor supports data deletion requests';
      }
    } else {
      // Display something comparable for a contribution without WMF custom fields
      $return['showme'][$id] = "{$contribution['receive_date']}: {$contribution['total_amount']} " .
        "{$contribution['currency']}, ({$contribution['trxn_id']})";
    }
  }
  return $return;
}
