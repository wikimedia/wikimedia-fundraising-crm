<?php

/**
 * Get recurring record in CiviCRM for the given subscriber id
 *
 * @todo - this should be handled in Message::normalize().
 *
 * There is tension between trxn_id as searchable index and full gateway style
 * at the moment, so we search using either style: either bare gateway
 * subscription ID, or trxn-style full natural key.
 *
 * @param string $subscr_id
 *
 * @return object|false
 * @deprecated use WMFHelper/ContributionRecur::getByGatewaySubscriptionId
 *
 */
function wmf_civicrm_get_gateway_subscription($gateway, $subscr_id) {
  $full_trxn_id = "RECURRING {$gateway} {$subscr_id}";

  $query = "select *
    from civicrm_contribution_recur
    where
        trxn_id = %1
        or trxn_id = %2";
  $dao = CRM_Core_DAO::executeQuery($query, [
    1 => [$subscr_id, 'String'],
    2 => [$full_trxn_id, 'String'],
  ]);

  if (!$dao->fetch()) {
    return FALSE;
  }

  return $dao;
}
