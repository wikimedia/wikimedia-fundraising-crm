<?php

use CRM_Wmffraud_ExtensionUtil as E;

class CRM_Wmffraud_Form_Report_Fredge extends CRM_Wmffraud_Form_Report_FraudReportsBase {

  const FRAUD_FILTERS = [
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

  function __construct() {
    parent::__construct();

    $this->fredge = substr($this->drupal, 0,
      3) === 'dev' ? 'dev_fredge' : 'fredge';

    $this->_columns = [];
    $this->_columns['payments_fraud'] = [
      'alias' => 'payments_fraud',
      'fields' => [
        'user_ip' => [
          'name' => 'user_ip',
          'title' => E::ts('IP Address'),
          'dbAlias' => "INET_NTOA(payments_fraud_civireport.user_ip)",
        ],
        'validation_action' => [
          'title' => E::ts('Payment Action'),
        ],
        'fredge_date' => [
          'title' => E::ts('Payment attempt date'),
          'name' => 'date',
          'default' => TRUE,
        ],
        'gateway' => [
          'title' => E::ts('Payment gateway'),
          'name' => 'gateway',
        ],
        'order_id' => [
          'title' => E::ts('Order ID'),
          'default' => TRUE,
          'name' => 'order_id',
        ],
        'risk_score' => [
          'title' => E::ts('Risk Score'),
          'default' => TRUE,
          'name' => 'risk_score',
        ],
        'server' => [
          'title' => E::ts('Server'),
          'name' => 'server',
        ],
      ],
      'filters' => [
        'user_ip' => [
          'name' => 'user_ip',
          'title' => E::ts('IP Address'),
          'type' => CRM_Utils_Type::T_STRING,
        ],
        'order_id' => [
          'title' => E::ts('Order ID'),
          'name' => 'order_id',
          'type' => CRM_Utils_Type::T_STRING,
        ],
        'validation_action' => [
          'title' => E::ts('Action'),
          'type' => CRM_Utils_Type::T_STRING,
        ],
        'fredge_date' => [
          'title' => E::ts('Payment attempt date'),
          'name' => 'date',
          'type' => CRM_Utils_Type::T_DATE,
        ],
      ],
      'order_bys' => [
        'fredge_date' => [
          'title' => E::ts('Payment attempt date'),
          'name' => 'date',
          'type' => CRM_Utils_Type::T_DATE,
        ],
        'user_ip' => [
          'name' => 'user_ip',
          'title' => E::ts('IP Address'),
          'type' => CRM_Utils_Type::T_STRING,
        ],
        'validation_action' => [
          'title' => E::ts('Action'),
          'type' => CRM_Utils_Type::T_STRING,
        ],
      ],
    ];

    foreach (self::FRAUD_FILTERS as $columnName => $value) {
      $this->_columns["payments_fraud_breakdown_" . $columnName] = [
        'alias' => "payments_fraud_breakdown_{$columnName}",
        'name' => "payments_fraud_breakdown",
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
  }

  public function setDefaultValues($freeze = TRUE) {
    $defaults = parent::setDefaultValues(TRUE);
    $this->convertOrderArrayToString($defaults);
    return $defaults;
  }

  function from() {
    $this->_from = "FROM {$this->fredge}.payments_fraud {$this->_aliases['payments_fraud']} ";
    foreach (self::FRAUD_FILTERS as $columnName => $value) {
      $tblAlias = "payments_fraud_breakdown_" . $columnName;
      $this->_from .= "LEFT JOIN {$this->fredge}.payments_fraud_breakdown {$this->_aliases[$tblAlias]}
        ON {$this->fredge}.{$this->_aliases['payments_fraud']}.id = {$this->fredge}.{$this->_aliases[$tblAlias]}.payments_fraud_id
        AND {$this->fredge}.{$this->_aliases[$tblAlias]}.filter_name = '$value' ";
    }
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
  protected function convertOrderArrayToString(&$defaults) {
    if (isset($defaults['order_id_value']) && is_array($defaults['order_id_value'])) {
      $defaults['order_id_value'] = implode(',', $defaults['order_id_value']);
    }
  }
}
