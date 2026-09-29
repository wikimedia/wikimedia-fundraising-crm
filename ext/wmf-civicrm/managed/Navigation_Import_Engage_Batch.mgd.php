<?php

use CRM_Wmf_ExtensionUtil as E;
use Civi\Api4\UserJob;

// Only register once the "Engage Summary" template (UserJobEngageSummary.mgd.php)
// exists - on a fresh install that may need a second reconciliation pass,
// since managed entities process in filename order and this sorts first.
$template = UserJob::get(FALSE)
  ->addWhere('name', '=', 'import_Engage Summary')
  ->addWhere('is_template', '=', TRUE)
  ->addWhere('job_type', '=', 'import_generic')
  ->execute()->first();

if (!$template) {
  return [];
}

return [
  [
    'name' => 'Navigation_Import_Engage_Batch',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Import Engage Batch'),
        'name' => 'Import Engage Batch',
        'url' => 'civicrm/import/batch?reset=1&template_id=' . $template['id'],
        'icon' => 'crm-i fa-file-excel',
        'permission' => [
          'access CiviContribute',
          'edit contributions',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'is_active' => TRUE,
        'weight' => 5,
        'has_separator' => NULL,
        'domain_id' => 'current_domain',
      ],
    ],
  ],
];
