<?php
use CRM_Assignee_ExtensionUtil as E;

return [
  'assignee_group' => [
    'group_name' => 'Assignee Settings',
    'group' => 'assignee',
    'name' => 'assignee_group',
    'type' => 'Integer',
    'title' => E::ts('Activity Assignee Group'),
    'description' => E::ts('Limit activity assignees to a specific Group?'),
    'help_text' => E::ts('When selecting assignees for an activity, limit the available individuals to those in the specified group'),
    'html_type' => 'select',
    'html_attributes' => ['options' => 'GROUPS'],
    'quick_form_type' => 'Element',
    'is_required' => FALSE,
    'pseudoconstant' => [
      'callback' => 'CRM_Core_PseudoConstant::allGroup',
    ],
    'settings_pages' => ['assigneesettings' => ['weight' => 20], 'search' => ['weight' => 20]],
  ],

  'assignee_as_source' => [
    'group_name' => 'Assignee Settings',
    'group' => 'assignee',
    'name' => 'assignee_as_source',
    'type' => 'Boolean',
    'default' => 0,
    'title' => E::ts('Activity Assignee default user'),
    'description' => E::ts('Set the Activity Assignee to the current user?'),
    'help_text' => E::ts('The assignees box is usually blank. By enabling this setting, the current user will be added automatically as an Assignee.  The user can remove this if desired.'),
    'html_type' => 'checkbox',
    'html_attributes' => '',
    'quick_form_type' => 'Element',
    'settings_pages' => ['assigneesettings' => ['weight' => 30], 'search' => ['weight' => 20]],
  ],
];
