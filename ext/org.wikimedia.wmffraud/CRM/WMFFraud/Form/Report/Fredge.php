<?php

use CRM_WMFFraud_ExtensionUtil as E;

class CRM_WMFFraud_Form_Report_Fredge extends CRM_WMFFraud_Form_Report_FraudReportsBase {

  public const FRAUD_FILTERS = [
    'AVS' => 'getAVSResult',
    'CVV' => 'getCVVResult',
    'ScoreCountryMap' => 'getScoreCountryMap',
    'ScoreName' => 'getScoreName',
    'ScoreEmailDomainMap' => 'getScoreEmailDomainMap',
    'ScoreUtmCampaignMap' => 'getScoreUtmCampaignMap',
    'initial' => 'initial',
    'IPVelocity' => 'IPVelocityFilter',
    'minfraud' => 'minfraud_filter',
    'SessionVelocity' => 'SessionVelocity',
    'donationInterfaceEmailPattern' => 'donation_interface_fraud_email_pattern',
    'IPBlacklist' => 'IPBlacklist',
  ];

  public function setDefaultValues($freeze = TRUE) : array {
    $defaults = parent::setDefaultValues(TRUE);
    $this->convertOrderArrayToString($defaults);
    return $defaults;
  }

  public function from() : void {
    $this->_from = "
      FROM payments_fraud {$this->_aliases['payments_fraud']}";
    if ($this->isTableSelected('civicrm_contribution_tracking')
      || $this->isTableSelected('civicrm_contribution')
      || $this->isTableSelected('civicrm_email')
      || $this->isTableSelected('civicrm_contact')
    ) {
      $this->_from .= "
        LEFT JOIN civicrm_contribution_tracking {$this->_aliases['civicrm_contribution_tracking']}
        ON {$this->_aliases['civicrm_contribution_tracking']}.id = {$this->_aliases['payments_fraud']}.contribution_tracking_id ";
    }
    if ($this->isTableSelected('civicrm_contribution')
      || $this->isTableSelected('civicrm_email')
      || $this->isTableSelected('civicrm_contact')
    ) {
      $this->_from .= "
        LEFT JOIN civicrm_contribution {$this->_aliases['civicrm_contribution']}
        ON {$this->_aliases['civicrm_contribution']}.id = {$this->_aliases['civicrm_contribution_tracking']}.contribution_id
      ";
    }
    if ($this->isTableSelected('civicrm_email')
      || $this->isTableSelected('civicrm_contact')
    ) {
      $this->addJoinToContactAndEmail();
    }

    foreach (self::FRAUD_FILTERS as $columnName => $value) {
      $tblAlias = "payments_fraud_breakdown_" . $columnName;
      $this->_from .= "LEFT JOIN payments_fraud_breakdown {$this->_aliases[$tblAlias]}
        ON {$this->_aliases['payments_fraud']}.id = {$this->_aliases[$tblAlias]}.payments_fraud_id
        AND {$this->_aliases[$tblAlias]}.filter_name = '$value' ";
    }
  }

  protected function storeGroupByArray(): void {
    parent::storeGroupByArray();

    // We're currently joining payments_fraud_breakdown mutuple times so grouping by id removes duplicate rows
    $this->_groupByArray['payments_fraud_id'] = $this->_aliases['payments_fraud'] . '.id';
  }

  /**
   * Sorting function to bring the breakdown tables to the top.
   *
   * @param string $a
   * @param string $b
   *
   * @return int
   */
  public function tableSort($a, $b) : int {
    $weLikeA = strpos($a, 'payments_fraud_breakdown_') === 0;
    $weLikeB = strpos($b, 'payments_fraud_breakdown_') === 0;
    if ($weLikeA && !$weLikeB) {
      return -1;
    }
    if ($weLikeB && !$weLikeA) {
      return 1;
    }
    return 0;
  }

  /**
   * We are supporting 'in' on a string field which is not supported in core as
   * yet.
   *
   * Core functions transform our string into an array - we kinda need it to be
   * an array so the field populates the form defaults & isn't lost on reload.
   *
   * This could obviously be more generic & if we upstream support for IN on
   * strings it would have to be.
   *
   * @param array $defaults
   */
  protected function convertOrderArrayToString(array &$defaults) : void {
    if (isset($defaults['order_id_value']) && is_array($defaults['order_id_value'])) {
      $defaults['order_id_value'] = implode(',', $defaults['order_id_value']);
    }
  }

  /**
   * Set the metadata to describe the report.
   *
   * @return void
   */
  protected function setReportColumns(): void {
    parent::setReportColumns();
    // Filter the tables set in the parent down to those for this report.
    $this->_columns = array_intersect_key($this->_columns, array_fill_keys([
      'payments_fraud',
      'civicrm_contribution_tracking',
      'civicrm_contribution',
      'civicrm_contact',
      'civicrm_email',
    ], TRUE));

    // Add report-appropriate defaults.
    $this->overrideDefaults([
      'payments_fraud' => [
        'fields' => [
          'fredge_date' => TRUE,
          'gateway' => TRUE,
          'order_id' => TRUE,
          'risk_score' => TRUE,
        ],
        'order_bys' => ['fredge_date' => 'DESC'],
      ],
      'civicrm_contribution_tracking' => [
        'fields' => [
          'amount' => TRUE,
          'currency' => TRUE,
          'country' => TRUE,
        ],
      ],
    ]);

    // Don't inherit required/ default fields from parent for tables which may have
    // no data (if the contribution failed)
    $this->doNotRequireFieldsFrom([
      'civicrm_contribution',
      'civicrm_contact',
      'civicrm_email',
    ]);
    $this->_columns['civicrm_contribution']['grouping'] = 'on_success';
    $this->_columns['civicrm_email']['grouping'] = 'on_success';
    $this->_columns['civicrm_contact']['grouping'] = 'on_success';
    $this->_columns['civicrm_contribution']['group_title'] = 'Additional information for payments that reached CiviCRM';

    $this->_columns['civicrm_contribution_tracking']['group_title'] = 'Contribution Tracking';

    foreach (self::FRAUD_FILTERS as $columnName => $value) {
      $this->_columns["payments_fraud_breakdown_" . $columnName] = [
        'grouping' => 'payment_attempt',
        'group_title' => 'Fraud score Fields',
        'alias' => "payments_fraud_breakdown_{$columnName}",
        'name' => "payments_fraud_breakdown",
        'title' => $columnName,
        'fields' => [
          $columnName => [
            'name' => "risk_score",
            'title' => ts($columnName),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_STRING,
          ],
        ],
        'filters' => [
          $columnName => [
            'name' => "risk_score",
            'title' => ts($columnName),
            'type' => CRM_Utils_Type::T_STRING,
          ],
        ],
      ];
    }
    // Sort all the payment fraud breakdown stuff to the start to influence UI order.
    uksort($this->_columns, [$this, 'tableSort']);
  }

}
