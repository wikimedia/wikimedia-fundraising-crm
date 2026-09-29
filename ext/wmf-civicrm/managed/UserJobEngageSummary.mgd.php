<?php

/**
 * Saved field mapping template for the "Engage Summary" batch import - see
 * Civi\Import\DataSource\EngageSummary and
 * Civi\WMFHook\Import::applyBatchImportDefaults().
 *
 * Docs/Checks (columns 4/5) aren't mapped - Total Item feeds item_count.
 * Batch Type (payment instrument, column 0) isn't mapped either:
 * Batch.payment_instrument_id has no 'import' usage in core's own
 * Batch.entityType.php, so it isn't importable without a core fix.
 * Columns 1 (Settlement Gateway Account) and 8 (Batch Status) are synthetic
 * columns the datasource appends itself - see EngageSummary.
 */
$importMappings = [
  ['name' => '', 'default_value' => NULL, 'column_number' => 0, 'entity_data' => []],
  ['name' => 'Batch.batch_data.settlement_gateway_account_id', 'default_value' => NULL, 'column_number' => 1, 'entity_data' => []],
  ['name' => 'Batch.title', 'default_value' => NULL, 'column_number' => 2, 'entity_data' => []],
  ['name' => 'Batch.batch_data.settlement_date', 'default_value' => NULL, 'column_number' => 3, 'entity_data' => []],
  ['name' => '', 'default_value' => NULL, 'column_number' => 4, 'entity_data' => []],
  ['name' => '', 'default_value' => NULL, 'column_number' => 5, 'entity_data' => []],
  ['name' => 'Batch.item_count', 'default_value' => NULL, 'column_number' => 6, 'entity_data' => []],
  ['name' => 'Batch.total', 'default_value' => NULL, 'column_number' => 7, 'entity_data' => []],
  ['name' => 'Batch.status_id', 'default_value' => NULL, 'column_number' => 8, 'entity_data' => []],
];

return [
  [
    // Label shown in "Saved Field Mapping" is this name from the _ onwards -
    // see CRM_CiviImport_Form_DataSource::addMappingSelector().
    'name' => 'import_Engage Summary',
    'entity' => 'UserJob',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'match' => ['name'],
      'values' => [
        'name' => 'import_Engage Summary',
        // Shared by all generic entity imports - see
        // Civi\Import\GenericParser::getUserJobInfo().
        'job_type' => 'import_generic',
        'is_template' => TRUE,
        'status_id' => 2,
        'metadata' => [
          // Pins the datasource (unlike most templates) since this mapping
          // only makes sense for the Engage Summary report's own columns.
          'submitted_values' => [
            'dataSource' => \Civi\Import\DataSource\EngageSummary::class,
            // Report dates are mm/dd/yyyy (e.g. 08/27/2026), which may not
            // match the site's own date preference.
            'dateFormats' => \CRM_Utils_Date::DATE_mm_dd_yyyy,
          ],
          // Explicit rather than url-derived, since this template is also
          // reached via a direct ?template_id= link (see
          // managed/Navigation_Import_Engage_Batch.mgd.php).
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
