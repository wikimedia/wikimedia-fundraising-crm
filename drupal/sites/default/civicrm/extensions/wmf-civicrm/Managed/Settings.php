<?php
// This is in the directory with the other managed files for visibility
// but the method used is different - instead of storing the values
// the alterSettingsMetaData hook
//https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
// is used to set the default value - which will apply unless something else has been
// actively defined.
use Civi\Api4\CustomField;
use Civi\Api4\OptionValue;

$settings = [
  'civi-data-mailing-template-path' => 'sites/default/civicrm/extensions/wmf-civicrm/msg_templates/recurring_failed_message',
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

  // Per live, exclude supplemental_address_3, add postal_code_suffix
  'address_options' => CRM_Core_DAO::VALUE_SEPARATOR .
    implode(
      CRM_Core_DAO::VALUE_SEPARATOR,
      array_keys(
        (array) OptionValue::get(FALSE)
          ->addWhere('option_group_id.name', '=', 'address_options')
          ->addWhere('name', 'IN', [
            'street_address',
            'supplemental_address_1',
            'supplemental_address_2',
            'postal_code',
            'postal_code_suffix',
            'city',
            'country',
            'state_province',
            'geo_code_1',
            'geo_code_2',
          ])
          ->setSelect(['value'])
          ->execute()
          ->indexBy('value')
      )
    ) . CRM_Core_DAO::VALUE_SEPARATOR,

  // Configure deduper per our preferences
  'deduper_resolver_preferred_contact_resolution' => ['most_recent_contributor'],
  'deduper_resolver_preferred_contact_last_resort' => 'most_recently_created_contact',
  'deduper_resolver_field_prefer_preferred_contact' => ['contact_source', 'preferred_language'],
  'deduper_resolver_custom_groups_to_skip' => ['wmf_donor'],
  'deduper_resolver_email' => 'preferred_contact_with_re-assign',
  'deduper_resolver_phone' => 'preferred_contact',
  'deduper_resolver_address' => 'preferred_contact',

  // Enable smash pig queue.
  'smashpig_recurring_use_queue' => '1',
  'smashpig_recurring_charge_descriptor' => 'Wikimedia 877 600 9454'
];

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
