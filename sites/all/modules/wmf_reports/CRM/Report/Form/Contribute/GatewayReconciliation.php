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
            'GLOBALCOLLECT' => 'Globalcollect',
            'PAYPAL' => 'Paypal',
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
            'contribution_tracking' => array(
                'bao' => 'CRM_BAO_ContributionTracking',
                'fields' => array(
                    'country' => array(
                        'title' => ts( 'Country' ),
                        'default' => false,
                    ),
                ),
                'filters' => array(
                    'country' => array(
                        'title' => ts( 'Country' ),
                        'type' => CRM_Utils_Type::T_STRING,
                        'operatorType' => CRM_Report_Form::OP_STRING,
                    ),
                ),
                'group_bys' => array(
                    'country' => array(
                        'title' => ts( 'Country' ),
                        'default' => false,
                    ),
                ),
            ),
        );

        parent::__construct( );
    }

    function from( ) {
        $dbs = new db_switcher();
        $drupalprefix = $dbs->get_prefix( "default" );

        $this->_from = <<<EOS
FROM civicrm_contribution {$this->_aliases['civicrm_contribution']}
LEFT JOIN wmf_contribution_extra {$this->_aliases['wmf_contribution_extra']}
    ON {$this->_aliases['wmf_contribution_extra']}.entity_id = {$this->_aliases['civicrm_contribution']}.id
EOS;
        if ( $this->_submitValues['country_value']
            or $this->_submitValues['group_bys']['country'] )
        {
            $this->_from .= <<<EOS
\nLEFT JOIN {$drupalprefix}contribution_tracking {$this->_aliases['contribution_tracking']} 
    ON {$this->_aliases['contribution_tracking']}.contribution_id = {$this->_aliases['civicrm_contribution']}.id
EOS;
        }
    }

    function modifyColumnHeaders() {
        if ( !array_key_exists( 'is_negative', $this->_submitValues['group_bys'] ) ) {
            unset( $this->_columnHeaders['civicrm_contribution_is_negative'] );
        }
        if ( !array_key_exists( 'gateway_account', $this->_submitValues['group_bys'] ) ) {
            unset( $this->_columnHeaders['wmf_contribution_extra_gateway_account'] );
        }
    }

    // hack taken from http://issues.civicrm.org/jira/browse/CRM-9505
    function addDateRange( $name, $label = 'From:', $dateFormat = 'searchDate', $required = false ) {
        $this->addDateTime( $name . '_from', $label , $required, array( 'formatType' => $dateFormat ) );
        $this->addDateTime( $name . '_to' , ts('To:'), $required, array( 'formatType' => $dateFormat ) );
    }

    function selectClause( $tableName, $type, $fieldName, &$field ) {
        // until the base class takes care of it:
        $register_field_alias = function( &$field ) use ( $tableName, $fieldName ) {
            //if ( !CRM_Utils_Array::value( 'dbAlias', $field ) ) {
            $field['dbAlias'] = "{$tableName}_{$fieldName}";
            if ( array_key_exists('group_bys', $this->_columns[$tableName])
                and array_key_exists($fieldName, $this->_columns[$tableName]['group_bys']) )
            {
                $this->_columns[$tableName]['group_bys'][$fieldName]['dbAlias'] = $field['dbAlias'];
            }
            $this->_columnHeaders[$field['dbAlias']]['title'] = CRM_Utils_Array::value( 'title', $field );
            $this->_columnHeaders[$field['dbAlias']]['type'] = CRM_Utils_Array::value( 'type', $field );
            $this->_selectAliases[] = $field['dbAlias'];
        };

        switch ( $fieldName ) {
        case 'is_negative':
            $register_field_alias( $field );
            $sql = "IF( {$this->_aliases['civicrm_contribution']}.total_amount < 0, '-', '+' )";
            if ( $type === 'fields' ) {
                $sql .= " AS {$field['dbAlias']}";
            }
            return $sql;
        }
        return parent::selectClause( $tableName, $type, $fieldName, $field );
    }
}
