<?php

namespace Civi\StandaloneMigrate\SourceUf;

class WordPress extends SourceUf {

  public function getRoles(): array {

    $wpRoles = \wp_roles()->roles;

    $roles = [];

    $capMap = self::getCapToPermissionMap();


    foreach ($wpRoles as $name => $details) {
      $capabilities = array_keys(array_filter($details['capabilities']));
      $permissions = [];

      // unfortunately we need to unmunge the wordpress capabilities
      foreach ($capabilities as $cap) {
        $permissions[] = $capMap[$cap] ?? $cap;
      }
      $roles[$name] = $permissions;
    }
    return $roles;
  }

  protected static function getCapToPermissionMap() {
    $permissions = array_keys(\CRM_Core_Permission::basicPermissions());

    $map = [];

    foreach ($permissions as $permission) {
      $cap = \CRM_Utils_String::munge(strtolower($permission));

      $map[$cap] = $permission;
    }

    return $map;

  }

  public function getUser(int $ufId): array {
    $user = get_userdata($ufId);

    $userData = [
      'email' => $user->data->user_email,
      'username' => $user->data->user_login,
      // NOTE: WordPress password hashes are not supported in Standalone yet, so user will end up having to
      // reset their password. but this preps for a time when they can be read
      'password' => $user->data->user_pass,
      // no such thing as disabled users in WP
      'is_active' => 1,
      // no such thing as user timezone in WP core
      'timezone' => '',
      // no such thing as user language in WP core
      'language' => '',
      'roles' => $user->roles,
    ];

    if ($user->has_cap('super admin') || $user->has_cap('administrator')) {
      $userData['roles'][] = 'superuser';
    }

    return $userData;
  }
}
