<?php

/**
 * Saved field mapping template for the "Finance Spreadsheet" batch import -
 * see Civi\Import\DataSource\FinanceSpreadsheet and
 * Civi\WMFHook\Import::applyBatchImportDefaults().
 *
 * Column 4 (BALANCE (USD), a running balance) isn't mapped to anything.
 * Columns 1 (Settlement Gateway Account, originally TRANSACTION TYPE), 5
 * (Batch Status) and 6 (Item Count) are computed/synthetic columns the
 * datasource builds itself - see FinanceSpreadsheet.
 */
$importMappings = [
  ['name' => 'Batch.batch_data.settlement_date', 'default_value' => NULL, 'column_number' => 0, 'entity_data' => []],
  ['name' => 'Batch.batch_data.settlement_gateway_account_id', 'default_value' => NULL, 'column_number' => 1, 'entity_data' => []],
  ['name' => 'Batch.title', 'default_value' => NULL, 'column_number' => 2, 'entity_data' => []],
  ['name' => 'Batch.total', 'default_value' => NULL, 'column_number' => 3, 'entity_data' => []],
  ['name' => '', 'default_value' => NULL, 'column_number' => 4, 'entity_data' => []],
  ['name' => 'Batch.status_id', 'default_value' => NULL, 'column_number' => 5, 'entity_data' => []],
  ['name' => 'Batch.item_count', 'default_value' => NULL, 'column_number' => 6, 'entity_data' => []],
];

return [
  [
    // Label shown in "Saved Field Mapping" is this name from the _ onwards -
    // see CRM_CiviImport_Form_DataSource::addMappingSelector().
    'name' => 'import_Finance Spreadsheet',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        'name' => 'import_Finance Spreadsheet',
        // Shared by all generic entity imports - see
        // Civi\Import\GenericParser::getUserJobInfo().
        'job_type' => 'import_generic',
        'is_template' => TRUE,
        'status_id' => 2,
        'metadata' => [
          // Pins the datasource (unlike most templates) since this mapping
          // only makes sense for the Finance Spreadsheet's own columns.
          'submitted_values' => [
            'dataSource' => \Civi\Import\DataSource\FinanceSpreadsheet::class,
            'dateFormats' => \CRM_Utils_Date::DATE_mm_dd_yyyy,
          ],
          // Explicit rather than url-derived, since this template is also
          // reached via a direct ?template_id= link (see
          // managed/Navigation_Import_Finance_Spreadsheet.mgd.php).
          'base_entity' => 'Batch',
          'entity_configuration' => [
            'Batch' => [
              'action' => 'create',
            ],
          ],
          'import_mappings' => $importMappings,
        ],
      ],
    ],
  ],
];
