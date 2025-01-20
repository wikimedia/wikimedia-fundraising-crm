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
];
