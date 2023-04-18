<?php
$workplaceTypeID = NULL;
$matchedTypeID = NULL;
$softCreditTypes = $fields = \Civi\Api4\ContributionSoft::getFields(FALSE)
  ->setLoadOptions([
    'id',
    'name',
    'label',
  ])
  ->addWhere('name', '=', 'soft_credit_type_id')
  ->addSelect('options')
  ->execute()->first()['options'];
foreach ($softCreditTypes as $type) {
  if ($type['name'] === 'workplace') {
    $workplaceTypeID = $type['id'];
  }
  if ($type['name'] === 'matched_gift') {
    $matchedTypeID = $type['id'];
  }
}

$individualDedupeRule = CRM_Core_DAO::singleValueQuery("SELECT name FROM civicrm_dedupe_rule_group WHERE is_reserved = 1 AND contact_type = 'Individual' AND used = 'General'");
/**
 * Templates for Matching Gift imports
 */
$entities = [
  [
    // This name doubles as a label from the _ onwards. The Mapping name
    // must match UserJob name but UserJob pre-pends a import_
    'name' => 'import_Matching Gifts Organizations',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        'job_type' => 'contribution_import',
        'status_id' => 2,
        'is_template' => TRUE,
        'name' => 'import_Matching Gifts Organizations',
        'metadata' => [
          'submitted_values' => [
            'contactType' => NULL,
            'contactSubType' => NULL,
            'dateFormats' => '2',
            'dataSource' => 'CRM_Import_DataSource_CSV',
            'use_existing_upload' => NULL,
            'dedupe_rule_id' => NULL,
            'onDuplicate' => '1',
            'disableUSPS' => NULL,
            'doGeocodeAddress' => NULL,
            'multipleCustomData' => NULL,
            'mapper' => [
              ['financial_type_id'],
              ['contribution_extra.gateway_txn_id'],
              ['total_amount'],
              [''],
              ['fee_amount'],
              ['receive_date'],
              ['payment_instrument_id'],
              ['check_number'],
              ['contribution_contact_id'],
              ['organization_name'],
              [''],
              [''],
              [''],
              [''],
              [''],
              [''],
              ['']
            ],
            'skipColumnHeader' => '1',
            'fieldSeparator' => ','
          ],
          'template_id' => 307,
          'Template' => [],
          'DataSource' => [
            'column_headers' => [
              'Batch',
              'Contribution Type',
              'Transaction ID',
              'Total Amount',
              'Source',
              'Fee Amount',
              'Postmark Date',
              'Received Date',
              'Payment Instrument',
              'Check Number',
              'Restrictions',
              'Gift Source',
              'Direct Mail Appeal',
              'Organization CID',
              'Organization Name',
              'Employer of',
              'Soft Credit to First Name',
              'Soft Credit to Last Name',
              'Street Address',
              'Additional Address 1',
              'Additional Address 2',
              'City',
              'State/Province',
              'Postal Code',
              'Country',
              'Phone',
              'Email',
              'AC Flag',
              'Do Not Email',
              'Do Not Mail',
              'Do Not Phone',
              'Do Not SMS',
              'Is Opt Out'
            ],
            'number_of_columns' => 33
          ],
          'entity_configuration' => [
            'Contribution' => [
              'action' => 'create'
            ],
            'Contact' => [
              'action' => 'select',
              'contact_type' => 'Organization',
              'dedupe_rule' => 'OrganizationUnsupervised'
            ],
            'SoftCreditContact' => [
              'contact_type' => 'Individual',
              'soft_credit_type_id' => $matchedTypeID,
              'action' => 'save',
              'dedupe_rule' => $individualDedupeRule,
              'entity' => [
                'entity_data' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ]
          ],
          'import_mappings' => [
            [
              'name' => 'Gift_Information.import_batch_number',
              'default_value' => NULL,
              'column_number' => 0,
              'entity_data' => [
              ]
            ],
            [
              'name' => 'financial_type_id',
              'default_value' => NULL,
              'column_number' => 1,
              'entity_data' => []
            ],
            [
              'name' => 'contribution_extra.gateway_txn_id',
              'default_value' => NULL,
              'column_number' => 2,
              'entity_data' => []
            ],
            [
              'name' => 'total_amount',
              'default_value' => NULL,
              'column_number' => 3,
              'entity_data' => []
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 4,
              'entity_data' => []
            ],
            [
              'name' => 'fee_amount',
              'default_value' => NULL,
              'column_number' => 5,
              'entity_data' => []
            ],
            [
              'name' => 'contribution_extra.Postmark_Date',
              'default_value' => NULL,
              'column_number' => 6,
              'entity_data' => []
            ],
            [
              'name' => 'receive_date',
              'default_value' => NULL,
              'column_number' => 7,
              'entity_data' => []
            ],
            [
              'name' => 'payment_instrument_id',
              'default_value' => NULL,
              'column_number' => 8,
              'entity_data' => []
            ],
            [
              'name' => 'check_number',
              'default_value' => NULL,
              'column_number' => 9,
              'entity_data' => []
            ],
            [
              'name' => 'Gift_Data.Fund',
              'default_value' => NULL,
              'column_number' => 10,
              'entity_data' => []
            ],
            [
              'name' => 'Gift_Data.Campaign',
              'default_value' => NULL,
              'column_number' => 11,
              'entity_data' => []
            ],
            [
              'name' => 'Gift_Data.Appeal',
              'default_value' => NULL,
              'column_number' => 12,
              'entity_data' => []
            ],
            [
              'name' => 'contribution_contact_id',
              'default_value' => NULL,
              'column_number' => 13,
              'entity_data' => []
            ],
            [
              'name' => 'organization_name',
              'default_value' => NULL,
              'column_number' => 14,
              'entity_data' => []
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 15,
              'entity_data' => []
            ],
            [
              'name' => 'soft_credit.contact.first_name',
              'default_value' => NULL,
              'column_number' => 16,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.last_name',
              'default_value' => NULL,
              'column_number' => 17,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.address_primary.street_address',
              'default_value' => NULL,
              'column_number' => 18,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.address_primary.supplemental_address_1',
              'default_value' => NULL,
              'column_number' => 19,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.address_primary.supplemental_address_2',
              'default_value' => NULL,
              'column_number' => 20,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.address_primary.city',
              'default_value' => NULL,
              'column_number' => 21,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.address_primary.state_province_id',
              'default_value' => NULL,
              'column_number' => 22,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.address_primary.postal_code',
              'default_value' => NULL,
              'column_number' => 23,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.address_primary.country_id',
              'default_value' => NULL,
              'column_number' => 24,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.phone_primary.phone',
              'default_value' => NULL,
              'column_number' => 25,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => 'soft_credit.contact.email_primary.email',
              'default_value' => NULL,
              'column_number' => 26,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $matchedTypeID,
                ]
              ]
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 27,
              'entity_data' => []
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 28,
              'entity_data' => []
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 29,
              'entity_data' => []
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 30,
              'entity_data' => []
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 31,
              'entity_data' => []
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 32,
              'entity_data' => []
            ]
          ]
        ],
      ],
    ],
  ],
  [
    'name' => 'import_Matching Gifts Individuals',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        'job_type' => 'contribution_import',
        'status_id' => 2,
        'is_template' => TRUE,
        'name' => 'import_Matching Gifts Individuals',
        'metadata' => [
          'submitted_values' => [
            'contactType' => NULL,
            'contactSubType' => NULL,
            'dateFormats' => '2',
            'dataSource' => 'CRM_Import_DataSource_CSV',
            'use_existing_upload' => NULL,
            'dedupe_rule_id' => NULL,
            'onDuplicate' => '1',
            'disableUSPS' => NULL,
            'doGeocodeAddress' => NULL,
            'multipleCustomData' => NULL,
            'mapper' => [
              ['financial_type_id'],
              ['contribution_extra.gateway_txn_id'],
              ['total_amount'],
              [''],
              ['fee_amount'],
              ['receive_date'],
              ['payment_instrument_id'],
              ['check_number'],
              ['contribution_source'],
              [''],
              [''],
              [''],
              [''],
              [''],
              [''],
              [''],
            ],
            'skipColumnHeader' => '1',
            'fieldSeparator' => ',',
          ],
          'DataSource' => [
            'column_headers' => [
              'Batch',
              'Contribution Type',
              'Transaction ID',
              'Total Amount',
              'Source',
              'Fee Amount',
              'Postmark Date',
              'Received Date',
              'Payment Instrument',
              'Check Number',
              'Restrictions',
              'Gift Source',
              'Direct Mail Appeal',
              'First Name',
              'Last Name',
              'Employee of',
              'Organization Soft Credit CID',
              'Soft Credit to Organization',
              'Street Address',
              'Additional Address 1',
              'Additional Address 2',
              'City',
              'State/Province',
              'Postal Code',
              'Country',
              'Phone',
              'Email',
              'AC Flag',
              'Do Not Email',
              'Do Not Mail',
              'Do Not Phone',
              'Do Not SMS',
              'Is Opt Out',
            ],
            'number_of_columns' => 33,
          ],
          'entity_configuration' => [
            'Contribution' => [
              'action' => 'create',
            ],
            'Contact' => [
              'action' => 'save',
              'contact_type' => 'Individual',
              'dedupe_rule' => $individualDedupeRule,
            ],
            'SoftCreditContact' => [
              'contact_type' => 'Organization',
              'soft_credit_type_id' => $workplaceTypeID,
              'action' => 'save',
              'dedupe_rule' => 'Organization_Name',
              'entity' => [
                'entity_data' => [
                  'soft_credit_type_id' => $workplaceTypeID,
                ],
              ],
            ],
          ],
          'import_mappings' => [
            [
              'name' => 'Gift_Information.import_batch_number',
              'default_value' => NULL,
              'column_number' => 0,
              'entity_data' => [],
            ],
            [
              'name' => 'financial_type_id',
              'default_value' => NULL,
              'column_number' => 1,
              'entity_data' => [],
            ],
            [
              'name' => 'contribution_extra.gateway_txn_id',
              'default_value' => NULL,
              'column_number' => 2,
              'entity_data' => [],
            ],
            [
              'name' => 'total_amount',
              'default_value' => NULL,
              'column_number' => 3,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 4,
              'entity_data' => [],
            ],
            [
              'name' => 'fee_amount',
              'default_value' => NULL,
              'column_number' => 5,
              'entity_data' => [],
            ],
            [
              'name' => 'contribution_extra.Postmark_Date',
              'default_value' => NULL,
              'column_number' => 6,
              'entity_data' => [],
            ],
            [
              'name' => 'receive_date',
              'default_value' => NULL,
              'column_number' => 7,
              'entity_data' => [],
            ],
            [
              'name' => 'payment_instrument_id',
              'default_value' => NULL,
              'column_number' => 8,
              'entity_data' => [],
            ],
            [
              'name' => 'check_number',
              'default_value' => NULL,
              'column_number' => 9,
              'entity_data' => [],
            ],
            [
              'name' => 'Gift_Data.Fund',
              'default_value' => NULL,
              'column_number' => 10,
              'entity_data' => [],
            ],
            [
              'name' => 'contribution_source',
              'default_value' => NULL,
              'column_number' => 11,
              'entity_data' => [],
            ],
            [
              'name' => 'Gift_Data.Appeal',
              'default_value' => NULL,
              'column_number' => 12,
              'entity_data' => [],
            ],
            [
              'name' => 'first_name',
              'default_value' => NULL,
              'column_number' => 13,
              'entity_data' => [],
            ],
            [
              'name' => 'last_name',
              'default_value' => NULL,
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
              'name' => 'soft_credit.contact.id',
              'default_value' => NULL,
              'column_number' => 16,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $workplaceTypeID,
                ],
              ],
            ],
            [
              'name' => 'soft_credit.contact.organization_name',
              'default_value' => NULL,
              'column_number' => 17,
              'entity_data' => [
                'soft_credit' => [
                  'soft_credit_type_id' => $workplaceTypeID,
                ],
              ],
            ],
            [
              'name' => 'address_primary.street_address',
              'default_value' => NULL,
              'column_number' => 18,
              'entity_data' => [],
            ],
            [
              'name' => 'address_primary.supplemental_address_1',
              'default_value' => NULL,
              'column_number' => 19,
              'entity_data' => [],
            ],
            [
              'name' => 'address_primary.supplemental_address_2',
              'default_value' => NULL,
              'column_number' => 20,
              'entity_data' => [],
            ],
            [
              'name' => 'address_primary.city',
              'default_value' => NULL,
              'column_number' => 21,
              'entity_data' => [],
            ],
            [
              'name' => 'address_primary.state_province_id',
              'default_value' => NULL,
              'column_number' => 22,
              'entity_data' => [],
            ],
            [
              'name' => 'address_primary.postal_code',
              'default_value' => NULL,
              'column_number' => 23,
              'entity_data' => [],
            ],
            [
              'name' => 'address_primary.country_id',
              'default_value' => NULL,
              'column_number' => 24,
              'entity_data' => [],
            ],
            [
              'name' => 'phone_primary.phone',
              'default_value' => NULL,
              'column_number' => 25,
              'entity_data' => [],
            ],
            [
              'name' => 'email_primary.email',
              'default_value' => NULL,
              'column_number' => 26,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 27,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 28,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 29,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 30,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 31,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 32,
              'entity_data' => [],
            ],
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
        'mapping_id:name' => 'Import Contribution',
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
