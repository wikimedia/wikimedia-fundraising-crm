<?php
// This is in the directory with the other managed files for visibility
// but the method used is different - instead of storing the values
// the alterSettingsMetaData hook
//https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
// is used to set the default value - which will apply unless something else has been
// actively defined.
use Civi\Api4\CustomField;

$settings = [
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

  // Configure deduper per our preferences
  'deduper_resolver_preferred_contact_resolution' => ['most_recent_contributor'],
  'deduper_resolver_preferred_contact_last_resort' => 'most_recently_created_contact',
  'deduper_resolver_field_prefer_preferred_contact' => ['contact_source'],

  // Enable smash pig queue.
  'smashpig_recurring_use_queue' => '1',
  'smashpig_recurring_charge_descriptor' => 'Wikimedia 877 600 9454'
];

// It's possible this is first run before the field is created so we check for the field before trying to add it.
// on live this has actually been set & is not relying on a default so this is really relevant for dev installs.
$optInField = CustomField::get(FALSE)->addWhere('name', '=', 'opt_in')->setSelect(['id'])->execute()->first();
if (!empty($optInField)) {
  $settings['deduper_resolver_field_prefer_preferred_contact'][] = 'custom_' . $optInField['id'];
}
return $settings;
