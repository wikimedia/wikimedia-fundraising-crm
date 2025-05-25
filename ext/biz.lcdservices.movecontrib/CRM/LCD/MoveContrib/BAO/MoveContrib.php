<?php

/**
 * Class CRM_LCD_MoveContrib_Form_MoveContrib
 */
class CRM_LCD_MoveContrib_BAO_MoveContrib {

  static function moveContribution($params) {
    $params['id'] = $params['contribution_id'];
    $contribution = CRM_Contribute_BAO_Contribution::create($params);

    // Update the financial items' contact IDs as well.
    $sql = "
      UPDATE civicrm_financial_item cfi
      JOIN civicrm_entity_financial_trxn cefti
        ON cefti.entity_id = cfi.id
      JOIN civicrm_financial_trxn cft
        ON cefti.entity_table = 'civicrm_financial_item'
        AND cft.id = cefti.financial_trxn_id
      JOIN civicrm_entity_financial_trxn ceftc
        ON ceftc.financial_trxn_id = cft.id
      SET cfi.contact_id = %1
      WHERE ceftc.entity_table = 'civicrm_contribution'
        AND ceftc.entity_id = %2
    ";
    CRM_Core_DAO::executeQuery($sql, [
      1 => [$params['change_contact_id'], 'Positive'],
      2 => [$params['contribution_id'], 'Positive'],
    ]);

    // record activity for moving contribution
    if ($contribution) {
      $subject = "Contribution #{$params['contribution_id']} Moved";
      $details = "Contribution #{$params['contribution_id']} was moved from contact #{$params['current_contact_id']} to contact #{$params['change_contact_id']}.";

      $activityTypeID = \CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'contribution_reassignment');

      $activityParams = [
        'source_contact_id' => $params['current_contact_id'],
        'activity_type_id' => $activityTypeID,
        'activity_date_time' => date('YmdHis'),
        'subject' => $subject,
        'details' => $details,
        'status_id' => 2,
      ];

      $session = CRM_Core_Session::singleton();
      $id = $session->get('userID');

      if ($id) {
        $activityParams['source_contact_id'] = $id;
        $activityParams['target_contact_id'][] = $params['current_contact_id'];
        $activityParams['target_contact_id'][] = $params['change_contact_id'];
      }

      try {
        CRM_Activity_BAO_Activity::create($activityParams);
      }
      catch (CRM_Core_Exception $e) {}

      return TRUE;
    }

    return FALSE;
  }
}
