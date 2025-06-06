<?php
// This is in the directory with the other managed files for visibility
// but the method used is different - instead of storing the values
// the alterSettingsMetaData hook
//https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
// is used to set the default value - which will apply unless something else has been
// actively defined.
use Civi\Api4\CustomField;
use Civi\Api4\OptionValue;
// This file specifies the default values for settings. Actively
// setting them over-rides these. Note that there are ALSO
// 2 other places we specify settings
// - sites/default/wmf_settings_developer.json for development site
// only settings and
// - sites/default/wmf_settings.json for settings that 'do something'
// when enabled, ie enabling logging creates logging tables.

$settings = [
  'omnimail_field_mapping' => [
    'first_name' => 'firstname',
    'last_name' => 'lastname' ,
  ],
  // Prevents acl cache clearing (as of recording already set on prod/staging)
  'acl_cache_refresh_mode' => 'deterministic',

  // Enable message translation with locale parsing.
  'partial_locales' => 1,

  // Do we still want a white menu?.
  'menubar_color' => '#ffffff',
  'editor_id' => 'CKEditor5-base64',

  // We specify the tokens we want to have available to limit
  // processing to what is useful. These add nice formatted address block tokens.
  'civitoken_enabled_tokens' => [
    'address.address_block',
    'address.address_text',
    'address.conditional_country',
    'date.today_format_full',
    'date.today_format_raw',
  ],

  // Per live, exclude supplemental_address_3, add postal_code_suffix (11).
  // core default is   '1234568910'
  'address_options' => '123456891011',

  // Configure deduper per our preferences
  'deduper_resolver_preferred_contact_resolution' => ['most_recent_contributor'],
  'deduper_resolver_preferred_contact_last_resort' => 'most_recently_created_contact',
  'deduper_resolver_field_prefer_preferred_contact' => ['source', 'preferred_language'],
  'deduper_resolver_custom_groups_to_skip' => ['wmf_donor'],
  'deduper_resolver_email' => 'preferred_contact_with_re-assign',
  'deduper_resolver_phone' => 'preferred_contact',
  'deduper_resolver_address' => 'preferred_contact',
  'deduper_exception_relationship_type_id' => CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_relationship_type WHERE name_a_b = 'Unknown: Shares contact information'"),

  // Enable smash pig queue.
  'smashpig_recurring_use_queue' => '1',
  'smashpig_recurring_charge_descriptor' => 'Wikimedia 877 600 9454',
];

// @todo - it might be better to switch this to calling
// CRM_Core_DAO::executeQuery()->fetchArray();
// in order to avoid load order issues as this is called early-ish it startup.
$fieldsUsedInSettings = CustomField::get(FALSE)
  ->addWhere('name', 'IN', [
    'opt_in',
    'do_not_solicit',
    'Benefactor_Page_Last_Updated',
    'Listed_on_Benefactor_Page_as',
    'Endowment_Listing_Last_Updated',
    'Endowment_Site_Listed_as',
    'WLS_Listing_Last_Updated',
    'WLS_Listed_as',
  ])
  ->setSelect(['id', 'name'])
  ->execute()->indexBy('name');
// It's possible this is first run before the field is created so we check for the field before trying to add it.
// on live this has actually been set & is not relying on a default so this is really relevant for dev installs.
if (!empty($fieldsUsedInSettings['opt_in'])) {
  $settings['deduper_resolver_field_prefer_preferred_contact'][] = 'custom_' . $fieldsUsedInSettings['opt_in']['id'];
}
// It's possible this is first run before the field is created so we check for the field before trying to add it.
// on live this has actually been set & is not relying on a default so this is really relevant for dev installs.
if (!empty($fieldsUsedInSettings['do_not_solicit'])) {
  $settings['deduper_resolver_bool_prefer_yes'] =
    ['on_hold', 'do_not_email', 'do_not_phone', 'do_not_mail', 'do_not_sms', 'do_not_trade', 'is_opt_out',
   'custom_' . $fieldsUsedInSettings['do_not_solicit']['id']
  ];
}

$fieldPairs = [
  'Benefactor_Page_Last_Updated' => 'Listed_on_Benefactor_Page_as',
  'wls_listing_last_updated' => 'WLS_Listed_as',
  'endowment_listing_last_updated' => 'Endowment_Site_Listed_as',
];
foreach ($fieldPairs as $updateField => $triggerField) {
  if (!empty($fieldsUsedInSettings[$updateField])
    && !empty($fieldsUsedInSettings[$triggerField])) {
    $settings['custom_field_tracking'][$fieldsUsedInSettings[$triggerField]['id']] = $fieldsUsedInSettings[$updateField]['id'];
  }
}

return $settings;
