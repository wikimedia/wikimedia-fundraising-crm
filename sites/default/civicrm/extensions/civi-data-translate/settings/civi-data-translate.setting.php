<?php
use CRM_CiviDataTranslate_ExtensionUtil as E;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 1/10/18
 * Time: 4:30 PM
 */
return [
  'civi-data-mailing-template-path' => [
    'group_name' => 'CiviData Settings',
    'group' => 'cdt',
    'name' => 'civi-data-mailing-template-path',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Path for disk-stored templates'),
    'help_text' => '',
    'html_type' => 'text',
    'html_attributes' => [
      'class' => 'crm-select2',
      'multiple' => 1,
    ],
    'settings_pages' => ['cividatatranslate' => ['weight' => 20]],
  ],
];
