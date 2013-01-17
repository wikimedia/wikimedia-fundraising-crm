<?php
/**
 * See https://mingle.corp.wikimedia.org/projects/fundraiser_2012/cards/529
 */

require_once 'CRM/Report/Form.php';

class CRM_Report_Form_Contribute_GatewayReconciliation extends CRM_Report_Form {
    function __construct( ) {
        require_once 'CRM/Core/PseudoConstant.php';

        $gateway_options = array(
            '' => '--any--',
            'AMAZON' => 'Amazon',
            'GLOBALCOLLECT' => 'GlobalCollect',
            'PAYPAL' => 'PayPal',
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
                    'total_amount' => array(
                        'required' => true,
                        'statistics' => array(
                            'sum' => ts( 'Total Amount (USD)' ),
                            'count' => ts( 'Number of Contributions' ),
                        ),
                    ),
                    'is_negative' => array(
                        'title' => ts( 'Credit (+) or Debit (-)' ),
                        'required' => true,
                    ),
                ),
                'filters' => array(
                    'receive_date' => array(
                        'title' => ts( 'Initiated Date (UTC)' ),
                        'type' => CRM_Utils_Type::T_DATE,
                        'operatorType' => CRM_Report_Form::OP_DATE,
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
                'group_bys' => array(
                    'is_negative' => array(
                        'title' => ts( 'Credit (+) or Debit (-)' ),
                        'default' => false,
                    ),
                ),
            ),
            'payment_instrument' => array(
                'dao' => 'CRM_Core_DAO_OptionValue',
                'fields' => array(
                    'simplified_payment_instrument' => array(
                        'name' => 'label',
                        'title' => ts( 'Payment Method' ),
                    ),
                ),
                'filters' => array(
                    'simplified_payment_instrument' => array(
                        'name' => 'label',
                        'title' => ts( 'Payment Method' ),
                        'type' => CRM_Utils_Type::T_STRING,
                        'operatorType' => CRM_Report_Form::OP_STRING,
                        'having' => true,
                    ),
                ),
                'group_bys' => array(
                    'simplified_payment_instrument' => array(
                        'name' => 'label',
                        'title' => ts( 'Payment Method' ),
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
                        'type' => CRM_Utils_Type::T_DATE,
                        'operatorType' => CRM_Report_Form::OP_DATE,
                    ),
                    'settlement_date' => array(
                        'title' => ts( 'Settlement Date (UTC)' ),
                        'type' => CRM_Utils_Type::T_DATE,
                        'operatorType' => CRM_Report_Form::OP_DATE,
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

    function select() {
        if ( $this->is_active('simplified_payment_instrument') ) {
            $this->_columns['payment_instrument']['fields']['simplified_payment_instrument']['required'] = true;
        }

        parent::select();
    }

    function from( ) {
        $this->_from = <<<EOS
FROM civicrm_contribution {$this->_aliases['civicrm_contribution']}
LEFT JOIN wmf_contribution_extra {$this->_aliases['wmf_contribution_extra']}
    ON {$this->_aliases['wmf_contribution_extra']}.entity_id = {$this->_aliases['civicrm_contribution']}.id
EOS;
        if ( $this->is_active( 'iso_code' ) ) {
            $this->_from .= <<<EOS
\nLEFT JOIN civicrm_address
    ON civicrm_address.contact_id = {$this->_aliases['civicrm_contribution']}.contact_id
LEFT JOIN civicrm_country {$this->_aliases['civicrm_country']} 
    ON {$this->_aliases['civicrm_country']}.id = civicrm_address.country_id
EOS;
        }

        if ( $this->is_active( 'simplified_payment_instrument' ) ) {
            $option_group_id = civicrm_option_group_id( 'payment_instrument' );
            $this->_from .= <<<EOS
\nLEFT JOIN civicrm_option_value {$this->_aliases['payment_instrument']}
    ON {$this->_aliases['payment_instrument']}.value = {$this->_aliases['civicrm_contribution']}.payment_instrument_id
        AND {$this->_aliases['payment_instrument']}.option_group_id = {$option_group_id}
EOS;
        }
    }

    function grandTotal( &$rows ) {
        $sum_amount = $sum_count = 0;
        foreach ( $rows as $rowNum => $row ) {
            $sum_amount += $row['civicrm_contribution_total_amount_sum'];
            $sum_count += $row['civicrm_contribution_total_amount_count'];
        }
        $grand_total_row = array(
            'civicrm_contribution_total_amount_sum' => $sum_amount,
            'civicrm_contribution_total_amount_count' => $sum_count,
        );
        $this->assign( 'grandStat', $grand_total_row );
    }

    function modifyColumnHeaders() {
        // We hide any non-aggregate fields which are not being grouped, since these
        // will have an indeterminate value
        foreach ( $this->_columns as $tableName => $table ) {
            foreach ( $table['group_bys'] as $fieldName => $field ) {
                if ( !array_key_exists( 'group_bys', $this->_submitValues )
                    or !array_key_exists( $fieldName, $this->_submitValues['group_bys'] ) )
                {
                    unset( $this->_columnHeaders["{$tableName}_{$fieldName}"] );
                }
            }
        }
    }

    function is_active( $field_name ) {
        return ( array_key_exists( "{$field_name}_value", $this->_submitValues )
                and $this->_submitValues["{$field_name}_value"] )
            or ( array_key_exists( 'group_bys', $this->_submitValues )
                and array_key_exists( $field_name, $this->_submitValues['group_bys'] ) );
    }

    // hack taken from http://issues.civicrm.org/jira/browse/CRM-9505
    function addDateRange( $name, $label = 'From:', $dateFormat = 'searchDate', $required = false ) {
        $this->addDateTime( $name . '_from', $label , $required, array( 'formatType' => $dateFormat ) );
        $this->addDateTime( $name . '_to' , ts('To:'), $required, array( 'formatType' => $dateFormat ) );
    }

    function selectClause( $tableName, $type, $fieldName, &$field ) {
        switch ( $fieldName ) {
        case 'is_negative':
            $this->register_field_alias( $tableName, $fieldName, $field );
            $sql = "IF( {$this->_aliases['civicrm_contribution']}.total_amount < 0, '-', '+' )";
            if ( $type === 'fields' ) {
                return $sql . " AS {$field['dbAlias']}";
            }
            return false;
        /*
        case 'simplified_payment_instrument':
            $this->register_field_alias( $tableName, $fieldName, $field );
            $sql = "IF( {$this->_aliases['payment_instrument']}.label LIKE 'Credit Card%', 'Credit Card', {$this->_aliases['payment_instrument']}.label )";
            if ( $type === 'fields' ) {
                return $sql . " AS {$field['dbAlias']}";
            }
            return false;
        */
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
}
