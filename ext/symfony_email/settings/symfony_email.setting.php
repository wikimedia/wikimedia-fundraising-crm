<?php

use CRM_SymfonyEmail_ExtensionUtil as E;

return [
  'symfony_email_dsn' => [
    'name' => 'symfony_email_dsn',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Symfony DSN'),
    'help_text' => 'see https://symfony.com/doc/current/mailer.html',
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 80,
    ],
  ],
];
