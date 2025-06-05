<?php

use Civi\WMFHelper\ContributionSoft;
$softCreditTypeID = ContributionSoft::getDonorAdvisedFundSoftCreditTypes()['donor-advised_fund'];

$entities = [
  [
    'name' => 'UserJob_import_Fidelity',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'import_Fidelity',
        'status_id' => 2,
        'job_type' => 'contribution_import',
        'metadata' => [
          'submitted_values' => [
            'contactType' => NULL,
            'contactSubType' => NULL,
            'dateFormats' => CRM_Utils_Date::DATE_mm_dd_yy,
            'savedMapping' => NULL,
            'dataSource' => 'CRM_Import_DataSource_CSV',
            'use_existing_upload' => '',
            'dedupe_rule_id' => NULL,
            'onDuplicate' => '1',
            'disableUSPS' => NULL,
            'doGeocodeAddress' => NULL,
            'multipleCustomData' => NULL,
            'mapper' => [],
            'file_name' => NULL,
            'skipColumnHeader' => NULL,
            'number_of_rows_to_validate' => NULL,
          ],
          'template_id' => 151,
          'Template' => [
            'mapping_id' => 8,
          ],
          'DataSource' => [
            'column_headers' => [
              'Recommended By',
              'Grant Id',
              'ACH Group Id',
              'Effective Date',
              'Grant Amount',
              'Special Purpose',
              'Giving Account Name',
              'Addressee Name',
              'Acknowledgement Address Line 1',
              'Acknowledgement Address Line 2',
              'Acknowledgement Address Line 3',
              'Acknowledgement City',
              'Acknowledgement State',
              'Acknowledgement ZipCode',
              'Acknowledgement Country',
              'Payable To',
              'Primary Name',
              'Secondary Name',
              'Full Address',
              '',
              '',
            ],
            'number_of_columns' => 19,
            'number_of_rows' => 5,
          ],
          'entity_configuration' => [
            'Contribution' => [
              'action' => 'create',
            ],
            'Contact' => [
              'action' => 'save',
              'contact_type' => 'Organization',
              'dedupe_rule' => 'OrganizationNameAddress',
            ],
            'SoftCreditContact' => [
              'contact_type' => 'Individual',
              'soft_credit_type_id' => $softCreditTypeID,
              'action' => 'save',
              'dedupe_rule' => 'IndividualNameAddress',
            ],
          ],
          'import_mappings' => [
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 0,
              'entity_data' => [],
            ],
            [
              'name' => 'Contribution.contribution_extra.gateway_txn_id',
              'default_value' => NULL,
              'column_number' => 1,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 2,
              'entity_data' => [],
            ],
            [
              'name' => 'Contribution.receive_date',
              'default_value' => NULL,
              'column_number' => 3,
              'entity_data' => [],
            ],
            [
              'name' => 'Contribution.total_amount',
              'default_value' => NULL,
              'column_number' => 4,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 5,
              'entity_data' => [],
            ],
            [
              'name' => 'Contact.organization_name',
              'default_value' => NULL,
              'column_number' => 6,
              'entity_data' => [],
            ],
            [
              'name' => 'SoftCreditContact.full_name',
              'default_value' => NULL,
              'column_number' => 7,
              'entity_data' => [
                'soft_credit' => [
                  'contact_type' => 'Individual',
                  'soft_credit_type_id' => $softCreditTypeID,
                  'action' => 'save',
                  'dedupe_rule' => 'IndividualNameAddress',
                ],
              ],
            ],
            [
              'name' => 'Contact.address_primary.street_address',
              'default_value' => NULL,
              'column_number' => 8,
              'entity_data' => [],
            ],
            [
              'name' => 'Contact.address_primary.supplemental_address_1',
              'default_value' => NULL,
              'column_number' => 9,
              'entity_data' => [],
            ],
            [
              'name' => 'Contact.address_primary.supplemental_address_2',
              'default_value' => NULL,
              'column_number' => 10,
              'entity_data' => [],
            ],
            [
              'name' => 'Contact.address_primary.city',
              'default_value' => NULL,
              'column_number' => 12,
              'entity_data' => [],
            ],
            [
              'name' => 'Contact.address_primary.state_province_id',
              'default_value' => NULL,
              'column_number' => 13,
              'entity_data' => [],
            ],
            [
              'name' => 'Contact.address_primary.postal_code',
              'default_value' => NULL,
              'column_number' => 14,
              'entity_data' => [],
            ],
            [
              'name' => 'Contact.address_primary.country_id',
              'default_value' => 'United States',
              'column_number' => 15,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 16,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 17,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 18,
              'entity_data' => [],
            ],
            [
              'name' => '',
              'default_value' => NULL,
              'column_number' => 19,
              'entity_data' => [],
            ],
            [
              'name' => 'Contribution.contribution_extra.gateway',
              'default_value' => 'fidelity',
              'column_number' => 20,
              'entity_data' => [],
            ],
            [
              'name' => 'Contribution.financial_type_id',
              'default_value' => 'Cash',
              'column_number' => 21,
              'entity_data' => [],
            ],
          ],
        ],
        'is_template' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];

return $entities;
