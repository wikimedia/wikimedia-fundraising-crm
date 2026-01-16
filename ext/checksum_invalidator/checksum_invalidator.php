<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'checksum_invalidator.civix.php';
// phpcs:enable

use CRM_ChecksumInvalidator_ExtensionUtil as E;
use Civi\Api4\InvalidChecksum;

/**
 * Implements hook_civicrm_invalidateChecksum()
 * to set as invalid any checksums in the invalidated table.
 */
function checksum_invalidator_civicrm_invalidateChecksum($contactID, $checksum, &$invalid) {
  $invalid = (bool) InvalidChecksum::get(FALSE)
    ->addWhere('contact_id', '=', $contactID)
    ->addWhere('checksum', '=', $checksum)
    ->execute()->count();
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function checksum_invalidator_civicrm_config(\CRM_Core_Config $config): void {
  _checksum_invalidator_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function checksum_invalidator_civicrm_install(): void {
  _checksum_invalidator_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function checksum_invalidator_civicrm_enable(): void {
  _checksum_invalidator_civix_civicrm_enable();
}
