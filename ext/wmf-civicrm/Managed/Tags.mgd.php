<?php

use Civi\Api4\OptionValue;
use Civi\Api4\Tag;
use CRM_Wmf_ExtensionUtil as E;

// First ensure that the option values exist so that we can add tags
// against contributions and recurring contributions.
// The timing is a bit fiddly so doing this as a separate mgd file
// does not necessarily ensure it's created in time for these tags, hence here.
$usedForOptions = Tag::getFields(FALSE)
  ->setLoadOptions(TRUE)
  ->addSelect('options')
  ->addWhere('name', '=', 'used_for')
  ->execute()->first()['options'];

$requiredUsedForOptions = [
  'civicrm_contribution' => [
    'name' => 'Contributions',
    'label' => 'Contributions',
    'value' => 'civicrm_contribution',
    'option_group_id:name' => 'tag_used_for',
  ],
  'civicrm_contribution_recur' => [
    'name' => 'Recurring contributions',
    'label' => 'Recurring contributions',
    'value' => 'civicrm_contribution_recur',
    'option_group_id:name' => 'tag_used_for',
  ],
];

$missing = array_diff_key($requiredUsedForOptions, $usedForOptions);
if (!empty($missing)) {
  foreach ($missing as $optionValue) {
    OptionValue::create(FALSE)->setValues($optionValue)->execute();
  }
}
// Note that reserved tags cannot be edited by (most) users.
return [
  'RecurringRestarted' => [
    // Tag used by recurring global collect module
    'name' => 'RecurringRestarted',
    'entity' => 'Tag',
    'cleanup' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'RecurringRestarted',
      'description' => 'For the first contribution of a restarted recurring subscription.',
      'is_selectable' => FALSE,
      'is_reserved' => TRUE,
      'used_for' => 'civicrm_contribution',
    ],
  ],
  'RecurringRestartedUncharged' => [
    // Indicates that the subscription has been cured of some
    // malady, and that the next contribution record created
    // from it should get the RecurringRestarted tag so the donor is thanked
    // correctly. The recurring processor should then remove
    // this tag from the civicrm_contribution_recur table.
    'name' => 'RecurringRestartedUncharged',
    'entity' => 'Tag',
    'cleanup' => 'never',
    'params' => [
      'name' => 'RecurringRestartedUncharged',
      'description' => 'A subscription that has been restarted but not yet charged.',
      'is_selectable' => FALSE,
      'is_reserved' => TRUE,
      'used_for' => 'civicrm_contribution_recur',
    ],
  ],
  'UnrecordedCharge' => [
    'name' => 'UnrecordedCharge',
    'entity' => 'Tag',
    'cleanup' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'UnrecordedCharge',
      'description' => 'For donations which have already been charged, but were not recorded in Civi at the time.',
      'is_selectable' => FALSE,
      'is_reserved' => TRUE,
      'used_for' => 'civicrm_contribution',
    ],
  ],
  'DuplicateInvoiceId' => [
    // Used in modifyDuplicateInvoice function in wmf queue consumer.
    'name' => 'DuplicateInvoiceId',
    'entity' => 'Tag',
    'cleanup' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'DuplicateInvoiceId',
      'used_for' => 'civicrm_contribution',
      'description' => 'Used for contributions where the original assigned invoice id was a duplicate with another already in the database',
      'is_reserved' => 1,
      'selectable' => 0,
    ],
  ],

  [
    'name' => 'Tag_Preference_Spring_Campaign_Only',
    'entity' => 'Tag',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Preference_Spring_Campaign_Only',
        'label' => E::ts('Preference: exclude-from-spring-campaigns'),
        'description' => E::ts('Donor does not want to participate in the campaign '),
        'used_for' => [
          'civicrm_contact',
        ],
        'color' => '#1b4a50',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'Tag_Preference_End_Of_The_Year_Campaign_Only',
    'entity' => 'Tag',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Preference_End_Of_The_Year_Campaign_Only',
        'label' => E::ts('Preference: exclude-from-6C-annual-campaigns '),
        'description' => E::ts('Donor does not want to participate in the campaign '),
        'used_for' => [
          'civicrm_contact',
        ],
        'color' => '#153f66',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'Tag_Preference_Exclude_from_Direct_Mail_Campaigns',
    'entity' => 'Tag',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Preference_Exclude_from_Direct_Mail_Campaigns',
        'label' => E::ts('Preference: exclude-from-direct-mail-campaigns'),
        'description' => E::ts('Donor does not want to participate in Direct Mail Campaign'),
        'used_for' => [
          'civicrm_contact',
        ],
        'color' => '#367d9b',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'Tag_Preference_Exclude_from_SMS_campaigns',
    'entity' => 'Tag',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Preference_Exclude_from_SMS_campaigns',
        'label' => E::ts('Preference: exclude-from-sms-campaigns'),
        'description' => E::ts('Donor does not want to participate in the campaign'),
        'used_for' => [
          'civicrm_contact',
        ],
        'color' => '#227b81',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
