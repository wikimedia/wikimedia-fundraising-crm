<?php

use Civi\SymfonyEmail\SymfonyMailProvider;

require_once 'symfony_email.civix.php';

use Symfony\Component\DependencyInjection\Definition;

use CRM_SymfonyEmail_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function symfony_email_civicrm_config(&$config): void {
  _symfony_email_civix_civicrm_config($config);
}

function symfony_email_civicrm_container($container) {
  $container->setDefinition('symfony_mail_factory', new Definition(SymfonyMailProvider::class, []))->setPublic(TRUE);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function symfony_email_civicrm_install(): void {
  _symfony_email_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function symfony_email_civicrm_enable(): void {
  _symfony_email_civix_civicrm_enable();
}
