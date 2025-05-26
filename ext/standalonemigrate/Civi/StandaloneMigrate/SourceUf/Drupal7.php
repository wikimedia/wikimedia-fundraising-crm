<?php

namespace Civi\StandaloneMigrate\SourceUf;

class Drupal7 extends SourceUf {

  public function getRoles(): array {
    $roleNamesById = user_roles();
    $permissionsById = user_role_permissions($roleNamesById);
    $roles = [];
    foreach ($roleNamesById as $id => $name) {
      $roles[$name] = array_keys($permissionsById[$id] ?? []);
    }

    return $roles;
  }

  public function getEveryoneRoleName(): string {
    return 'anonymous user';
  }

  public function getUser(int $ufId): array {
    $user = user_load($ufId);

    $userData = [
      'email' => $user->mail,
      'username' => $user->name,
      'password' => $user->pass,
      'is_active' => ($user->status === '1'),
      'timezone' => $user->timezone ?? '',
      'language' => $ufMatch['language'] ?? '',
      'roles' => $user->roles,
    ];

    if ($user->uid == 1) {
      // Give Drupal7 super user an equivalent role.
      $userData['roles'][] = 'superuser';
    }

    return $userData;
  }
}
