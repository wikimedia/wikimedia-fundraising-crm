<?php
// This is in the directory with the other managed files for visibility
// but the method used is different - instead of storing the values
// the alterSettingsMetaData hook
//https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
// is used to set the default value - which will apply unless something else has been
// actively defined.
return [
  // This prevents contacts being assigned English as a default
  // when the language is unknown.
  'contact_default_language' => 'undefined',
  // This is one we should consider removing. It was added as part of
  // T137496 to make the money format in the receipts generated from CiviCRM
  // look per MG preference. However, we don't really use that receipt now
  // as our thank yous are available as a button now and the concept of
  // moneyformat is up for deprecation in core as part of a switch to brick money.
  'moneyformat' => '%c%a',

  // We specify the tokens we want to have available to limit
  // processing to what is useful. These add nice formatted address block tokens.
  'civitoken_enabled_tokens' => [
    'address.address_block',
    'address.address_text',
    'address.conditional_country',
    'date.today_format_full',
    'date.today_format_raw',
  ],
];
