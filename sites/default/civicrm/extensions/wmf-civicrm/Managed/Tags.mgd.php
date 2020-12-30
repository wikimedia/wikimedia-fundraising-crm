<?php
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
  ];
