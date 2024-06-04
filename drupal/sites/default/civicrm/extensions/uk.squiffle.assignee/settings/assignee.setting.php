<?php
use CRM_Assignee_ExtensionUtil as E;

return [
  'assignee_group' => [
    'name' => 'assignee_group',
    'type' => 'Integer',
    'title' => E::ts('Activity Assignee group'),
    'description' => E::ts('Limit Assignees to members of this group.'),
    'help_text' => E::ts('When selecting assignees for an activity, limit the available individuals to those in the specified group'),  // not working
    'html_type' => 'select',
    'pseudoconstant' => ['callback' => 'CRM_Core_PseudoConstant::allGroup'],
    'is_required' => FALSE,    // Causes 'none' to be added to options
    'settings_pages' => ['assignee' => ['weight' => 20]],
  ],

  'assignee_as_source' => [
    'name' => 'assignee_as_source',
    'type' => 'Boolean',
#    'html_type' => 'yesno',
    'quick_form_type' => 'YesNo',
    'default' => 0,
    'title' => E::ts('Activity Assignee defaults to current user?'),
    'description' => E::ts('Add the current user as an Assignee.  Note: if a group is specified, the user will only be shown if in the group.'),
    'help_text' => E::ts('The assignees box is usually blank. By enabling this setting, the current user will be added automatically as an Assignee.  The user can remove this if desired.'),  // not working
    'settings_pages' => ['assignee' => ['weight' => 30]],
  ],

  'assignee_contacts' => [
    'name' => 'assignee_contacts',
    'type' => 'Integer',
    'default' => 0,
    'title' => E::ts('Activity Assignee default users'),
    'description' => E::ts('Add these contacts as Assignees. Note: if a group is specified, the users will only be shown if in the group.'),
    'help_text' => E::ts('The assignees box is usually blank. By enabling this setting, the listed contact will be added automatically as an Assignee.  The user can remove this if desired.'),  // not working
    'html_type' => 'entity_reference',
    'entity_reference_options' => ['multiple' => TRUE],
    'settings_pages' => ['assignee' => ['weight' => 40]],
  ],
];
