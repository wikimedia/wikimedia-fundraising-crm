<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('Offline Finance Batch'),
  'icon' => 'fa-scale-unbalanced-flip',
  'server_route' => 'civicrm/finance-batch',
  'create_submission' => TRUE,
  'requires' => ['wmfFinanceBatch'],
  'confirmation_type' => 'show_confirmation_message',
  'confirmation_message' => 'Batch created.<br/>
Name: [Batch1.0.name]<br/>
Settlement Date: [Batch1.0.batch_data.settlement_date]<br/>
Currency: [Batch1.0.batch_data.settlement_currency]<br/>
Donation Amount: [Batch1.0.batch_data.settled_donation_amount]<br/>
Net Amount: [Batch1.0.batch_data.settled_net_amount]<br/>
Fee Amount: [Batch1.0.batch_data.settled_fee_amount]',
];
