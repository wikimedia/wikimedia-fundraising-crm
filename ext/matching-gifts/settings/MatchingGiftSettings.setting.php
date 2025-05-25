<?php

return [
  'matching_gifts_employer_data_file_path' => [
    'name' => 'matching_gifts_employer_data_file_path',
    'type' => 'Text',
    'quick_form_type' => 'Element',
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 40,
    ],
    'default' => '/srv/matching_gifts/employers.csv',
    'is_domain' => '1',
    'is_contact' => 0,
    'title' => 'File path for matching gifts employer data file',
    'description' => '',
    'settings_pages' => ['misc' => ['weight' => -80]],
  ],
  'matchinggifts.ssbinfo_credentials' => [
    'name' => 'matchinggifts.ssbinfo_credentials',
    'type' => 'Array',
    'quick_form_type' => 'Element',
    'default' => ['api_key' => 'placeholder'],
    'is_domain' => '1',
    'is_contact' => 0,
    'title' => 'Array containing the api key',
    'description' => '',
  ],
  'ssbinfo_matched_categories' => [
    'name' => 'ssbinfo_matched_categories',
    'type' => 'Array',
    'quick_form_type' => 'Element',
    'default' => [
      'educational_services',
      'educational_funds',
      'libraries',
      'cultural',
    ],
    'is_domain' => '1',
    'is_contact' => 0,
    'title' => 'Array of categories to interact with',
    'description' => '',
  ],
];
