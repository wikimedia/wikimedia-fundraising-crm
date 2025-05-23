<?php
use CRM_Omnimail_ExtensionUtil as E;
return [
  'name' => 'OmnimailJobProgress',
  'table' => 'civicrm_omnimail_job_progress',
  'class' => 'CRM_Omnimail_DAO_OmnimailJobProgress',
  'getInfo' => fn() => [
    'title' => E::ts('Omnimail Job Progress'),
    'title_plural' => E::ts('Omnimail Job Progresses'),
    'description' => E::ts('FIXME'),
    'log' => FALSE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique OmnimailJobProgress ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'mailing_provider' => [
      'title' => E::ts('Mailing Provider'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'description' => E::ts('Mailing provider name'),
      'required' => TRUE,
    ],
    'job' => [
      'title' => E::ts('Job'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'description' => E::ts('job name'),
    ],
    'job_identifier' => [
      'title' => E::ts('Job Identifier'),
      'sql_type' => 'varchar(512)',
      'input_type' => 'Text',
      'description' => E::ts('optional suffix to disambiguate the job'),
    ],
    'last_timestamp' => [
      'title' => E::ts('Last Timestamp'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'description' => E::ts('Mailing provider name'),
    ],
    'progress_end_timestamp' => [
      'title' => E::ts('Progress End Timestamp'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'description' => E::ts('End timestamp of current retrieval'),
    ],
    'retrieval_parameters' => [
      'title' => E::ts('Retrieval Parameters'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('json copy of any paramters that need to be passed to the provider.'),
    ],
    'offset' => [
      'title' => E::ts('Offset'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'description' => E::ts('How many lines have been processed'),
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'description' => E::ts('When was the job created'),
      'default' => 'CURRENT_TIMESTAMP',
      'required' => TRUE,
    ],
  ],
];
