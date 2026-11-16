<?php
return [
  [
    'name' => 'UserJob_import_Citibank_International_Intl_wires_import',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'import_Citibank International (Intl wires) import',
        'status_id:name' => 'draft',
        'job_type' => 'contribution_import',
        'metadata' => [
          'submitted_values' => [
            'contactType' => NULL,
            'contactSubType' => NULL,
            'dateFormats' => '2',
            'savedMapping' => NULL,
            'dataSource' => 'CRM_Import_DataSource_CSV',
            'use_existing_upload' => NULL,
            'dedupe_rule_id' => NULL,
            'onDuplicate' => '1',
            'disableUSPS' => NULL,
            'doGeocodeAddress' => NULL,
            'multipleCustomData' => NULL,
            'mapper' => NULL,
            'skipColumnHeader' => '1',
            'fieldSeparator' => ',',
          ],
          'template_id' => 1441,
          'Template' => [
            'mapping_id' => 255,
          ],
          'DataSource' => [
            'column_headers' => [
              'Posted dt.',
              'Doc Dt.',
              'Doc',
              'Memo/Description',
              'Department',
              'Funding',
              'Txn No',
              'JNL',
              'Curr',
              'Txn amt',
              'Debit',
              'Credit',
              'Balance',
            ],
            'number_of_columns' => 13,
          ],
          'entity_configuration' => [
            'Contribution' => [
              'action' => 'create',
            ],
            'Contact' => [
              'action' => 'select',
              'contact_type' => 'Individual',
              'dedupe_rule' => [
                'IndividualUnsupervised',
              ],
            ],
            'SoftCreditContact' => [
              'contact_type' => 'Individual',
              'soft_credit_type_id' => 1,
              'action' => 'ignore',
              'dedupe_rule' => [
                'IndividualUnsupervised',
              ],
            ],
          ],
          'import_mappings' => [
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 0,
            ],
            [
              'name' => 'Contribution.receive_date',
              'default_value' => NULL,
              'column_number' => 1,
            ],
            [
              'name' => 'Contribution.trxn_id',
              'default_value' => NULL,
              'column_number' => 2,
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 3,
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 4,
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 5,
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 6,
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 7,
            ],
            [
              'name' => 'Contribution.contribution_extra.original_currency',
              'default_value' => NULL,
              'column_number' => 8,
            ],
            [
              'name' => 'Contribution.contribution_extra.original_amount',
              'default_value' => NULL,
              'column_number' => 9,
            ],
            [
              'name' => 'Contribution.total_amount',
              'default_value' => NULL,
              'column_number' => 10,
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 11,
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 12,
            ],
            [
              'name' => 'Contribution.contribution_extra.gateway_account',
              'default_value' => 'citibankpublic2',
              'column_number' => 13,
            ],
            [
              'name' => 'Contribution.financial_type_id',
              'default_value' => 'Cash',
              'column_number' => 14,
            ],
            [
              'name' => 'Contribution.payment_instrument_id',
              'default_value' => 'Citibank International',
              'column_number' => 15,
            ],
            [
              'name' => 'Contribution.Gift_Data.Fund',
              'default_value' => 'Major Gifts - CC104',
              'column_number' => 16,
            ],
            [
              'name' => 'Contribution.Gift_Data.Campaign',
              'default_value' => 'Individual Gift',
              'column_number' => 17,
            ],
            [
              'name' => 'Contribution.contact_id',
              'default_value' => '31398010',
              'column_number' => 18,
            ],
            [
              'name' => 'Contribution.Gift_Data.Appeal',
              'default_value' => 'White Mail',
              'column_number' => 19,
            ],
            [
              'name' => 'Contribution.Gift_Data.Channel',
              'default_value' => 'Other Offline',
              'column_number' => 20,
            ],
          ],
          'import_options' => [
            'date_format' => '2',
          ],
          'bundled_actions' => [],
        ],
        'is_template' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
