<?php

namespace Civi\AuthenticatedIframe;

/**
 * Reads and normalizes the authenticatediframe_routes setting.
 *
 * Only the value from $civicrm_setting in civicrm.settings.php
 * is used, so routes can't be changed via the API, UI or cv.
 */
class ConfiguredRoutes {

  /**
   * @return array<string, array{frame_ancestors: string|null, permissions: string|array|null, ttl_seconds: int}>
   *   Keyed by menu path. `frame_ancestors` and `permissions` are NULL if
   *   unset — both are required for AccessGuard to grant access.
   */
  public static function getAll(): array {
    $routes = \Civi::settings()->getMandatory('authenticatediframe_routes') ?? [];
    $defaultTtlSeconds = \Civi::settings()->get('standaloneusers_session_max_lifetime') * 60;
    $normalized = [];
    foreach ($routes as $path => $config) {
      $normalized[$path] = [
        'frame_ancestors' => $config['frame_ancestors'] ?? NULL,
        'permissions' => $config['permissions'] ?? NULL,
        'ttl_seconds' => $config['ttl_seconds'] ?? $defaultTtlSeconds,
      ];
    }
    return $normalized;
  }

  public static function get(string $path): ?array {
    return self::getAll()[$path] ?? NULL;
  }

}
