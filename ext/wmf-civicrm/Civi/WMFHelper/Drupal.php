<?php

namespace Civi\WMFHelper;

use Civi;

/**
 * This class attempts to provide a single place for our interactions with drupal.
 *
 * Hopefully it will help to remove it.
 */
class Drupal {

  /**
   * Get the drupal transaction object
   *
   * @param string $database civicrm or drupal.
   *
   * @return \DatabaseTransaction
   * @throws \CRM_Core_Exception
   */
  public static function getTransaction(string $database): \DatabaseTransaction {
    if ($database === 'civicrm') {
      return db_transaction('wmf_civicrm', ['target' => 'civicrm']);
    }
    if ($database === 'drupal') {
      return db_transaction('wmf_default', ['target' => 'default']);
    }
    throw new \CRM_Core_Exception('unknown database');
  }

}
