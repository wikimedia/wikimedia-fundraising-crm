<?php

function wmf_eoy_receipt_drush_command() {
  $items = [
    'wmf-eoy-receipts' => [
      'description' => 'Run end-of-year receipts generation, and send letters.  This is equivalent to running the following tasks in succession: wmf-eoy-calculate-summaries, wmf-eoy-send-letters',
      'options' => [
        'contact_id' => 'ID of donor to receive receipt',
        'year' => 'Report year',
        'batch' => 'Batch size',
      ],
    ],
    'wmf-eoy-send-letters' => [
      'description' => 'Check job queue and send letters',
      'options' => [
        'job_id' => [
          'description' => 'Force reuse of an old job',
          'required' => TRUE,
        ],
        'batch' => 'Batch size, defaults to 100',
      ],
    ],
  ];

  return $items;
}

function wmf_eoy_receipt_drush_help($section) {
  switch ($section) {
    case 'drush:wmf-eoy-receipts':
      return dt('Run end-of-year receipts generation and send.');
  }
}

function drush_wmf_eoy_receipt_wmf_eoy_receipts() {
  $options = drush_get_merged_options();
  module_invoke('wmf_eoy_receipt', 'run', $options);
}


function drush_wmf_eoy_receipt_wmf_eoy_send_letters() {
  $options = drush_get_merged_options();
  module_invoke('wmf_eoy_receipt', 'send_letters', $options);
}
