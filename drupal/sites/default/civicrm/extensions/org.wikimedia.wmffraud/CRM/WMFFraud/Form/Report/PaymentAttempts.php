<?php

use CRM_WMFFraud_ExtensionUtil as E;

/**
 * Class CRM_WMFFraud_Form_Report_PaymentAttempts
 *
 * The payment attempts reports shows all payment attempts, regardless of whether
 * they resulted in a contribution.
 */
class CRM_WMFFraud_Form_Report_PaymentAttempts extends CRM_WMFFraud_Form_Report_FraudReportsBase {

  function __construct() {
    parent::__construct();
    $this->_columns['payments_fraud']['fields']['validation_action']['default'] = 1;
    $this->_columns['payments_fraud']['fields']['fredge_date']['default'] = 1;
    $this->_columns['payments_fraud']['order_bys']['fredge_date']['default_order'] = 'DESC';
  }

  /**
   * Generate from clause for payment attempts.
   *
   * The difference between this report and the fraud report is the base table.
   *
   * This report shows all payment attempts and left joins onto contribution
   * whereas the other shows all contributions and left joins onto payments.
   */
  function from() {
    $this->_from = "
      FROM {$this->fredge}.payments_fraud {$this->_aliases['payments_fraud']}
      LEFT JOIN civicrm_contribution_tracking {$this->_aliases['civicrm_contribution_tracking']}
        ON {$this->_aliases['payments_fraud']}.contribution_tracking_id = {$this->_aliases['civicrm_contribution_tracking']}.id
      LEFT JOIN civicrm_contribution {$this->_aliases['civicrm_contribution']}
        ON {$this->_aliases['civicrm_contribution_tracking']}.contribution_id = {$this->_aliases['civicrm_contribution']}.id";

    $this->addJoinToContactAndEmail();
    $this->addJoinToPaymentsFraudBreakdown();
    $this->addIpFailsJoin();
    $this->addEmailFailsJoin();
  }

  function preProcess() {
    $this->assign('reportTitle', E::ts('Payment attempts'));
    parent::preProcess();
  }

}
