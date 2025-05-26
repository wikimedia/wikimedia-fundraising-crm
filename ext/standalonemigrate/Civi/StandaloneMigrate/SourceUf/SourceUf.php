<?php

namespace Civi\StandaloneMigrate\SourceUf;

abstract class SourceUf {

  public static function get(?string $sourceDirectory): SourceUf {
    if ($sourceDirectory) {
      return new DumpedFiles($sourceDirectory);
    }
    switch (CIVICRM_UF) {
      case 'Backdrop':
        return new Backdrop();

      case 'Drupal':
      case 'Backdrop':
        return new Drupal7();

      case 'WordPress':
        return new WordPress();
    }

    throw new \CRM_Core_Exception("User migration not implemented for " . CIVICRM_UF);
  }

  /**
   * @return array with role names as keys, and an array of permission names as values.
   */
  abstract public function getRoles(): array;

  /**
   * @return string the name of the CMS role which translates to Standaloneusers "everyone" role
   */
  public function getEveryoneRoleName(): string {
    return 'everyone';
  }

  /**
   * Get user info matching a given user ID
   *
   * @return array user info with following keys:
   *   'email'
   *   'username'
   *   'password'
   *   'is_active'
   *   'timezone'
   *   'language'
   *   'roles' - array of name keys
   */
  abstract public function getUser(int $ufId): array;
}
