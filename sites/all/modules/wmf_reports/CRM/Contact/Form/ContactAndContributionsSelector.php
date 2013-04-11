<?php

class CRM_Contact_Form_ContactAndContributionsSelector extends CRM_Contribute_Selector_Search
{
    static $contact_properties = array(
        'contact_id',
        'contact_type',
        'sort_name',
        'do_not_email',
        'do_not_phone',
        'do_not_mail',
        'do_not_sms',
        'do_not_trade',
        'is_opt_out',
    );
    static $location_properties = array(
        'address_name',
        'street_address',
        'supplemental_address_1',
        'supplemental_address_2',
        'city',
        'state_province_id',
        'country_id',
        'postal_code',
        'postal_code_suffix',
        'geo_code_1',
        'geo_code_2',
        'is_primary',
        'is_billing',

        'phone',
        'phone_ext',
    );
    static $contribution_properties = array(
        'contribution_id',
        'amount_level',
        'total_amount',
        'contribution_type',
        'contribution_source',
        'receive_date',
        'thankyou_date',
        'contribution_status_id',
        'contribution_status',
        'cancel_date',
        'product_name',
        'is_test',
        'contribution_recur_id',
        'membership_id',
        'currency',
        'contribution_campaign_id',
        'note',
    );
    static $all_location_types;

    function __construct(&$queryParams,
                         $action = CRM_Core_Action::NONE,
                         $contributionClause = null,
                         $single = false,
                         $limit = null,
                         $context = 'search',
                         $compContext = null ) 
    {
        // submitted form values
        $this->_queryParams =& $queryParams;

        $this->_limit   = $limit;
        $this->_context = $context;
        $this->_compContext = $compContext;

        $this->_contributionClause = $contributionClause;

        // type of selector
        $this->_action = $action;

        $returnProperties = CRM_Contribute_BAO_Query::defaultReturnProperties(
            CRM_Contact_BAO_Query::MODE_CONTRIBUTE,
            false
        );
        self::$all_location_types = CRM_Core_PseudoConstant::locationType();
        foreach (self::$all_location_types as $location_type)
        {
            foreach (self::$location_properties as $property)
            {
                $returnProperties['location'][$location_type][$property] = 1;
            }
        }
        $returnProperties = array_merge_recursive(
            $returnProperties,
            self::$contribution_properties,
            self::$contact_properties
        );
        $this->_query = new CRM_Contact_BAO_Query(
            $this->_queryParams,
            $returnProperties,
            null, //array('notes' => 1),
            false,
            false,
            CRM_Contact_BAO_Query::MODE_CONTRIBUTE
        );
        $this->_query->_distinctComponentClause = " civicrm_contribution.id";
        $this->_query->_groupByComponentClause  = " GROUP BY civicrm_contribution.id ";
    }

    function &getRows($action, $offset, $rowCount, $sort, $output = null) {
        $result = $this->_query->searchQuery( $offset, $rowCount, $sort,
                                              false, false, 
                                              false, false, 
                                              false, 
                                              $this->_contributionClause );
        // process the result of the query
        $rows = array( );

        //CRM-4418 check for view/edit/delete
        $permissions = array( CRM_Core_Permission::VIEW );
        if ( CRM_Core_Permission::check( 'edit contributions' ) ) {
            $permissions[] = CRM_Core_Permission::EDIT;
        }
        if ( CRM_Core_Permission::check( 'delete in CiviContribute' ) ) {
            $permissions[] = CRM_Core_Permission::DELETE;
        }
        $mask = CRM_Core_Action::mask( $permissions );
        
        $qfKey = $this->_key;
        $componentId = $componentContext = null;
        if ( $this->_context != 'contribute' ) {
            $qfKey            = CRM_Utils_Request::retrieve( 'key',         'String',   CRM_Core_DAO::$_nullObject ); 
            $componentId      = CRM_Utils_Request::retrieve( 'id',          'Positive', CRM_Core_DAO::$_nullObject );
            $componentAction  = CRM_Utils_Request::retrieve( 'action',      'String',   CRM_Core_DAO::$_nullObject );
            $componentContext = CRM_Utils_Request::retrieve( 'compContext', 'String',   CRM_Core_DAO::$_nullObject );

            if ( ! $componentContext &&
                 $this->_compContext ) {
                $componentContext = $this->_compContext;
                $qfKey = CRM_Utils_Request::retrieve( 'qfKey', 'String', CRM_Core_DAO::$_nullObject, null, false, 'REQUEST' );
            }
        }

        // get all contribution status
        $contributionStatuses = CRM_Core_OptionGroup::values( 'contribution_status', 
                                                              false, false, false, null, 'name', false );
        
        //get all campaigns.
        $allCampaigns = CRM_Campaign_BAO_Campaign::getCampaigns( null, null, false, false, false, true );


        $current_contact = FALSE;
        while ($result->fetch())
        {
#dpm($result);
            $contact_row = array();
            $contribution_row = array();
            if ($result->contact_id != $current_contact)
            {
                $current_contact = $result->contact_id;
                foreach (self::$contact_properties as $property)
                {
                    if ( property_exists( $result, $property ) ) {
                        $contact_row[$property] = $result->$property;   
                    }
                }
                foreach (self::$all_location_types as $location_type)
                {
                    foreach (self::$location_properties as $name)
                    {
                        $property = "{$location_type}-{$name}";
                        if ( property_exists( $result, $property ) ) {
                            $address[$name] = $result->$property;
                        }
                    }

                    $phone_text = $address["phone"];
                    if ($address["phone_ext"])
                        $phone_text .= "x{$address["phone_ext"]}";
                    if ($phone_text)
                        $contact_row['all_phones'][] = $phone_text." ({$location_type})";

                    CRM_Core_BAO_Address::fixAddress($address);
                    $address_text = CRM_Utils_Address::format($address);
                    if ($address_text)
                        $contact_row['all_addresses'][] = $address_text." ({$location_type})";
                }

                $contact_row['contact_type'] = 
                    CRM_Contact_BAO_Contact_Utils::getImage( $result->contact_sub_type ? 
                                                             $result->contact_sub_type : $result->contact_type,false,$result->contact_id );
            
                $contact_row['checkbox'] = CRM_Core_Form::CB_PREFIX . $result->contact_id;
            }
            foreach (self::$contribution_properties as $property)
            {
                if ( property_exists( $result, $property ) ) {
                    $contribution_row[$property] = $result->$property;
                }
            }

            //carry campaign on selectors.
            $contribution_row['campaign'] = CRM_Utils_Array::value( $result->contribution_campaign_id, $allCampaigns );
            $contribution_row['campaign_id'] = $result->contribution_campaign_id;
            
            // add contribution status name
            $contribution_row['contribution_status_name'] = CRM_Utils_Array::value( $contribution_row['contribution_status_id'],
                                                                       $contributionStatuses );

            if ( $result->is_pay_later && CRM_Utils_Array::value( 'contribution_status_name', $contribution_row ) == 'Pending' ) {
                $contribution_row['contribution_status'] .= ' (Pay Later)';
                
            } else if ( CRM_Utils_Array::value( 'contribution_status_name', $contribution_row ) == 'Pending' ) {
                $contribution_row['contribution_status'] .= ' (Incomplete Transaction)';
            }

            if ( $contribution_row['is_test'] ) {
                $contribution_row['contribution_type'] = $contribution_row['contribution_type'] . ' (test)';
            }
            
            
            
            $actions =  array( 'id'               => $result->contribution_id,
                               'cid'              => $result->contact_id,
                               'cxt'              => $this->_context
                               );
            
            $contribution_row['action']       = CRM_Core_Action::formLink(
                self::links(
                    $componentId,
                    $componentAction,
                    $qfKey,
                    $componentContext
                ),
                $mask,
                $actions
            );
            
            if ( CRM_Utils_Array::value( 'amount_level', $contribution_row ) ) {
                CRM_Event_BAO_Participant::fixEventLevel( $contribution_row['amount_level'] );
            }
            
            if ($contact_row)
                $rows[] = $contact_row;
            if ($contribution_row)
                $rows[] = $contribution_row;
        }
#dpm($rows);
        return $rows;
    }    
    
    public function &getColumnHeaders( $action = null, $output = null ) 
    {
        self::$_columnHeaders = array(
            array(
                'desc' => ts('Contact Type')
            ), 
            array( 
                'name'      => ts('Name'), 
            ),
            array(
                'name'      => ts('Amount'),
                'sort'      => 'total_amount',
                'direction' => CRM_Utils_Sort::DONTCARE,
            ),
            array(
                'name'      => ts('Type'),
                'sort'      => 'contribution_type_id',
                'direction' => CRM_Utils_Sort::DONTCARE,
            ),
            array(
                'name'      => ts('Source'),
                'sort'      => 'contribution_source',
                'direction' => CRM_Utils_Sort::DONTCARE,
            ),
            array(
                'name'      => ts('Received'),
                'sort'      => 'receive_date',
                'direction' => CRM_Utils_Sort::DESCENDING,
            ),
            array(
                'name'      => ts('Thank-you Sent'),
                'sort'      => 'thankyou_date',
                'direction' => CRM_Utils_Sort::DONTCARE,
            ),
            array(
                'name'      => ts('Status'),
                'sort'      => 'contribution_status_id',
                'direction' => CRM_Utils_Sort::DONTCARE,
            ),
            array(
                'name'      => ts('Premium'),
                'sort'      => 'product_name',
                'direction' => CRM_Utils_Sort::DONTCARE,
            ),
            array('desc' => ts('Actions') ),
        );

        return self::$_columnHeaders;
    }
    
    /** 
     * name of export file. 
     * 
     * @param string $output type of output 
     * @return string name of the file 
     */ 
    function getExportFileName($output = 'csv')
    { 
        return ts('Contacts and Contributions'); 
    }
}
