<?php

use Civi\Api4\OptionValue;
use Civi\Api4\Tag;

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
    'AddressTruncated' => [
      // Tag applied in wmf import code
      'name' => 'AddressTruncated',
      'entity' => 'Tag',
      'cleanup' => 'never',
      'params' => [
        'version' => 3,
        'name' => 'AddressTruncated',
        'description' => 'Tag applied to a contact when the address was truncated on import.',
        'is_selectable' => TRUE,
        'is_reserved' => TRUE,
        'used_for' => 'civicrm_contact',
      ],
    ],
    'NameTruncated' => [
      // Tag applied in wmf import code
      'name' => 'NameTruncated',
      'entity' => 'Tag',
      'cleanup' => 'never',
      'params' => [
        'version' => 3,
        'name' => 'NameTruncated',
        'description' => 'Tag applied to a contact when the name was truncated on import.',
        'is_selectable' => TRUE,
        'is_reserved' => TRUE,
        'used_for' => 'civicrm_contact',
      ],
    ],
  ];
