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
  'name' => 'imported_duplicates',
  'entity' => 'Group',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'match' => ['name'],
    'values' => [
      'name' => 'imported_duplicates',
      'title' => 'Duplicate from csv imports',
      'description' => 'This group should be processed through manual deduping',
      'group_type' => [],
      'source' => 'csv imports',
      'is_reserved' => TRUE,
    ],
  ],
]];

return $entities;
