<?php
use CRM_ExtendedMailingStats_ExtensionUtil as E;
return [
  'name' => 'MailingStats',
  'table' => 'civicrm_mailing_stats',
  'class' => 'CRM_ExtendedMailingStats_DAO_MailingStats',
  'getInfo' => fn() => [
    'title' => E::ts('Mailing Stats'),
    'title_plural' => E::ts('Mailing Statses'),
    'description' => E::ts('MailingStats class'),
    'log' => TRUE,
  ],
  'getIndices' => fn() => [
    'index_start' => [
      'fields' => [
        'start' => TRUE,
      ],
    ],
    'index_finish' => [
      'fields' => [
        'finish' => TRUE,
      ],
    ],
    'mailing_id' => [
      'fields' => [
        'mailing_id' => TRUE,
      ],
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'mailing_id' => [
      'title' => E::ts('Mailing ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Mailing ID'),
    ],
    'mailing_name' => [
      'title' => E::ts('Mailing Name'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Title of mailing'),
    ],
    'is_completed' => [
      'title' => E::ts('Is completed'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'default' => FALSE,
      'usage' => [
        'import',
        'export',
        'duplicate_matching',
      ],
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'default' => NULL,
      'input_attrs' => [
        'format_type' => 'activityDateTime',
      ],
    ],
    'start' => [
      'title' => E::ts('Start'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'default' => NULL,
      'input_attrs' => [
        'format_type' => 'activityDateTime',
      ],
    ],
    'finish' => [
      'title' => E::ts('Finish'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'default' => NULL,
      'input_attrs' => [
        'format_type' => 'activityDateTime',
      ],
    ],
    'recipients' => [
      'title' => E::ts('Number of Recipients'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'delivered' => [
      'title' => E::ts('Number of Successful Deliveries'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'send_rate' => [
      'title' => E::ts('Send Rate'),
      'sql_type' => 'double',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'bounced' => [
      'title' => E::ts('Number of Bounces'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'blocked' => [
      'title' => E::ts('Number of Blocked emails'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'description' => E::ts('Blocks represent administrative blocks such as blacklisting or lack of whitelisting by the email provider'),
      'default' => NULL,
    ],
    'suppressed' => [
      'title' => E::ts('Number of Suppressed emails'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'description' => E::ts('The number of deliveries suppressed by the external email provider (if one is used)'),
      'default' => NULL,
    ],
    'abuse_complaints' => [
      'title' => E::ts('Number of abuse or spam complaints from email'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'description' => E::ts('If using an external provider they may receive abuse complaints (e.g people marking mail as spam)'),
      'default' => NULL,
    ],
    'opened_total' => [
      'title' => E::ts('Total Number of Opens'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'opened_unique' => [
      'title' => E::ts('Opens by Unique Contacts'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'unsubscribed' => [
      'title' => E::ts('Number Unsubscribed'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'forwarded' => [
      'title' => E::ts('Number Forwarded'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'clicked_total' => [
      'title' => E::ts('Total clicks'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => 0,
    ],
    'clicked_unique' => [
      'title' => E::ts('Unique Contact clicks'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'trackable_urls' => [
      'title' => E::ts('Trackable urls'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'clicked_contribution_page' => [
      'title' => E::ts('Number of clicks on Contribute Page'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'contribution_count' => [
      'title' => E::ts('Number Of Related Contributions'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Text',
      'default' => NULL,
    ],
    'contribution_total' => [
      'title' => E::ts('Number Opened'),
      'sql_type' => 'double',
      'input_type' => 'Text',
      'default' => NULL,
    ],
  ],
];
