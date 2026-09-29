<?php

use CRM_Wmf_ExtensionUtil as E;
use Civi\Api4\UserJob;

// Only register once the "Finance Spreadsheet" template
// (UserJobFinanceSpreadsheet.mgd.php) exists - on a fresh install that may
// need a second reconciliation pass, since managed entities process in
// filename order and this sorts first.
$template = UserJob::get(FALSE)
  ->addWhere('name', '=', 'import_Finance Spreadsheet')
  ->addWhere('is_template', '=', TRUE)
  ->addWhere('job_type', '=', 'import_generic')
  ->execute()->first();

if (!$template) {
  return [];
}

return [
  [
    'name' => 'Navigation_Import_Finance_Spreadsheet',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Import Finance Spreadsheet'),
        'name' => 'Import Finance Spreadsheet',
        'url' => 'civicrm/import/batch?reset=1&template_id=' . $template['id'],
        'icon' => 'crm-i fa-money-bill-transfer',
        'permission' => [
          'access CiviContribute',
          'edit contributions',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'is_active' => TRUE,
        'weight' => 6,
        'has_separator' => NULL,
        'domain_id' => 'current_domain',
      ],
    ],
  ],
];
