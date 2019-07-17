<?php

/**
 * Create WMF specific custom fields.
 *
 * @throws \CiviCRM_API3_Exception
 */
function _wmf_civicrm_update_custom_fields() {
  civicrm_initialize();
  $customGroupSpecs = [
    'wmf_donor' => [
      'group' => [
        'extends' => 'Contact',
        'name' => 'wmf_donor',
        'table_name' => 'wmf_donor',
        'title' => ts('WMF Donor'),
        'is_active' => 1,
        'style' => 'inline',
      ],
      'fields' => _wmf_civicrm_get_wmf_donor_fields(),
    ],
    'Communication' => [
      'group' => [
        'name' => 'Communication',
        'extends' => 'Contact',
        'style' => 'Inline',
        'collapse_display' => 0,
        'title' => 'Communication',
        'table_name' => 'civicrm_value_1_communication_4',
      ],
      'fields' => _wmf_civicrm_get_communication_fields(),
    ],
    'Gift_Data' => [
      'group' => [
        'name' => 'Gift_Data',
        'title' => ts('Gift Data'),
        'extends' => 'Contribution',
        'style' => 'inline',
        'is_active' => 1,
      ],
      'fields' => _wmf_civicrm_get_gift_data_fields(),
    ],
    'Prospect' => [
      'group' => [
        'name' => 'Prospect',
        'title' => 'Prospect',
        'extends' => 'Contact',
        'style' => 'tab',
        'is_active' => 1,
      ],
      'fields' => _wmf_civicrm_get_prospect_fields(),
    ],
    'Anonymous' => [
      'group' => [
        'name' => 'Anonymous',
        'title' => 'Benefactor Page Listing',
        'extends' => 'Contact',
        'style' => 'Inline',
        'is_active' => 1,
      ],
      'fields' => _wmf_civicrm_get_benefactor_fields(),
    ],
    'Partner' => [
      'group' => [
        'name' => 'Partner',
        'title' => 'Partner',
        'extends' => 'Contact',
        'style' => 'Inline',
        'is_active' => 1,
        // Setting weight here is a bit hit & miss but one day the api
        // will do the right thing...
        'weight' => 1,
      ],
      'fields' => _wmf_civicrm_get_partner_fields(),
    ],
  ];
  foreach ($customGroupSpecs as $groupName => $customGroupSpec) {
    $customGroup = civicrm_api3('CustomGroup', 'get', ['name' => $groupName]);
    if (!$customGroup['count']) {
      $customGroup = civicrm_api3('CustomGroup', 'create', $customGroupSpec['group']);
    }
    // We mostly are trying to ensure a unique weight since weighting can be re-ordered in the UI but it gets messy
    // if they are all set to 1.
    $weight = CRM_Core_DAO::singleValueQuery('SELECT max(weight) FROM civicrm_custom_field WHERE custom_group_id = %1',
      [1 => [$customGroup['id'], 'Integer']]
    );

    foreach ($customGroupSpec['fields'] as $index => $field) {
      if (civicrm_api3('CustomField', 'getcount', [
        'custom_group_id' => $customGroup['id'],
        'name' => $field['name'],
      ])
      ) {
        unset($customGroupSpec['fields'][$index]);
      }
      else {
        $weight++;
        $customGroupSpec['fields'][$index]['weight'] = $weight;
      }
    }
    if ($customGroupSpec['fields']) {
      CRM_Core_BAO_CustomField::bulkCreate($customGroupSpec['fields'], ['custom_group_id' => $customGroup['id']]);
    }
  }
  civicrm_api3('System', 'flush', ['triggers' => 0, 'session' => 0]);
}

/**
 * Get fields from prospect custom group.
 *
 * @return array
 */
function _wmf_civicrm_get_benefactor_fields() {
  return [
    'Listed_as_Anonymous' => [
      'name' => 'Listed_as_Anonymous',
      'label' => 'Listed as',
      'data_type' => 'String',
      'html_type' => 'Select',
      'default_value' => 'not_replied',
      'is_searchable' => 1,
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      'option_values' => [
        'anonymous' => 'Anonymous',
        'not_replied' => 'Not replied',
        'public' => 'Public',
      ],
    ],
    'Listed_on_Benefactor_Page_as' => [
      'name' => 'Listed_on_Benefactor_Page_as',
      'label' => 'Listed on Benefactor Page as',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_searchable' => 1,
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
  ];
}

/**
 * Get fields for gift data custom group.
 *
 * @return array
 */
function _wmf_civicrm_get_gift_data_fields() {
  return [
    'Fund' => [
      'name' => 'Fund',
      'column_name' => 'fund',
      'label' => ts('Restrictions'),
      'data_type' => 'String',
      'html_type' => 'Select',
      'default_value' => 'Unrestricted - General',
      'is_active' => 1,
      'is_required' => 1,
      'is_searchable' => 1,
    ],
    'Campaign' => [
      'name' => 'Campaign',
      'column_name' => 'campaign',
      'label' => ts('Gift Source'),
      'data_type' => 'String',
      'html_type' => 'Select',
      'default_value' => 'Community Gift',
      'is_active' => 1,
      'is_required' => 1,
      'is_searchable' => 1,
    ],
    'Appeal' => [
      'name' => 'Appeal',
      'column_name' => 'appeal',
      'label' => ts('Direct Mail Appeal'),
      'data_type' => 'String',
      'html_type' => 'Select',
      'default_value' => 'spontaneousdonation',
      'is_active' => 1,
      'is_required' => 1,
      'is_searchable' => 1,
    ],
  ];
}

/**
 * Get fields from prospect custom group.
 *
 * @return array
 */
function _wmf_civicrm_get_prospect_fields() {
  return [
    'Origin' => [
      'name' => 'Origin',
      'label' => 'Origin',
      'data_type' => 'String',
      'html_type' => 'Select',
      'help_post' => 'How do we know about the prospect?',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      //"option_group_id":"65",
    ],
    'Steward' => [
      'name' => 'Steward',
      'label' => 'Steward',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'default_value' => 5,
      'note_columns' => 60,
      'note_rows' => 4,
      //"option_group_id":"44",
    ],
    'Solicitor' => [
      'name' => 'Solicitor',
      'label' => 'Solicitor',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'note_columns' => 60,
      'note_rows' => 4,
      //"option_group_id":"45",
    ],
    'Prior_WMF_Giving' => [
      'name' => 'Prior_WMF_Giving',
      'label' => 'Prior WMF Giving',
      'data_type' => 'Boolean',
      'html_type' => 'Radio',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'Biography' => [
      'name' => 'Biography',
      'label' => 'Biography',
      "data_type" => 'Memo',
      'html_type' => 'RichTextEditor',
      'is_searchable' => 1,
      'help_post' => 'Who they are, what they do.',
      'attributes' => 'rows=4, cols=60',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'Estimated_Net_Worth' => [
      'name' => 'Estimated_Net_Worth',
      'label' => 'Estimated Net Worth',
      'data_type' => 'String',
      'html_type' => 'Select',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      'option_values' => [
        1 => '$20 Million +',
        2 => '$10 Million - $19.99 Million',
        3 => '$5 Million - $9.99 Million',
        4 => '$2 Million - $4.99 Million',
        5 => '$1 Million - $1.99 Million',
        6 => '$500,000 - $999,999',
      ],
    ],
    'Philanthropic_History' => [
      'name' => 'Philanthropic_History',
      'label' => 'Philanthropic History',
      'data_type' => 'Memo',
      'html_type' => 'RichTextEditor',
      'is_searchable' => 1,
      'attributes' => 'rows=4, cols=60',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'Philanthropic_Interests' => [
      'name' => 'Philanthropic_Interests',
      'label' => 'Philanthropic Interests',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'note_columns' => 60,
      'note_rows' => 4,
      'option_values' => [
        1 => 'Technology',
        2 => 'Education',
        3 => 'Political Campaign',
        4 => 'Cultural Arts',
        5 => 'Poverty',
        6 => 'Environment',
      ],
    ],
    'Subject_Area_Interest' => [
      'name' => 'Subject_Area_Interest',
      'label' => 'Subject Area Interest',
      'data_type' => 'String',
      'html_type' => 'Multi-Select',
      'is_searchable' => 1,
      'help_post' => 'Subject Area, Interests',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      //"option_group_id":"115",
    ],
    'Interests' => [
      'name' => 'Interests',
      'label' => 'Interests',
      'data_type' => 'Memo',
      'html_type' => 'RichTextEditor',
      'is_searchable' => 1,
      'help_post' => 'Pet projects, hobbies, other conversation worthy interests',
      'attributes' => 'rows=4, cols=60',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'University_Affiliation' => [
      'label' => 'University Affiliation',
      'data_type' => 'String',
      'html_type' => 'Multi-Select',
      'is_searchable' => 1,
      'help_post' => 'University Attended, Taught At, Employed By',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      // "option_group_id":"116",
    ],
    'Board_Affiliations' => [
      'name' => 'Board_Affiliations',
      'label' => 'Board Affiliations',
      'data_type' => 'Memo',
      'html_type' => 'RichTextEditor',
      'is_searchable' => 1,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'Notes' => [
      'name' => 'Notes',
      'label' => 'Notes',
      'data_type' => 'Memo',
      'html_type' => 'RichTextEditor',
      'is_searchable' => 1,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'Stage' => [
      'name' => 'Stage',
      'label' => 'MG Stage',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'note_columns' => 60,
      'note_rows' => 4,
      // "option_group_id":"32",
    ],
    'Endowment_Stage' => [
      'name' => 'Endowment_Stage',
      'label' => 'Endowment Stage',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'On Hold' => [
      'name' => 'On_Hold',
      'label' => 'On Hold',
      'data_type' => 'Boolean',
      'html_type' => 'Radio',
      'default_value' => 0,
      'is_searchable' => 1,
      'help_post' => 'Prospects archived from being stewarded',
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'Affinity' => [
      'name' => 'Affinity',
      'label' => 'Affinity',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'default_value' => 'Unknown',
      'note_columns' => 60,
      'note_rows' => 4,
      // "option_group_id":"46",
    ],

    'Capacity' => [
      'name' => 'Capacity',
      'label' => 'Capacity',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'help_post' => 'Low = <$5k\r\nMedium = $5k to $99,999\r\nHigh = >$100k and over',
      'note_columns' => 60,
      'note_rows' => 4,
      // "option_group_id":"34",
    ],
    'Reviewed' => [
      'name' => 'Reviewed',
      'label' => 'Reviewed',
      'data_type' => 'Date',
      'html_type' => 'Select Date',
      'is_searchable' => 1,
      'is_search_range' => 1,
      'start_date_years' => 10,
      'end_date_years' => 1,
      'date_format' => 'mm/dd/yy',
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'Income_Range' => [
      'name' => 'Income_Range',
      'label' => 'Income Range',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      // "option_group_id":"111",
    ],
    'Charitable_Contributions_Decile' => [
      'name' => 'Charitable_Contributions_Decile',
      'label' => 'Charitable Contributions Decile',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      // "option_group_id":"112",
    ],
    'Disc_Income_Decile' => [
      'name' => 'Disc_Income_Decile',
      'label' => 'Disc Income Decile',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      'option_values' => [
        '1' => 'A',
        '2' => 'B',
        '3' => 'C',
        '4' => 'D',
        '5' => 'E',
        '6' => 'F',
        '7' => 'G',
        '8' => 'H',
        '9' => 'I',
        '10' => 'J',
        '11' => 'J',
      ],
    ],
    'Voter_Party' => [
      'name' => 'Voter_Party',
      'label' => 'Voter Party',
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_searchable' => 1,
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
      //"option_group_id":"114",
    ],
    'ask_amount' => [
      'name' => 'ask_amount',
      'label' => 'Ask Amount',
      'data_type' => 'Money',
      'html_type' => 'Text',
      'is_searchable' => 1,
      'is_search_range' => 1,
    ],
    'expected_amount' => [
      'name' => 'expected_amount',
      'label' => 'Expected Amount',
      'data_type' => 'Money',
      'html_type' => 'Text',
      'is_searchable' => 1,
      'is_search_range' => 1,
    ],
    'likelihood' => [
      'name' => 'likelihood',
      'label' => 'Likelihood (%)',
      'data_type' => 'Int',
      'html_type' => 'Text',
      'is_searchable' => 1,
      'is_search_range' => 1,
    ],
    'expected_close_date' => [
      'name' => 'expected_close_date',
      'label' => 'Expected Close Date',
      'data_type' => 'Date',
      'html_type' => 'Select Date',
      'is_searchable' => 1,
      'is_search_range' => 1,
    ],
    'close_date' => [
      'name' => 'close_date',
      'label' => 'Close Date',
      'data_type' => 'Date',
      'html_type' => 'Select Date',
      'is_searchable' => 1,
      'is_search_range' => 1,
    ],
    'next_step' => [
      'name' => 'next_step',
      'label' => 'Next Step',
      'data_type' => 'Memo',
      'html_type' => 'RichTextEditor',
      'note_columns' => 60,
      'note_rows' => 4,
    ],
    'PG_Stage' => [
      'name' => 'PG_Stage',
      'label' => 'PG Stage',
      'data_type' => 'String',
      'html_type' => 'Select',
      'note_columns' => 60,
      'note_rows' => 4,
      'is_searchable' => 1,
      'option_values' => [
        '1' => 'Cultivation',
        '2' => "Cont'd Cultivation",
      ],
    ],
    'Survey_Responses' => [
      'name' => 'Survey_Responses',
      'label' => 'Survey Responses',
      'data_type' => 'String',
      'html_type' => 'Text',
      'note_columns' => 60,
      'note_rows' => 4,
      'help_pre' => 'Data field to store any MGF survey related data for future reference.  Please date appropriately and do not overwrite previous responses.',
    ],
  ];
}

/**
 * Get fields for partner custom group.
 *
 * @return array
 */
function _wmf_civicrm_get_partner_fields() {
  return [
    'Partner' => [
      'name' => 'Partner',
      'label' => 'Partner',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_searchable' => 1,
      'text_length' => 255,
      'note_columns' => 60,
      'note_rows' => 4,
    ],
  ];
}

/**
 * Get fields for communication custom group.
 *
 * @return array
 */
function _wmf_civicrm_get_communication_fields() {
  return [
    'opt_in' => [
      'name' => 'opt_in',
      'column_name' => 'opt_in',
      'label' => ts('Opt In'),
      'data_type' => 'Boolean',
      'html_type' => 'Radio',
      'is_active' => 1,
      'is_searchable' => 0,
    ],
    'do_not_solicit' => [
      'name' => 'do_not_solicit',
      'column_name' => 'do_not_solicit',
      'label' => ts('Do not solicit'),
      'data_type' => 'Boolean',
      'html_type' => 'Radio',
      'is_active' => 1,
      'is_required' => 1,
      'is_searchable' => 1,
      'default_value' => 0,
    ],
    'Employer_Name' => [
      'name' => 'Employer_Name',
      'label' => ts('Employer Name'),
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
      'is_searchable' => 1,
    ],
    'optin_source' => [
      'name' => 'optin_source',
      'label' => ts('Opt-in Source'),
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
      'is_searchable' => 1,
    ],
    'optin_medium' => [
      'name' => 'optin_medium',
      'label' => ts('Opt-in Medium'),
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
      'is_searchable' => 1,
    ],
    'optin_campaign' => [
      'name' => 'optin_campaign',
      'label' => ts('Opt-in Campaign'),
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
      'is_searchable' => 1,
    ],
  ];
}

/**
 * Get the legacy wmf donor fields we want to remove.
 *
 * @return array
 */
function _wmf_civicrm_get_wmf_donor_fields_to_remove() {
  $fields = [];
  for ($year = WMF_MIN_ROLLUP_YEAR; $year <= WMF_MAX_ROLLUP_YEAR; $year++) {
    $fields["is_{$year}_donor"] = "is_{$year}_donor";
  }
  $fields['do_not_solicit_old'] = 'do_not_solicit_old';
  return $fields;
}
