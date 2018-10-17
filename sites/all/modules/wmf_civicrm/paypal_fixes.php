<?php

function wmf_civicrm_undo_bogus_paypal_cancel($start, $end) {
  civicrm_initialize();
  $contributionStatusId = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
  $cancelledStatusId = CRM_Utils_Array::key('Cancelled', $contributionStatusId);
  $completedStatusId = CRM_Utils_Array::key('Completed', $contributionStatusId);

  CRM_Core_DAO::executeQuery("
    UPDATE civicrm_contribution_recur
    SET
      contribution_status_id = $completedStatusId,
      cancel_date = NULL
    WHERE (
      trxn_id LIKE 'I-%' OR trxn_id LIKE 'S-%'
    )
    AND contribution_status_id = $cancelledStatusId
    AND cancel_date BETWEEN '$start' AND '$end'
  ");
}
