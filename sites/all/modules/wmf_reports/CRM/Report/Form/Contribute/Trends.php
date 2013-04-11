<?php

class CRM_Report_Form_Contribute_Trends extends CRM_Report_Form {
    function __construct( ) {
        $this->_columns = array(
            'public_reporting_hours' => array(
                'bao' => 'CRM_BAO_PublicReportingHours',
                'fields' => array(
                    'datehour' => array(
                        'title' => ts( 'Hour' ),
                        'default' => true,
                    ),
                    'total' => array(
                        'title' => ts( 'Total' ),
                        'default' => true,
                        'required' => true,
                    ),
                ),
                'filters' => array(
                    'datehour' => array(
                        'title' => ts( 'Date' ),
                        'type' => CRM_Utils_Type::T_DATE,
                        'operatorType' => CRM_Report_Form::OP_DATE,
                    ),
                ),
                'group_bys' => array(
                    'datehour' => array(
                        'title' => ts( 'Hour' ),
                        'default' => true,
                    ),
                ),
            ),
            'civicrm_country' => array(
                'dao' => 'CRM_Core_DAO_Country',
                'fields' => array(
                    'name' => array(
                        'title' => ts( 'Country' ),
                        'default' => true,
                    ),
                    'id' => array(
                        'no_display' => true,
                        'required' => true,
                    ),
                ),
                'filters' => array(
                    'id' => array(
                        'title' => ts( 'Country' ),
                        'type' => CRM_Utils_Type::T_INT,
                        'operatorType' => CRM_Report_Form::OP_MULTISELECT,
                        'options' => CRM_Core_PseudoConstant::country(),
                    ),
                ),
                'group_bys' => array(
                    'id' => array(
                        'title' => ts( 'Country' ),
                        'default' => true,
                    ),
                ),
            ),
        );

        parent::__construct( );
    }

    function select( ) {
        // HACK, is necessary because table aliases do not exist during the constructor.
        $this->_columns['public_reporting_hours']['filters']['datehour']['name'] = "{$this->_aliases['public_reporting_hours']}.datehour";

        $select = array( );
        $this->_columnHeaders = array( );
        foreach ( $this->_columns as $tableName => $table ) {
            if ( array_key_exists('fields', $table) ) {
                foreach ( $table['fields'] as $fieldName => $field ) {
                    if ( CRM_Utils_Array::value( 'required', $field ) ||
                         CRM_Utils_Array::value( $fieldName, $this->_params['fields'] ) ) {
                        
                        $select[] = "{$field['dbAlias']} as {$tableName}_{$fieldName}";
                        $this->_columnHeaders["{$tableName}_{$fieldName}"]['type']  = CRM_Utils_Array::value( 'type', $field );
                        $this->_columnHeaders["{$tableName}_{$fieldName}"]['title'] = CRM_Utils_Array::value( 'title', $field );
                    }
                }
            }
        }

        $select[] = "ROUND( 100 * {$this->_aliases['public_reporting_hours']}.total / previous_hour.total - 100, 0 ) AS frac_change";
        $this->_columnHeaders["frac_change"] = array(
            'type' => CRM_Utils_Type::T_INT,
            'title' => ts( '% change' ),
        );

        $this->_select = "SELECT " . implode( ', ', $select ) . " ";
    }

    function from( ) {
        global $db_prefix;
        $this->_from = <<<EOS
FROM {$db_prefix}public_reporting_hours {$this->_aliases['public_reporting_hours']}
    LEFT JOIN {$db_prefix}public_reporting_hours previous_hour
        ON
            previous_hour.datehour = DATE_SUB( {$this->_aliases['public_reporting_hours']}.datehour, INTERVAL 1 HOUR )
            AND previous_hour.country = {$this->_aliases['public_reporting_hours']}.country
    LEFT JOIN civicrm_country {$this->_aliases['civicrm_country']} 
        ON {$this->_aliases['public_reporting_hours']}.country = {$this->_aliases['civicrm_country']}.iso_code
EOS;
    }

    function groupBy( ) {
        $this->_groupBy = "";
        $append = false;
        foreach ( $this->_columns as $tableName => $table ) {
            if ( array_key_exists('group_bys', $table) ) {
                foreach ( $table['group_bys'] as $fieldName => $field ) {
                    if ( CRM_Utils_Array::value( $fieldName, $this->_params['group_bys'] ) ) {
                        $this->_groupBy[] = $field['dbAlias'];
                    }
                }
            }
        }
        
        if ( !empty($this->_statFields) && 
             (( $append && count($this->_groupBy) <= 1 ) || (!$append)) && !$this->_having ) {
            $this->_rollup = " WITH ROLLUP";
        }
        if ( $this->_groupBy ) {
            $this->_groupBy = "GROUP BY " . implode( ', ', $this->_groupBy ) . " {$this->_rollup} ";
        }
    }
}
