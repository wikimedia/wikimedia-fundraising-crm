<?php

/**
 * Saved field mapping template for Fidelity grants paid to the Endowment.
 *
 * ACH Group Id maps to import_batch_number, which
 * Civi\WMFHook\Import::getFidelityBatchName() uses to link each row to the
 * ACH batch imported from the Finance Spreadsheet.
 */
use Civi\WMFHelper\ContributionSoft;

$softCreditTypeID = ContributionSoft::getDonorAdvisedFundSoftCreditTypes()['donor-advised_fund'];

return [
  [
    'name' => 'UserJob_import_Fidelity_END',
    'entity' => 'UserJob',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'import_Fidelity END',
        'status_id:name' => 'draft',
        'job_type' => 'contribution_import',
        'metadata' => [
          'submitted_values' => [
            'contactType' => NULL,
            'contactSubType' => NULL,
            'dateFormats' => NULL,
            'savedMapping' => NULL,
            'dataSource' => 'CRM_Import_DataSource_CSV',
            'use_existing_upload' => NULL,
            'dedupe_rule_id' => NULL,
            'onDuplicate' => NULL,
            'disableUSPS' => NULL,
            'doGeocodeAddress' => NULL,
            'multipleCustomData' => NULL,
            'mapper' => NULL,
            'skipColumnHeader' => '1',
            'fieldSeparator' => ',',
          ],
          'Template' => ['mapping_id' => NULL],
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
          ],
          'entity_configuration' => [
            'Contribution' => ['action' => 'create'],
            'Contact' => [
              'action' => 'save',
              'contact_type' => 'Organization',
              'dedupe_rule' => ['OrganizationNameAddress'],
            ],
            'SoftCreditContact' => [
              'contact_type' => 'Individual',
              'soft_credit_type_id' => $softCreditTypeID,
              'action' => 'save',
              'dedupe_rule' => ['IndividualNameAddress.first'],
            ],
          ],
          'import_mappings' => [
            ['name' => '', 'default_value' => NULL, 'column_number' => 0],
            ['name' => 'Contribution.contribution_extra.gateway_txn_id', 'default_value' => NULL, 'column_number' => 1],
            ['name' => 'Contribution.Gift_Information.import_batch_number', 'default_value' => NULL, 'column_number' => 2],
            ['name' => 'Contribution.receive_date', 'default_value' => NULL, 'column_number' => 3],
            ['name' => 'Contribution.total_amount', 'default_value' => NULL, 'column_number' => 4],
            ['name' => '', 'default_value' => NULL, 'column_number' => 5],
            ['name' => 'Contact.organization_name', 'default_value' => NULL, 'column_number' => 6],
            ['name' => 'SoftCreditContact.full_name', 'default_value' => NULL, 'column_number' => 7],
            ['name' => 'Contact.address_primary.street_address', 'default_value' => NULL, 'column_number' => 8],
            ['name' => 'SoftCreditContact.address_primary.supplemental_address_1', 'default_value' => NULL, 'column_number' => 9],
            ['name' => 'SoftCreditContact.address_primary.supplemental_address_2', 'default_value' => NULL, 'column_number' => 10],
            ['name' => 'SoftCreditContact.address_primary.city', 'default_value' => NULL, 'column_number' => 11],
            ['name' => 'Contact.address_primary.state_province_id', 'default_value' => NULL, 'column_number' => 12],
            ['name' => 'Contact.address_primary.postal_code', 'default_value' => NULL, 'column_number' => 13],
            ['name' => 'Contact.address_primary.country_id', 'default_value' => 'United States', 'column_number' => 14],
            ['name' => '', 'default_value' => NULL, 'column_number' => 15],
            ['name' => '', 'default_value' => NULL, 'column_number' => 16],
            ['name' => '', 'default_value' => NULL, 'column_number' => 17],
            ['name' => '', 'default_value' => NULL, 'column_number' => 18],
            ['name' => 'Contribution.contribution_extra.gateway_account', 'default_value' => 'achendowment', 'column_number' => 19],
            ['name' => 'Contribution.financial_type_id', 'default_value' => 'Endowment Gift', 'column_number' => 20],
            ['name' => 'Contribution.payment_instrument_id', 'default_value' => 'EFT', 'column_number' => 21],
            ['name' => 'Contribution.Gift_Data.Fund', 'default_value' => 'Endowment Fund', 'column_number' => 22],
            ['name' => 'Contribution.Gift_Data.Channel', 'default_value' => 'Other Offline', 'column_number' => 23],
            ['name' => 'Contribution.Gift_Data.Campaign', 'default_value' => 'Donor Advised Fund', 'column_number' => 24],
            ['name' => 'Contribution.Gift_Data.Appeal', 'default_value' => 'White Mail', 'column_number' => 25],
            ['name' => 'Contribution.contribution_extra.backend_processor', 'default_value' => 'Fidelity', 'column_number' => 26],
          ],
          'import_options' => ['date_format' => CRM_Utils_Date::DATE_mm_dd_yyyy],
          'bundled_actions' => [
            [
              'entity' => 'SoftCreditContact',
              'action' => 'add_to_group.imported_duplicates',
              'condition' => 'on_multiple_match',
            ],
          ],
        ],
        'is_template' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
];
