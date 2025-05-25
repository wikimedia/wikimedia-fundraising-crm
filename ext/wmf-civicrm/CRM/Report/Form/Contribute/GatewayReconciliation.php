<?php
/**
 * See https://mingle.corp.wikimedia.org/projects/fundraiser_2012/cards/529
 */

class CRM_Report_Form_Contribute_GatewayReconciliation extends CRM_Report_Form {

  function __construct() {
    $gateway_options = [
      '' => '--any--',
      'ADYEN' => 'Adyen',
      'AMAZON' => 'Amazon',
      'ASTROPAY' => 'AstroPay', // depreciated
      'BITPAY' => 'Bitpay',
      'BRAINTREE' => 'Braintree',
      'COINBASE' => 'Coinbase',
      'DLOCAL' => 'Dlocal',
      'ENGAGE' => 'Engage',
      'GENERIC_IMPORT' => 'Generic Import',
      'GLOBALCOLLECT' => 'GlobalCollect (legacy integration)',
      'INGENICO' => 'Ingenico (Connect)',
      'JPMORGAN' => 'JP Morgan',
      'PAYPAL' => 'PayPal (legacy integration)',
      'PAYPAL_EC' => 'PayPal Express Checkout',
      'SQUARE' => 'Square',
      'TRILOGY' => 'Trilogy',
    ];

    $this->_columns = [
      'civicrm_contribution' => [
        'dao' => 'CRM_Contribute_DAO_Contribution',
        'fields' => [
          'receive_date' => [
            'title' => ts('Initiated Date (UTC)'),
            'required' => TRUE,
            'no_display' => TRUE,
          ],
        ],
        'filters' => [
          'receive_date' => [
            'title' => ts('Initiated Date (UTC)'),
            'operatorType' => CRM_Report_Form::OP_DATETIME,
            'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,

          ],
          /*
          'is_negative' => array(
              'title' => ts( 'Credit (+) or Debit (-)' )
              'type' => CRM_Utils_Type::T_STRING,
              'operatorType' => CRM_Report_Form::OP_SELECT,
              'options' => array(
                  '' => '--any--', '+' => 'Credit (+)', '-' => 'Debit (-)'
              ),
          ),
          */
        ],
        'group_bys' => [],
      ],
      'civicrm_financial_trxn' => [
        'dao' => 'CRM_Financial_DAO_FinancialTrxn',
        'fields' => [
          'total_amount' => [
            'title' => ts('Total Amount (USD)'),
            'required' => TRUE,
            'type' => CRM_Utils_Type::T_MONEY,
            'statistics' => [
              'sum' => ts('Total Amount (USD)'),
              'count' => ts('Number of Contributions'),
            ],
          ],
          'is_negative' => [
            'title' => ts('Credit (+) or Debit (-)'),
            'required' => TRUE,
            'dbAlias' => "IF(financial_trxn_civireport.total_amount < 0, '-', '+' )",
          ],
          'financial_trxn_payment_instrument_id' => [
            'name' => 'payment_instrument_id',
            'title' => ts('Payment Method'),
            'default' => TRUE,
          ],
        ],
        'filters' => [
          'financial_trxn_payment_instrument_id' => [
            'name' => 'payment_instrument_id',
            'title' => ts('Payment Method'),
            'type' => CRM_Utils_Type::T_INT,
            'operatorType' => CRM_Report_Form::OP_MULTISELECT,
            'options' => $this->getOptions('FinancialTrxn', 'payment_instrument_id'),
          ],
        ],
        'group_bys' => [
          'payment_instrument_id' => [
            'name' => 'payment_instrument_id',
            'title' => ts('Payment Method'),
          ],
          'is_negative' => [
            'title' => ts('Credit (+) or Debit (-)'),
            'default' => FALSE,
          ],
        ],
      ],
      'wmf_contribution_extra' => [
        'fields' => [
          'deposit_date' => [
            'title' => ts('Deposit Date (UTC)'),
            'default' => FALSE,
            'no_display' => TRUE,
          ],
          'settlement_date' => [
            'title' => ts('Settlement Date (UTC)'),
            'default' => FALSE,
            'no_display' => TRUE,
          ],
          'original_amount' => [
            'title' => ts('Original Amount'),
            'type' => CRM_Utils_Type::T_MONEY,
            'statistics' => [
              'sum' => ts('Total Original Amount'),
            ],
          ],
          'original_currency' => [
            'title' => ts('Original Currency'),
            'required' => TRUE,
          ],
          'gateway' => [
            'title' => ts('Gateway'),
            'required' => TRUE,
          ],
          'gateway_account' => [
            'title' => ts('Account'),
            'required' => TRUE,
          ],
        ],
        'filters' => [
          'deposit_date' => [
            'title' => ts('Deposit Date (UTC)'),
            'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
            'operatorType' => CRM_Report_Form::OP_DATETIME,
          ],
          'settlement_date' => [
            'title' => ts('Settlement Date (UTC)'),
            'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
            'operatorType' => CRM_Report_Form::OP_DATETIME,
          ],
          'gateway' => [
            'title' => ts('Gateway'),
            'type' => CRM_Utils_Type::T_STRING,
            'operatorType' => CRM_Report_Form::OP_SELECT,
            'options' => $gateway_options,
          ],
          'original_currency' => [
            'title' => ts('Original Currency'),
            'type' => CRM_Utils_Type::T_STRING,
            'operatorType' => CRM_Report_Form::OP_STRING,
          ],
        ],
        'group_bys' => [
          'original_currency' => [
            'title' => ts('Original Currency'),
            'default' => TRUE,
          ],
          'gateway' => [
            'title' => ts('Gateway'),
            'default' => TRUE,
          ],
          'gateway_account' => [
            'title' => ts('Account'),
            'default' => FALSE,
          ],
        ],
      ],
      'civicrm_country' => [
        'dao' => 'CRM_Core_DAO_Country',
        'fields' => [
          'iso_code' => [
            'title' => ts('Country'),
            'default' => FALSE,
          ],
        ],
        'filters' => [
          'iso_code' => [
            'title' => ts('Country'),
            'type' => CRM_Utils_Type::T_STRING,
            'operatorType' => CRM_Report_Form::OP_STRING,
          ],
        ],
        'group_bys' => [
          'iso_code' => [
            'title' => ts('Country'),
            'default' => FALSE,
          ],
        ],
      ],
    ];

    parent::__construct();
  }

  /**
   * Get the options for a given field.
   *
   * @param string $entity
   * @param string $field
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  function getOptions($entity, $field) {
    $options = civicrm_api3($entity, 'getoptions', ['field' => $field]);
    return $options['values'];
  }

  function buildQuery($applyLimit = TRUE) {
    return "/* timeout=600 */ " . parent::buildQuery();
  }

  function from() {
    $this->_from = <<<EOS
FROM civicrm_contribution {$this->_aliases['civicrm_contribution']}
LEFT JOIN wmf_contribution_extra {$this->_aliases['wmf_contribution_extra']}
    ON {$this->_aliases['wmf_contribution_extra']}.entity_id = {$this->_aliases['civicrm_contribution']}.id

LEFT JOIN civicrm_entity_financial_trxn entity_financial_trxn_civireport
                    ON (contribution_civireport.id = entity_financial_trxn_civireport.entity_id AND
                        entity_financial_trxn_civireport.entity_table = 'civicrm_contribution')

  LEFT JOIN civicrm_financial_trxn {$this->_aliases['civicrm_financial_trxn']}
                    ON {$this->_aliases['civicrm_financial_trxn']}.id = entity_financial_trxn_civireport.financial_trxn_id
EOS;
    if ($this->isTableSelected('civicrm_country')) {
      $this->_from .= <<<EOS
\nLEFT JOIN civicrm_address
    ON civicrm_address.contact_id = {$this->_aliases['civicrm_contribution']}.contact_id
        AND civicrm_address.is_primary = 1
LEFT JOIN civicrm_country {$this->_aliases['civicrm_country']}
    ON {$this->_aliases['civicrm_country']}.id = civicrm_address.country_id
EOS;
    }
  }

  function grandTotal(&$rows) {
    $sum_amount = $sum_count = 0;
    foreach ($rows as $rowNum => $row) {
      $sum_amount += $row['civicrm_financial_trxn_total_amount_sum'];
      $sum_count += $row['civicrm_financial_trxn_total_amount_count'];
    }
    $grand_total_row = [
      'civicrm_financial_trxn_total_amount_sum' => $sum_amount,
      'civicrm_financial_trxn_total_amount_count' => $sum_count,
    ];
    $this->assign('grandStat', $grand_total_row);
  }

  function modifyColumnHeaders() {
    // We hide any non-aggregate fields which are not being grouped, since these
    // will have an indeterminate value
    foreach ($this->_columns as $tableName => $table) {
      foreach ($table['group_bys'] as $fieldName => $field) {
        if (!array_key_exists('group_bys', $this->_params)
          or !array_key_exists($fieldName, $this->_params['group_bys'])) {
          unset($this->_columnHeaders["{$tableName}_{$fieldName}"]);
        }
      }
    }
  }

  function storeWhereHavingClauseArray() {
    parent::storeWhereHavingClauseArray();
    $depositFinancialAccountID = civicrm_api3('FinancialAccount', 'getvalue', [
      'return' => 'id',
      'name' => 'Deposit Bank Account',
    ]);
    $this->_whereClauses[] = "{$this->_aliases['civicrm_financial_trxn']}.to_financial_account_id = {$depositFinancialAccountID}";

  }

  /**
   *
   * Alter display of rows.
   *
   * We can speed up the query significantly by eliminating the join to
   * the option value table. Despite appearances it is an unindexed join.
   *
   * @param array $rows
   *   Rows generated by SQL, with an array for each row.
   */
  public function alterDisplay(&$rows) {
    $paymentInstruments = CRM_Contribute_PseudoConstant::paymentInstrument();

    foreach ($rows as $rowNum => $row) {
      if (!array_key_exists('civicrm_financial_trxn_payment_instrument_id', $row)) {
        return;
      }
      if (!empty($row['civicrm_financial_trxn_payment_instrument_id'])) {
        $rows[$rowNum]['civicrm_financial_trxn_payment_instrument_id'] = $paymentInstruments[$row['civicrm_financial_trxn_payment_instrument_id']];
      }
    }
  }

}
