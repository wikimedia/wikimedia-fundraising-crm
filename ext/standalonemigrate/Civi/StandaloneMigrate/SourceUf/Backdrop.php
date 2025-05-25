<?php

namespace Civi\StandaloneMigrate\SourceUf;

class Backdrop extends SourceUf {

  public function getRoles(): array {
    $roleObjects = user_roles(FALSE, NULL, TRUE);
    $roles = [];
    foreach ($roleObjects as $name => $role) {
      $roles[$name] = $role->permissions;
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
      // Give Backdrop super user the equivalent role.
      $userData['roles'][] = 'superuser';
    }

    return $userData;
  }
}
