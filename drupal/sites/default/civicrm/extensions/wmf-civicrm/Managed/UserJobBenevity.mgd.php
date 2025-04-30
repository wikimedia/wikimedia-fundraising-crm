<?php

use Civi\WMFHelper\ContributionSoft;
$softCreditTypeID = ContributionSoft::getEmploymentSoftCreditTypes()['matched_gift'];

$entities = [
  [
    'name' => 'UserJob_import_Benevity',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'expires_date' => '2025-04-30 02:03:31',
        'status_id' => 2,
        'name' => 'import_Benevity',
        'job_type' => 'contribution_import',
        'is_template' => TRUE,
        'metadata' => [
          'submitted_values' => [
            'contactType' => 'Individual',
            'contactSubType' => NULL,
            'dateFormats' => '1',
            'savedMapping' => '',
            'dataSource' => 'CRM_Import_DataSource_CSV',
            'use_existing_upload' => NULL,
            'dedupe_rule_id' => NULL,
            'onDuplicate' => '1',
            'disableUSPS' => NULL,
            'doGeocodeAddress' => NULL,
            'multipleCustomData' => NULL,
            'mapper' => [
            ],
            'skipColumnHeader' => '1',
            'uploadFile' => [
              'name' => '/srv/civi-sites/wmff/drupal/sites/default/files/civicrm/upload/benevity_only_match_1c199fd47d23ea5028ccf7f6830b34ea.csv',
              'type' => 'text/csv',
            ],
            'fieldSeparator' => ',',
          ],
          'template_id' => NULL,
          'Template' => [
            'mapping_id' => NULL,
          ],
          'DataSource' => [
            'table_name' => 'civicrm_tmp_d_dflt_7b10a5de6c5f5f8cdf5b4402be92b0d0',
            'column_headers' => [
              'Company',
              'Project',
              'Donation Date',
              'Donor First Name',
              'Donor Last Name',
              'Email',
              'Address',
              'City',
              'State/Province',
              'Postal Code',
              'Comment',
              'Transaction ID',
              'Donation Frequency',
              'Total Donation to be Acknowledged',
              'Match Amount',
              'Merchant Fee',
              'Net Total',
              '',
              '',
            ],
            'number_of_columns' => 16,
          ],
          'entity_configuration' => [
            'Contribution' => [
              'action' => 'create',
            ],
            'Contact' => [
              'action' => 'save',
              'contact_type' => 'Individual',
              'dedupe_rule' => 'IndividualUnsupervised',
            ],
            'SoftCreditContact' => [
              'contact_type' => 'Organization',
              'soft_credit_type_id' => $softCreditTypeID,
              'action' => 'update',
              'dedupe_rule' => 'OrganizationSupervised',
            ],
          ],
          'import_mappings' => [
            [
              'name' => 'soft_credit.contact.organization_name',
              'default_value' => NULL,
              'column_number' => 0,
              'entity_data' => [
                'soft_credit' => [
                  'contact_type' => 'Organization',
                  'soft_credit_type_id' => $softCreditTypeID,
                  'action' => 'update',
                  'dedupe_rule' => 'OrganizationSupervised',
                ],
              ],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 1,
              'entity_data' => [],
            ],
            [
              'name' => 'receive_date',
              'default_value' => NULL,
              'column_number' => 2,
              'entity_data' => [],
            ],
            [
              'name' => 'contact.first_name',
              'default_value' => NULL,
              'column_number' => 3,
              'entity_data' => [],
            ],
            [
              'name' => 'contact.last_name',
              'default_value' => NULL,
              'column_number' => 4,
              'entity_data' => [],
            ],
            [
              'name' => 'contact.email_primary.email',
              'default_value' => NULL,
              'column_number' => 5,
              'entity_data' => [],
            ],
            [
              'name' => 'contact.address_primary.street_address',
              'default_value' => NULL,
              'column_number' => 6,
              'entity_data' => [],
            ],
            [
              'name' => 'contact.address_primary.city',
              'default_value' => NULL,
              'column_number' => 7,
              'entity_data' => [],
            ],
            [
              'name' => 'contact.address_primary.state_province_id',
              'default_value' => NULL,
              'column_number' => 8,
              'entity_data' => [],
            ],
            [
              'name' => 'contact.address_primary.postal_code',
              'default_value' => NULL,
              'column_number' => 9,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 10,
              'entity_data' => [],
            ],
            [
              'name' => 'note',
              'default_value' => NULL,
              'column_number' => 11,
              'entity_data' => [],
            ],
            [
              'name' => 'contribution_extra.gateway_txn_id',
              'default_value' => NULL,
              'column_number' => 12,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 13,
              'entity_data' => [],
            ],
            [
              'name' => 'contribution_extra.original_currency',
              'default_value' => 'USD',
              'column_number' => 14,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 15,
              'entity_data' => [],
            ],
            [
              'name' => 'Gift_Data.Campaign',
              'default_value' => NULL,
              'column_number' => 16,
              'entity_data' => [],
            ],
            [
              'name' => 'contribution_extra.original_amount',
              'default_value' => NULL,
              'column_number' => 17,
              'entity_data' => [],
            ],
            [
              'name' => 'fee_amount',
              'default_value' => NULL,
              'column_number' => 18,
              'entity_data' => [],
            ],
            [
              // We map this for wrangling in the hook, but do not save it
              // to this field (in keeping with historical benevity imports).
              'name' => 'Matching_Gift_Information.Match_Amount',
              'default_value' => NULL,
              'column_number' => 19,
              'entity_data' => [],
            ],
            [
              // We need to map both fee columns because we total
              // them in fee_amount in custom code, and then unset this again.
              'name' => 'contribution_extra.scheme_fee',
              'default_value' => NULL,
              'column_number' => 20,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 21,
              'entity_data' => [],
            ],
            [
              'name' => 'financial_type_id',
              'default_value' => 'Cash',
              'column_number' => 22,
              'entity_data' => [],
            ],
            [
              'name' => 'contribution_extra.gateway',
              'default_value' => 'benevity',
              'column_number' => 23,
              'entity_data' => [],
            ],
          ],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];

$templateName = 'Benevity ';
foreach ($entities as $template) {
  $entities[] = [
    'name' => $templateName,
    'entity' => 'Mapping',
    'cleanup' => 'unused',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        'mapping_type_id:name' => 'Import Contribution',
        'name' => $templateName,
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
          'mapping_id.name' => $templateName,
          'name' => $field[0] ?: 'do_not_import',
          'column_number' => $column,
        ],
      ],
    ];
  }
}

return $entities;
