<?php

/**
 * Add report for Address History tab.
 *
 * Bug: T142549
 */
return [
  [
    'name' => 'report_instance_address_history',
    'entity' => 'ReportInstance',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      // It would be better to match on name - but we didn't use one initially
      // (although the report will be updated with one as a result of migrating
      // to this managed approach so maybe later we can change it).
      // We don't have any other instances of this report_id & it's hard
      // to see that changing in future so should be enough.
      'match' => ['report_id'],
      'values' => [
        'title' => ts('Address History'),
        'report_id' => 'contact/addresshistory',
        'description' => 'ContactAddress History',
        'permission' => 'access CiviReport',
        'name' => 'address_history',
        'form_values' => serialize([
          'fields' => [
            'address_display_address' => 1,
            'log_date' => 1,
            'address_location_type_id' => 1,
            'address_is_primary' => 1,
            'log_conn_id' => 1,
            'log_user_id' => 1,
            'log_action' => 1,
          ],
          'contact_dashboard_tab' => ['contact_dashboard_tab' => '1'],
        ]),
      ],
    ],
  ],
];
