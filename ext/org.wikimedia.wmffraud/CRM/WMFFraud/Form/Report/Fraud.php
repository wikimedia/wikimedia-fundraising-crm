<?php

use CRM_WMFFraud_ExtensionUtil as E;

/**
 * Class CRM_WMFFraud_Form_Report_Fraud
 *
 * Report for identifying possibly fraudulent contributions.
 *
 * This reports is contribution-based & links in fraud data relating to those contributions.
 */
class CRM_WMFFraud_Form_Report_Fraud extends CRM_WMFFraud_Form_Report_FraudReportsBase {

  public function __construct() {
    parent::__construct();
    $this->_columns['civicrm_contribution']['order_bys']['receive_date']['default_order'] = 'DESC';
  }

  public function preProcess() {
    $this->assign('reportTitle', E::ts('Potential Fraudsters Report'));
    parent::preProcess();
  }

  /**
   * Generate from clause for main fraud report.
   *
   * The report is based on contributions, which may or may not have fredge entries.
   */
  public function from() {
    $this->_from = "
      FROM civicrm_contribution {$this->_aliases['civicrm_contribution']}
      LEFT JOIN civicrm_contribution_tracking {$this->_aliases['civicrm_contribution_tracking']}
        ON {$this->_aliases['civicrm_contribution_tracking']}.contribution_id = {$this->_aliases['civicrm_contribution']}.id
      LEFT JOIN payments_fraud {$this->_aliases['payments_fraud']}
      ON {$this->_aliases['payments_fraud']}.contribution_tracking_id = {$this->_aliases['civicrm_contribution_tracking']}.id";
    $this->addJoinToContactAndEmail();
    $this->addJoinToPaymentsFraudBreakdown();
    $this->addIpFailsJoin();
    $this->addEmailFailsJoin();
  }

}
