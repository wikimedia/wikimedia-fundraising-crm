<?php

// Create a new rule group that will catch a broad range of contacts (but should only be
// used against a narrow one for performance reasons.
// This is to help major gifts pull up a screen of possible matches rather than
// trawling for them.
//
// Struggling to think of a precise name / description so going for something
// people should remember. Am tempted to add a 'Go Fish' button to contact dash now.
// This rule is set up on staging and can be accessed from a contact record
// under the drop down actions. I tried it on a few fairly common names (John Smith)
// and there was some lag in those cases but not that bad & certainly better than doing searches.

// Only 5 rule-criteria can be configured in the UI but more will work if added.
// An email match alone is enough to hit the threshold.

// Last name is enough if the street address is the same OR the
// first name is the same too and either state or city is the same.
// There are some odd street address ones -

$entities = [[
  'name' => 'fishing_net',
  'entity' => 'DedupeRuleGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['name'],
    'values' => [
      'contact_type' => 'Individual',
      'threshold' => 150,
      'used' => 'General',
      'name' => 'fishing_net',
      'title' => 'Fishing Net',
      'is_reserved' => FALSE,
    ],
  ],
]];

$entities[] = [
  'name' => 'fishing_net_email',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'fishing_net',
      'rule_table' => 'civicrm_email',
      'rule_field' => 'email',
      'rule_weight' => 150,
    ],
  ],
];

$entities[] = [
  'name' => 'fishing_net_last_name',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'fishing_net',
      'rule_table' => 'civicrm_contact',
      'rule_field' => 'last_name',
      'rule_weight' => 120,
    ],
  ],
];


$entities[] = [
  'name' => 'fishing_net_first_name',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'fishing_net',
      'rule_table' => 'civicrm_contact',
      'rule_field' => 'first_name',
      'rule_weight' => 25,
    ],
  ],
];

$entities[] = [
  'name' => 'fishing_net_street_address',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'fishing_net',
      'rule_table' => 'civicrm_address',
      'rule_field' => 'street_address',
      'rule_weight' => 30,
    ],
  ],
];

$entities[] = [
  'name' => 'fishing_net_city',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'fishing_net',
      'rule_table' => 'civicrm_address',
      'rule_field' => 'city',
      'rule_weight' => 10,
    ],
  ],
];

$entities[] = [
  'name' => 'fishing_net_state_province_id',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'fishing_net',
      'rule_table' => 'civicrm_address',
      'rule_field' => 'state_province_id',
      'rule_weight' => 5,
    ],
  ],
];
$entities[] = [
  'name' => 'OrganizationNameAddress',
  'entity' => 'DedupeRuleGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['name'],
    'values' => [
      'contact_type' => 'Organization',
      'threshold' => 10,
      'used' => 'General',
      'name' => 'OrganizationNameAddress',
      'title' => 'Organization Name and Address',
      'is_reserved' => FALSE,
    ],
  ],
];
$entities[] = [
  'name' => 'OrganizationNameAddress_street_address',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'OrganizationNameAddress',
      'rule_table' => 'civicrm_address',
      'rule_field' => 'street_address',
      'rule_weight' => 5,
    ],
  ],
];
$entities[] = [
  'name' => 'OrganizationNameAddress_organization_name',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'OrganizationNameAddress',
      'rule_table' => 'civicrm_contact',
      'rule_field' => 'organization_name',
      'rule_weight' => 5,
    ],
  ],
];

$entities[] = [
  'name' => 'IndividualNameAddress',
  'entity' => 'DedupeRuleGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['name'],
    'values' => [
      'contact_type' => 'Individual',
      'threshold' => 15,
      'used' => 'General',
      'name' => 'IndividualNameAddress',
      'title' => 'Individual Name and Address',
      'is_reserved' => FALSE,
    ],
  ],
];
$entities[] = [
  'name' => 'IndividualNameAddress_street_address',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'IndividualNameAddress',
      'rule_table' => 'civicrm_address',
      'rule_field' => 'street_address',
      'rule_weight' => 5,
    ],
  ],
];
$entities[] = [
  'name' => 'IndividualNameAddress_last_name',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'IndividualNameAddress',
      'rule_table' => 'civicrm_contact',
      'rule_field' => 'last_name',
      'rule_weight' => 5,
    ],
  ],
];
$entities[] = [
  'name' => 'IndividualNameAddress_first_name',
  'entity' => 'DedupeRule',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['dedupe_rule_group_id', 'rule_table', 'rule_field'],
    'values' => [
      'dedupe_rule_group_id.name' => 'IndividualNameAddress',
      'rule_table' => 'civicrm_contact',
      'rule_field' => 'first_name',
      'rule_weight' => 5,
    ],
  ],
];
return $entities;
