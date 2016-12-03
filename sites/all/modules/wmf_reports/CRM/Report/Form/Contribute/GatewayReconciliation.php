<?php
/**
 * See https://mingle.corp.wikimedia.org/projects/fundraiser_2012/cards/529
 */

class CRM_Report_Form_Contribute_GatewayReconciliation extends CRM_Report_Form {
    function __construct( ) {
        $gateway_options = array(
            '' => '--any--',
            'ADYEN' => 'Adyen',
            'AMAZON' => 'Amazon',
            'ARIZONALOCKBOX' => 'Arizona Lockbox',
            'ASTROPAY' => 'AstroPay',
            'COINBASE' => 'Coinbase',
            'ENGAGE' => 'Engage',
            'GENERIC_IMPORT' => 'Generic Import',
            'GLOBALCOLLECT' => 'GlobalCollect',
            'JPMORGAN' => 'JP Morgan',
            'PAYPAL' => 'PayPal',
            'SQUARE' => 'Square',
            'TRILOGY' => 'Trilogy',
            'WORLDPAY' => 'Worldpay',
        );

        $this->_columns = array(
            'civicrm_contribution' => array(
                'dao' => 'CRM_Contribute_DAO_Contribution',
                'fields' => array(
                    'receive_date' => array(
                        'title' => ts( 'Initiated Date (UTC)' ),
                        'required' => true,
                        'no_display' => true,
                    ),
                ),
                'filters' => array(
                    'receive_date' => array(
                        'title' => ts( 'Initiated Date (UTC)' ),
                        'operatorType' => CRM_Report_Form::OP_DATETIME,
                        'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,

                    ),
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
                ),
              'group_bys' => array(),
            ),
            'civicrm_financial_trxn' => array(
                'dao' => 'CRM_Financial_DAO_FinancialTrxn',
                'fields' => array(
                    'total_amount' => array(
                        'title' => ts('Total Amount (USD)'),
                        'required' => true,
                        'type' => CRM_Utils_Type::T_MONEY,
                        'statistics' => array(
                            'sum' => ts( 'Total Amount (USD)' ),
                            'count' => ts( 'Number of Contributions' ),
                        ),
                    ),
                    'is_negative' => array(
                        'title' => ts( 'Credit (+) or Debit (-)' ),
                        'required' => true,
                    ),
                    'financial_trxn_payment_instrument_id' => array(
                        'name' => 'payment_instrument_id',
                        'title' => ts( 'Payment Method' ),
                        'default' => true,
                    ),
                ),
                'filters' => array(
                  'financial_trxn_payment_instrument_id' => array(
                        'name' => 'payment_instrument_id',
                        'title' => ts( 'Payment Method' ),
                        'type' => CRM_Utils_Type::T_INT,
                        'operatorType' => CRM_Report_Form::OP_MULTISELECT,
                        'options' => $this->getOptions('FinancialTrxn', 'payment_instrument_id'),
                    ),
                ),
                'group_bys' => array(
                  'payment_instrument_id' => array(
                        'name' => 'payment_instrument_id',
                        'title' => ts( 'Payment Method' ),
                  ),
                  'is_negative' => array(
                      'title' => ts( 'Credit (+) or Debit (-)' ),
                      'default' => false,
                  ),
                ),
            ),
            'wmf_contribution_extra' => array(
                'bao' => 'CRM_BAO_WmfContributionExtra',
                'fields' => array(
                    'deposit_date' => array(
                        'title' => ts( 'Deposit Date (UTC)' ),
                        'default' => false,
                        'no_display' => true,
                    ),
                    'settlement_date' => array(
                        'title' => ts( 'Settlement Date (UTC)' ),
                        'default' => false,
                        'no_display' => true,
                    ),
                    'original_currency' => array(
                        'title' => ts( 'Original Currency' ),
                        'required' => true,
                    ),
                    'gateway' => array(
                        'title' => ts( 'Gateway' ),
                        'required' => true,
                    ),
                    'gateway_account' => array(
                        'title' => ts( 'Account' ),
                        'required' => true,
                    ),
                ),
                'filters' => array(
                    'deposit_date' => array(
                        'title' => ts( 'Deposit Date (UTC)' ),
                        'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
                        'operatorType' => CRM_Report_Form::OP_DATETIME,
                    ),
                    'settlement_date' => array(
                        'title' => ts( 'Settlement Date (UTC)' ),
                        'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
                        'operatorType' => CRM_Report_Form::OP_DATETIME,
                    ),
                    'gateway' => array(
                        'title' => ts( 'Gateway' ),
                        'type' => CRM_Utils_Type::T_STRING,
                        'operatorType' => CRM_Report_Form::OP_SELECT,
                        'options' => $gateway_options,
                    ),
                    'original_currency' => array(
                        'title' => ts( 'Original Currency' ),
                        'type' => CRM_Utils_Type::T_STRING,
                        'operatorType' => CRM_Report_Form::OP_STRING,
                    ),
                ),
                'group_bys' => array(
                    'original_currency' => array(
                        'title' => ts( 'Original Currency' ),
                        'default' => true,
                    ),
                    'gateway' => array(
                        'title' => ts( 'Gateway' ),
                        'default' => true,
                    ),
                    'gateway_account' => array(
                        'title' => ts( 'Account' ),
                        'default' => false,
                    ),
                ),
            ),
            'civicrm_country' => array(
                'dao' => 'CRM_Core_DAO_Country',
                'fields' => array(
                    'iso_code' => array(
                        'title' => ts( 'Country' ),
                        'default' => false,
                    ),
                ),
                'filters' => array(
                    'iso_code' => array(
                        'title' => ts( 'Country' ),
                        'type' => CRM_Utils_Type::T_STRING,
                        'operatorType' => CRM_Report_Form::OP_STRING,
                    ),
                ),
                'group_bys' => array(
                    'iso_code' => array(
                        'title' => ts( 'Country' ),
                        'default' => false,
                    ),
                ),
            ),
        );

        parent::__construct( );
    }

    /**
     * Get the options for a given field.
     *
     * @param string $entity
     * @param string $field
     *
     * @return array
     * @throws CiviCRM_API3_Exception
     */
    function getOptions($entity, $field) {
        $options = civicrm_api3($entity, 'getoptions', array('field' => $field));
        return $options['values'];
    }

    function buildQuery($applyLimit = true) {
        return "/* timeout=600 */ " . parent::buildQuery();
    }

    function from( ) {

        $depositFinancialAccountID = civicrm_api3('FinancialAccount', 'getvalue', array(
          'return' => 'id',
          'name' => 'Deposit Bank Account',
        ));

        $this->_from = <<<EOS
FROM civicrm_contribution {$this->_aliases['civicrm_contribution']}
LEFT JOIN wmf_contribution_extra {$this->_aliases['wmf_contribution_extra']}
    ON {$this->_aliases['wmf_contribution_extra']}.entity_id = {$this->_aliases['civicrm_contribution']}.id

LEFT JOIN civicrm_entity_financial_trxn entity_financial_trxn_civireport
                    ON (contribution_civireport.id = entity_financial_trxn_civireport.entity_id AND
                        entity_financial_trxn_civireport.entity_table = 'civicrm_contribution')

  LEFT JOIN civicrm_financial_trxn {$this->_aliases['civicrm_financial_trxn']}
                    ON {$this->_aliases['civicrm_financial_trxn']}.id = entity_financial_trxn_civireport.financial_trxn_id
                    AND {$this->_aliases['civicrm_financial_trxn']}.to_financial_account_id = {$depositFinancialAccountID}
EOS;
        if ( $this->isTableSelected( 'civicrm_country' ) ) {
            $this->_from .= <<<EOS
\nLEFT JOIN civicrm_address
    ON civicrm_address.contact_id = {$this->_aliases['civicrm_contribution']}.contact_id
        AND civicrm_address.is_primary = 1
LEFT JOIN civicrm_country {$this->_aliases['civicrm_country']}
    ON {$this->_aliases['civicrm_country']}.id = civicrm_address.country_id
EOS;
        }
    }

    function grandTotal( &$rows ) {
        $sum_amount = $sum_count = 0;
        foreach ( $rows as $rowNum => $row ) {
            $sum_amount += $row['civicrm_financial_trxn_total_amount_sum'];
            $sum_count += $row['civicrm_financial_trxn_total_amount_count'];
        }
        $grand_total_row = array(
            'civicrm_financial_trxn_total_amount_sum' => $sum_amount,
            'civicrm_financial_trxn_total_amount_count' => $sum_count,
        );
        $this->assign( 'grandStat', $grand_total_row );
    }

    function modifyColumnHeaders() {
        // We hide any non-aggregate fields which are not being grouped, since these
        // will have an indeterminate value
        foreach ( $this->_columns as $tableName => $table ) {
            foreach ( $table['group_bys'] as $fieldName => $field ) {
                if ( !array_key_exists( 'group_bys', $this->_params )
                    or !array_key_exists( $fieldName, $this->_params['group_bys'] ) )
                {
                    unset( $this->_columnHeaders["{$tableName}_{$fieldName}"] );
                }
            }
        }
    }

    function selectClause( &$tableName, $type, &$fieldName, &$field ) {
        switch ( $fieldName ) {
        case 'is_negative':
            $this->register_field_alias( $tableName, $fieldName, $field );
            $sql = "IF( {$this->_aliases['civicrm_financial_trxn']}.total_amount < 0, '-', '+' )";
            if ( $type === 'fields' ) {
                return $sql . " AS {$field['dbAlias']}";
            }
            return false;
        }
        return parent::selectClause( $tableName, $type, $fieldName, $field );
    }

    function register_field_alias( $tableName, $fieldName, &$field ) {
        // until the base class takes care of it:
        //if ( !CRM_Utils_Array::value( 'dbAlias', $field ) ) {
        $field['dbAlias'] = "{$tableName}_{$fieldName}";
        if ( array_key_exists('group_bys', $this->_columns[$tableName])
            and array_key_exists($fieldName, $this->_columns[$tableName]['group_bys']) )
        {
            $this->_columns[$tableName]['group_bys'][$fieldName]['dbAlias'] = $field['dbAlias'];
        }
        $this->_columns[$tableName]['fields'][$fieldName]['dbAlias'] = $field['dbAlias'];
        $this->_columns[$tableName]['filters'][$fieldName]['dbAlias'] = $field['dbAlias'];

        $this->_columnHeaders[$field['dbAlias']]['title'] = CRM_Utils_Array::value( 'title', $field );
        $this->_columnHeaders[$field['dbAlias']]['type'] = CRM_Utils_Array::value( 'type', $field );
        $this->_selectAliases[] = $field['dbAlias'];
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
