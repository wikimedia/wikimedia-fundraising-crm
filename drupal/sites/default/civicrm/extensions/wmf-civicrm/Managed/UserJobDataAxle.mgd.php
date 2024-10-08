<?php

use Civi\Api4\CustomField;

// Import is based on Wiki7808037.csv - one of 2 files to import.

$customFields = CustomField::get(FALSE)->addWhere('name', 'LIKE', 'data%')->addSelect('id', 'name')->execute()->indexBy('name');

// Add the Data-axle mapping - all the fields are listed for visibility
$mappedFields = [
  'contact_id' => 'id',
  'first_name' => 'do_not_import',
  'last_name' => 'do_not_import',
  'street_address' => 'do_not_import',
  'city' => 'do_not_import',
  'state' => 'do_not_import',
  'zip' => 'do_not_import',
  'email' => 'do_not_import',
  'phone' => 'do_not_import',
  'birth_date' => 'do_not_import',
  'AH1_mb_zip4_primary_number' => 'do_not_import',
  'AH1_mb_zip4_pre_direction' => 'do_not_import',
  'AH1_mb_zip4_primary_name' => 'do_not_import',
  'AH1_mb_zip4_street_suffix' => 'do_not_import',
  'AH1_mb_zip4_post_direction' => 'do_not_import',
  'AH1_mb_zip4_unit_type' => 'do_not_import',
  'AH1_mb_zip4_unit_number' => 'do_not_import',
  'AH1_mb_zip4_city_name' => 'do_not_import',
  'AH1_mb_zip4_state_abbreviation' => 'do_not_import',
  'AH1_mb_zip4_zip_code' => 'do_not_import',
  'AH1_mb_zip4_zip4_code' => 'do_not_import',
  'AH1_mb_zip4_advanced_bar_code_and_check_digit' => 'do_not_import',
  'AH1_mb_zip4_carrier_code' => 'do_not_import',
  'AH1_mb_zip4_zip4_match_level' => 'do_not_import',
  'AH1_mb_zip4_primary_number_is_a_box' => 'do_not_import',
  'AH1_mb_zip4_zip_code_status' => 'do_not_import',
  'AH1_mb_zip4_city_name_has_changed' => 'do_not_import',
  'AH1_mb_zip4_line_of_travel_information' => 'do_not_import',
  'AH1_mb_zip4_lot_sortation_number' => 'do_not_import',
  'AH1_mb_zip4_state_code' => 'do_not_import',
  'AH1_mb_zip4_county_code' => 'do_not_import',
  'AH1_mb_zip4_lacs_indicator' => 'do_not_import',
  'AH1_mb_zip4_urbanization_code_for_puerto_rico' => 'do_not_import',
  'AH1_mb_zip4_unit_return_code_from_finalist' => 'do_not_import',
  'AH1_mb_zip4_FILLER' => 'do_not_import',
  'AH1_mb_zip4_vendor_source' => 'do_not_import',
  'AH1_mb_zip4_city_type_indicator' => 'do_not_import',
  'AH1_mb_zip4_address_type_indicator' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_aa' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_a1' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_a2' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_a3' => 'do_not_import',
  'AH1_mb_zip4_footnotes___expansion1' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_d' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_e' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_f' => 'do_not_import',
  'AH1_mb_zip4_footnotes___expansion2' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_h' => 'do_not_import',
  'AH1_mb_zip4_footnotes___expansion3' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_j' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_k' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_k1' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_k2' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_l' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_m1' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_m2' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_n1' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_n2' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_p1' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_p2' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_q1' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_q2' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_m3' => 'do_not_import',
  'AH1_mb_zip4_footnotes_footnote_m4' => 'do_not_import',
  'AH1_mb_zip4_footnotes___expansion4' => 'do_not_import',
  'AH1_mb_zip4_footnotes_ews' => 'do_not_import',
  'AH1_mb_coa_match_level' => 'do_not_import',
  'AH1_mb_coa_coa_move_type' => 'do_not_import',
  'AH1_mb_coa_coa_effective_move_date' => 'do_not_import',
  'AH1_mb_coa_delivery_code' => 'do_not_import',
  'AH1_mb_coa_primary_number' => 'do_not_import',
  'AH1_mb_coa_pre_direction' => 'do_not_import',
  'AH1_mb_coa_primary_name' => 'do_not_import',
  'AH1_mb_coa_street_suffix' => 'do_not_import',
  'AH1_mb_coa_post_direction' => 'do_not_import',
  'AH1_mb_coa_unit_type' => 'do_not_import',
  'AH1_mb_coa_unit_number' => 'do_not_import',
  'AH1_mb_coa_city_name' => 'do_not_import',
  'AH1_mb_coa_state_abbreviation' => 'do_not_import',
  'AH1_mb_coa_zip_code' => 'do_not_import',
  'AH1_mb_coa_zip4_addon' => 'do_not_import',
  'AH1_mb_coa_delivery_point_and_check_digit' => 'do_not_import',
  'AH1_mb_coa_carrier_route_code' => 'do_not_import',
  'AH1_mb_coa_zip4_match_level' => 'do_not_import',
  'AH1_mb_coa_primary_number_is_a_box' => 'do_not_import',
  'AH1_mb_coa_urbanization_code' => 'do_not_import',
  'AH1_mb_coa_record_type' => 'do_not_import',
  'AH1_mb_coa_multi_source_match' => 'do_not_import',
  'AH1_mb_coa_reserved' => 'do_not_import',
  'AH1_mb_coa_individual_match_logic_required' => 'do_not_import',
  'AH1_mb_coa_ncoalink_return_code' => 'do_not_import',
  'AH1_mb_coa___expansion1' => 'do_not_import',
  'AH1_mb_coa_query_prefix_title' => 'do_not_import',
  'AH1_mb_coa_query_given_name' => 'do_not_import',
  'AH1_mb_coa_query_middle_name' => 'do_not_import',
  'AH1_mb_coa_query_surname' => 'do_not_import',
  'AH1_mb_coa_query_surname_suffix' => 'do_not_import',
  'AH1_mb_coa___expansion2' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_aa' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_a1' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_bb' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_cc' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_n1' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_m1' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_m3' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_p1' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_rr' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_r1' => 'do_not_import',
  'AH1_mb_dpv_dpv_confirmation_indicator' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_p3' => 'do_not_import',
  'AH1_mb_dpv_dpv_lacs_indicator' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_f1' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_g1' => 'do_not_import',
  'AH1_mb_dpv_dpv_footnote_u1' => 'do_not_import',
  'AH1_mb_dpv_dsf2_match_level' => 'do_not_import',
  'AH1_mb_dpv_dsf2_pseudo_sequence' => 'do_not_import',
  'AH1_mb_dpv___expansion2' => 'do_not_import',
  'AH1_mb_dpv_dsf2_educational_indicator' => 'do_not_import',
  'AH1_mb_dpv_dsf2_vacant' => 'do_not_import',
  'AH1_mb_dpv_dsf2_seasonal' => 'do_not_import',
  'AH1_mb_dpv_dsf2_residential_business_general_delivery' => 'do_not_import',
  'AH1_mb_dpv_dsf2_throwback' => 'do_not_import',
  'AH1_mb_dpv_dsf2_delivery_type' => 'do_not_import',
  'AH1_mb_dpv_dsf2_delivery_drop' => 'do_not_import',
  'AH1_mb_dpv_dsf2_delivery_drop_count' => 'do_not_import',
  'AH1_mb_dpv_dsf2_lacs_indicator' => 'do_not_import',
  'AH1_mb_dpv_dsf2_no_stat' => 'do_not_import',
  'AH1_mb_dpv___expansion3' => 'do_not_import',
  'AH1_mb_mailing_address_address_source_code' => 'do_not_import',
  'AH1_mb_mailing_address_address_status_delivery_code' => 'do_not_import',
  'AH1_mb_mailing_address_pander_code' => 'do_not_import',
  'AH1_mb_mailing_address_local_address_line_1' => 'do_not_import',
  'AH1_mb_mailing_address_unit_information_line' => 'do_not_import',
  'AH1_mb_mailing_address_secondary_address_line' => 'do_not_import',
  'AH1_mb_mailing_address_long_city_name' => 'do_not_import',
  'AH1_mb_mailing_address_short_city_name' => 'do_not_import',
  'AH1_mb_mailing_address_state' => 'do_not_import',
  'AH1_mb_mailing_address_zip_code' => 'do_not_import',
  'AH1_mb_mailing_address_zip_four' => 'do_not_import',
  'AH1_mb_mailing_address___expansion1' => 'do_not_import',
  'AH1_mb_mailing_address_mailability_score' => 'do_not_import',
  'AH1_mb_mailing_address___expansion2' => 'do_not_import',
  'AH1_mb_mailing_address_military_zip_code' => 'do_not_import',
  'AH1_mb_mailing_address_opac_match_indicator' => 'do_not_import',
  'AH1_mb_mailing_address_ndi_affirmed_apt_indicator' => 'do_not_import',
  'AH1_mb_mailing_address_secondary_address_indicator' => 'do_not_import',
  'AH1_mb_mailing_address_state_code' => 'do_not_import',
  'AH1_mb_mailing_address_county_code' => 'do_not_import',
  'AH1_mb_mailing_address_long_city_indicator' => 'do_not_import',
  'AH1_mb_mailing_address_carrier_route_code' => 'do_not_import',
  'AH1_mb_mailing_address_line_of_travel_information' => 'do_not_import',
  'AH1_mb_mailing_address_lot_sortation_number' => 'do_not_import',
  'AH1_mb_mailing_address_prestige_city_name' => 'do_not_import',
  'AH1_mb_mailing_address_zip_addon_delivery_point' => 'do_not_import',
  'DC_DeceasedFlag' => 'do_not_import',
  'CE_Match_Level' => 'do_not_import',
  'CE_Match_Score' => 'do_not_import',
  'CE_Household_Status_Code' => 'do_not_import',
  'CE_Location_ID' => 'do_not_import',
  'CE_Household_ID' => 'do_not_import',
  'CE_Individual_HoH_Individual_ID' => 'do_not_import',
  'CE_Selected_Individual_ID' => 'do_not_import',
  'CE_Family_Income_Detector' => 'do_not_import',
  'CE_Family_Income_Detector_Code' => 'do_not_import',
  'CE_Family_Income_Detector_Ranges' => 'do_not_import',
  'CE_Wealthfinder_Code' => 'custom_' . $customFields['data_axle_net_worth']['id'],
  'CE_Wealthfinder_Score' => 'do_not_import',
  'CE_Home_Value_Code' => 'do_not_import',
  'CE_Selected_Age' => 'do_not_import',
  'CE_Selected_Age_Code' => 'do_not_import',
  'CE_Selected_Individual_YYYYMMDD_Of_Birth' => 'do_not_import',
  'CE_Selected_Individual_YYYY_Year_Of_Birth' => 'do_not_import',
  'CE_Selected_Individual_MM_Month_Of_Birth' => 'do_not_import',
  'CE_Selected_Individual_DD_Day_Of_Birth' => 'do_not_import',
  'CE_Selected_Individual_Grandparent_Flag' => 'custom_' . $customFields['data_axle_is_grandparent']['id'],
  'CE_Grandparent_Present_Flag' => 'do_not_import',
  'CE_Children_Present_Flag' => 'do_not_import',
  'CE_Member_Count' => 'do_not_import',
  'CE_LHI_Household_Charitable_Donor' => 'custom_' . $customFields['data_axle_donation_interest']['id'],
  'CE_Selected_Individual_Parent_Flag' => 'custom_' . $customFields['data_axle_is_parent']['id'],
  'CE_High_Value_Security_Investor_Model' => 'custom_' . $customFields['data_axle_security_investor_likelihood']['id'],
  'CE_Number_Children' => 'custom_' . $customFields['data_axle_number_children']['id'],
  'CE_Investments_Interest_Flag' => 'do_not_import',
  'CE_Stocks_Bonds_Investments_Flag' => 'do_not_import',
  'CE_Bank_Card_Holder_Flag' => 'do_not_import',
  'CE_Books_Music_Interest_Flag' => 'do_not_import',
  'CE_Consumer_Stability_Index_Raw_Score' => 'do_not_import',
  'CE_Selected_Individual_Has_Finance_Card' => 'do_not_import',
  'CE_Discretionary_Income_Score' => 'do_not_import',
  'CE_Environment_Contributor_Flag' => 'do_not_import',
  'CE_Donor_Ever_Contributor_Flag' => 'do_not_import',
  'CE_Health_Contributor_Flag' => 'do_not_import',
  'CE_Education_Model' => 'do_not_import',
  'CE_Education_Model_Desc' => 'do_not_import',
  'CE_Selected_Individual_Vendor_Ethnicity_Code' => 'do_not_import',
  'CE_Selected_Individual_Vendor_Ethnic_Group_Code' => 'do_not_import',
  'CE_Expendable_Income_Rank_Code' => 'custom_' . $customFields['data_axle_expendable_income']['id'],
  'CE_Selected_Gender' => 'do_not_import',
  'CE_Golfer_Flag' => 'do_not_import',
  'CE_Gourmet_Food_Wine_Interest_Flag' => 'do_not_import',
  'CE_Health_Fitness_Interest_Flag' => 'do_not_import',
  'CE_Heavy_Internet_User_Model' => 'do_not_import',
  'CE_Home_Equity_Estimate_Code' => 'do_not_import',
  'CE_Own_Rent_Likelihood_Code' => 'do_not_import',
  'CE_Home_Owner_Flag' => 'custom_' . $customFields['data_axle_homeowner_investor_likelihood']['id'],
  'CE_Homeowner_Source_Code' => 'do_not_import',
  'CE_Income_Producing_Assets' => 'do_not_import',
  'CE_Income_Producing_Assets_Desc' => 'do_not_import',
  'CE_Selected_Individual_Marital_Status_Code' => $customFields['data_axle_marital_status']['id'],
  'CE_County_Nielsen_Rank_Code' => 'do_not_import',
  'CE_County_Nielsen_Region_Code' => 'do_not_import',
  'CE_Purchasing_Power_Income_Detector' => 'do_not_import',
  'CE_Purchasing_Power_Income_Code' => 'do_not_import',
  'CE_Adventure_Seekers_Model' => 'do_not_import',
  'CE_Alternative_Medicine_Model' => 'do_not_import',
  'CE_Conservative_Model' => 'do_not_import',
  'CE_Cruise_Model' => 'do_not_import',
  'CE_Donors_PBS_NPR_Model' => 'do_not_import',
  'CE_Education_Loan_Model' => 'do_not_import',
  'CE_Green_Model' => 'do_not_import',
  'CE_Health_Insurance_Model' => 'do_not_import',
  'CE_Heavy_Payperview_Movie_Model' => 'do_not_import',
  'CE_Non_Religious_Donor_Model' => 'do_not_import',
  'CE_Heavy_Payperview_Sports_Model' => 'do_not_import',
  'CE_Home_Office_Model' => 'do_not_import',
  'CE_Luxury_Car_Buyer_Model' => 'do_not_import',
  'CE_Apparel_Interest_Flag' => 'do_not_import',
  'CE_High_End_Apparel_Model' => 'do_not_import',
  'CE_Loan_to_Value_Ratio_Range_Code' => 'do_not_import',
  'CE_High_Value_Stock_Investor_Model' => 'custom_' . $customFields['data_axle_stock_investor_likelihood']['id'],
  'CE_Higher_Education_Model' => 'do_not_import',
  'CE_Hybrid_Cars_Model' => 'do_not_import',
  'CE_Leaning_Conservative_Model' => 'do_not_import',
  'CE_Liberal_Model' => 'do_not_import',
  'CE_Luxury_Hotel_Model' => 'do_not_import',
  'CE_Religious_Donor_Model' => 'do_not_import',
  'CE_Social_Media_Network_Model' => 'do_not_import',
  'CE_Home_Decorating_Interest_Flag' => 'do_not_import',
  'CE_Pensioner_Present_Flag' => 'do_not_import',
  'CE_Pet_Owner_Flag' => 'do_not_import',
  'GE_ALSSESICode' => 'do_not_import',
];
$mapper = [];
foreach ($mappedFields as $mappedField) {
  $mapper[] = [$mappedField];
}
$entities = [
  [
    'name' => 'import_data-axle-1',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        // We have this in code - we can expire it out & still re-use?
        'expires_date' => '2024-04-01 01:01:01',
        'status_id' => 1,
        'job_type' => 'contact_import',
        'name' => 'import_data-axle-1',
        'is_template' => TRUE,
        'metadata' => [
          'submitted_values' => [
            'contactType' => 'Individual',
            'contactSubType' => '',
            'dateFormats' => '1',
            'savedMapping' => '3',
            'dataSource' => 'Civi\Import\DataSource\UploadedFile',
            'use_existing_upload' => NULL,
            'dedupe_rule_id' => '',
            'onDuplicate' => '4',
            'disableUSPS' => NULL,
            'doGeocodeAddress' => NULL,
            'multipleCustomData' => NULL,
            'isFirstRowHeader' => '1',
            'number_of_rows_to_validate' => '10',
            'mapper' => $mapper,
          ],
          'DataSource' => [
            'column_headers' => array_keys($mappedFields),
            'column_count' => count($mappedFields),
          ],
        ],
      ],
    ],
  ],
];
foreach ($entities as $template) {
  $entities[] = [
    'name' => substr($template['name'], 7),
    'entity' => 'Mapping',
    'cleanup' => 'unused',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        'mapping_type_id:name' => 'Import Contact',
        'name' => substr($template['name'], 7),
      ],
    ],
  ];

  foreach ($template['params']['values']['metadata']['submitted_values']['mapper'] as $column => $field) {
    $entities[] = [

      'name' => $template['name'] . '_' . $column,
      'entity' => 'MappingField',
      'cleanup' => 'unused',
      'update' => 'never',
      'params' => [
        'version' => 4,
        'match' => ['mapping_id', 'column_number'],
        'values' => [
          'mapping_id.name' => substr($template['name'], 7),
          'name' => $field[0],
          'column_number' => $column,
        ],
      ],
    ];
  }
}

return $entities;
