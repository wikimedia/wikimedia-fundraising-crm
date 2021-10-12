<?php

return [
  'Holds Donor Advised Fund of' => [
    'name' => 'Holds Donor Advised Fund of',
    'entity' => 'RelationshipType',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name_a_b' => 'Holds Donor Advised Fund of',
      'label_a_b' => 'Manages Donor Advised Fund',
      'name_b_a' => 'Has Donor Advised Fund at',
      'label_b_a' => 'Donor Advised Fund is managed by',
      'description' => 'Donor Advised Fund',
      'contact_type_a' => 'Organization',
      'contact_type_b' => 'Individual',
      'is_active' => 1,
    ],
  ],
  'Holds a Donor Advised Fund of' => [
    'name' => 'Holds a Donor Advised Fund of',
    'entity' => 'RelationshipType',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name_a_b' => 'Holds a Donor Advised Fund of',
      'label_a_b' => 'Is the Donor Advised Fund of',
      'name_b_a' => 'Has a Donor Advised Fund at',
      'label_b_a' => 'Owns the Donor Advised Fund',
      'description' => 'Donor Advised Fund',
      'contact_type_a' => 'Organization',
      'contact_type_b' => 'Individual',
      'is_active' => 1,
    ],
  ],
  'Donates via' => [
    'name' => 'Donates via',
    'entity' => 'RelationshipType',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name_a_b' => 'Donates via',
      'label_a_b' => 'Donates via',
      'name_b_a' => 'Donates via',
      'label_b_a' => 'Donates via',
      'is_active' => 1,
    ],
  ],
];
